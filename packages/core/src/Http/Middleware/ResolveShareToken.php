<?php

declare(strict_types=1);

namespace Vaulter\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Exceptions\Sharing\ShareNotFoundException;
use Vaulter\Drive\Exceptions\Sharing\SharePasswordRequiredException;
use Vaulter\Drive\Sharing\ShareContext;
use Vaulter\Drive\Sharing\ShareRedeemer;
use Vaulter\Drive\Sharing\ShareResolution;
use Vaulter\Drive\Sharing\ShareResolver;
use Vaulter\Drive\Sharing\ShareSession;
use Vaulter\Exceptions\Access\MissingActorException;

/**
 * Resolves the {token} route parameter of a PUBLIC share endpoint into a
 * {@see ShareContext} and stashes it on the request. This is the
 * SEPARATE sharing pass — it never touches the identity ACL / AccessResolver; a
 * share grants exactly its role's abilities on its node-set.
 *
 * It carries NO auth middleware of its own (the routes are public). When the
 * share's audience needs a signed-in visitor it reads whatever optional host user
 * is already authenticated; a missing one yields 401. A missing/wrong/expired/
 * revoked/over-budget token, a visitor outside the audience's tenant, or a share
 * whose target failed item-safety, yields an opaque 404 — a password-gated share
 * without a valid password yields 401 with a password challenge.
 *
 * A visit that passes is REDEEMED once: the use is counted and the
 * response carries a link session in the X-Share-Session header. Requests that send
 * that header back belong to the same visit and are not counted again, so opening a
 * preview, paging a listing and fetching ranges cost one use.
 *
 * Downstream controllers read the context via {@see self::context()}.
 */
#[Internal]
final class ResolveShareToken
{
    /**
     * The request attribute the resolved ShareContext is stashed under.
     */
    public const string ATTRIBUTE = 'vaulter_share_context';

    public function __construct(
        private readonly ShareResolver $resolver,
        private readonly ShareRedeemer $redeemer,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');

        if (!is_string($token) || $token === '') {
            $this->deny(ShareResolution::NotFound);
        }

        $result = $this->resolver->resolve(
            token: $token,
            actor: $this->actor($request),
            password: $this->password($request),
            session: $this->session($request),
        );

        if (!$result->isOk() || !$result->context instanceof ShareContext) {
            $this->deny($result->resolution);
        }

        $session = null;
        if (!$result->inSession) {
            // The atomic UPDATE refuses a link that was revoked, rotated or spent
            // since it was resolved: the visitor sees the same opaque 404.
            if (!$this->redeemer->redeem($result->context->share)) {
                $this->deny(ShareResolution::NotFound);
            }
            $session = $this->redeemer->session($result->context->share);
        }

        $request->attributes->set(self::ATTRIBUTE, $result->context);

        /** @var Response $response */
        $response = $next($request);
        if ($session !== null) {
            $response->headers->set(ShareSession::HEADER, $session);
        }

        return $response;
    }

    /**
     * The ShareContext a downstream controller relies on — resolved by this
     * middleware, so it is always present once the request reaches the controller.
     */
    public static function context(Request $request): ShareContext
    {
        $context = $request->attributes->get(self::ATTRIBUTE);

        if (!$context instanceof ShareContext) {
            // The middleware guarantees this; reaching here means the route was
            // misconfigured (controller behind no ResolveShareToken).
            abort(500, 'Vaulter share context missing — the route is not behind ResolveShareToken.');
        }

        return $context;
    }

    /**
     * The supplied share password: header X-Share-Password first (so the secret
     * stays out of the URL/logs), then a `password` query/body param as a fallback.
     */
    private function password(Request $request): ?string
    {
        $header = $request->header('X-Share-Password');

        if (is_string($header) && $header !== '') {
            return $header;
        }

        $input = $request->input('password');

        return is_string($input) && $input !== '' ? $input : null;
    }

    private function session(Request $request): ?string
    {
        $header = $request->header(ShareSession::HEADER);

        return is_string($header) && $header !== '' ? $header : null;
    }

    private function actor(Request $request): ?Authenticatable
    {
        return $request->user();
    }

    private function deny(ShareResolution $resolution): never
    {
        throw match ($resolution) {
            ShareResolution::PasswordRequired => SharePasswordRequiredException::challenge(),
            ShareResolution::LoginRequired => MissingActorException::required(),
            default => ShareNotFoundException::opaque(),
        };
    }
}
