<?php

declare(strict_types=1);

namespace Vaulter\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Collaboration\CollaborationCall;
use Vaulter\Collaboration\CollaborationCallResolver;

/**
 * Guards the server-to-server collaboration webhook endpoints (collab/authenticate,
 * collab/store — M4b) presented by the Node Hocuspocus hub. It authenticates the call
 * via the shared webhook secret OR a valid signed room JWT through the
 * {@see CollaborationCallResolver}, stashes the resolved {@see CollaborationCall} on
 * the request, and 401s an unauthenticated caller. The endpoints are NEVER open — a
 * call with no valid secret and no valid JWT is rejected.
 *
 * The shared secret is read from the X-Vaulter-Collab-Secret header (kept out of the
 * body/logs); the JWT from the Authorization: Bearer header, falling back to a `token`
 * body field for hubs that cannot set headers.
 */
final class ResolveCollaborationCall
{
    /**
     * The request attribute the resolved CollaborationCall is stashed under.
     */
    public const string ATTRIBUTE = 'vaulter_collaboration_call';

    /**
     * The header the Node hub presents the shared webhook secret in.
     */
    public const string SECRET_HEADER = 'X-Vaulter-Collab-Secret';

    public function __construct(
        private readonly CollaborationCallResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $call = $this->resolver->resolve(
            presentedSecret: $this->secret($request),
            bearerToken: $this->bearer($request),
        );

        if (!$call instanceof CollaborationCall) {
            return new JsonResponse(
                ['message' => 'Unauthenticated collaboration call.'],
                401,
            );
        }

        $request->attributes->set(self::ATTRIBUTE, $call);

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    /**
     * The CollaborationCall this middleware guaranteed onto the request — the
     * controller always finds it once the request reaches it.
     */
    public static function call(Request $request): CollaborationCall
    {
        $call = $request->attributes->get(self::ATTRIBUTE);

        if (!$call instanceof CollaborationCall) {
            abort(500, 'Vaulter collaboration call missing — the route is not behind ResolveCollaborationCall.');
        }

        return $call;
    }

    private function secret(Request $request): ?string
    {
        $header = $request->header(self::SECRET_HEADER);

        return is_string($header) && $header !== '' ? $header : null;
    }

    /**
     * The presented JWT: the Authorization: Bearer header first (so the token stays
     * out of the body/logs), then a `token` body field as a fallback.
     */
    private function bearer(Request $request): ?string
    {
        $bearer = $request->bearerToken();

        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        $token = $request->input('token');

        return is_string($token) && $token !== '' ? $token : null;
    }
}
