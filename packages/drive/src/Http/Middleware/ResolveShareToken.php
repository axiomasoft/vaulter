<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Drive\Exceptions\Sharing\ShareNotFoundException;
use Vaulter\Drive\Exceptions\Sharing\SharePasswordRequiredException;
use Vaulter\Drive\Sharing\ShareContext;
use Vaulter\Drive\Sharing\ShareDownloadGrant;
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
 * The password is read from the X-Share-Password header or a POST body, never from
 * the query string, so it stays out of URLs, access logs and browser history. A plain
 * download link gets a short-lived grant from POST shares/{token}/unlock instead. Wrong
 * passwords are counted per link and client by the `vaulter-share-password` rate
 * limiter; past it every password attempt on that link answers 429, right or wrong.
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

    /** The named rate limiter that bounds wrong password attempts on one link. */
    public const string PASSWORD_LIMITER = 'vaulter-share-password';

    private const string DOWNLOAD_ROUTE = 'vaulter.v1.public-shares.download';

    public function __construct(
        private readonly ShareResolver $resolver,
        private readonly ShareRedeemer $redeemer,
        private readonly RateLimiter $limiter,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');

        if (!is_string($token) || $token === '') {
            $this->deny(ShareResolution::NotFound);
        }

        $password = $this->password($request);
        $attempts = $password === null ? null : $this->passwordLimit($request);
        if ($attempts !== null && $this->limiter->tooManyAttempts($attempts['key'], $attempts['max'])) {
            throw new ThrottleRequestsException('Too many share password attempts.', headers: [
                'Retry-After' => $this->limiter->availableIn($attempts['key']),
            ]);
        }

        $download = $request->route()?->getName() === self::DOWNLOAD_ROUTE;
        $result = $this->resolver->resolve(
            token: $token,
            actor: $this->actor($request),
            password: $password,
            session: $this->session($request),
            grant: $download ? $this->query($request, ShareDownloadGrant::PARAMETER) : null,
            resource: $download ? $this->query($request, 'node') : null,
        );

        if ($attempts !== null && $result->resolution === ShareResolution::PasswordRequired) {
            $this->limiter->hit($attempts['key'], $attempts['decay']);
        }

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
     * The supplied share password: the X-Share-Password header, or the `password` field
     * of a POST body. A `password` query parameter is ignored, as on every other method.
     */
    private function password(Request $request): ?string
    {
        $header = $request->header('X-Share-Password');

        if (is_string($header) && $header !== '') {
            return $header;
        }

        $body = $request->isMethod('POST') ? $request->post('password') : null;

        return is_string($body) && $body !== '' ? $body : null;
    }

    /**
     * The password attempt budget of this client on this link, keyed apart from every other limiter.
     *
     * @return array{key: string, max: int, decay: int}|null
     */
    private function passwordLimit(Request $request): ?array
    {
        $callback = $this->limiter->limiter(self::PASSWORD_LIMITER);
        $limit = $callback === null ? null : $callback($request);
        if (!$limit instanceof Limit) {
            return null;
        }
        $key = $limit->key;

        return [
            'key' => self::PASSWORD_LIMITER.':'.(is_string($key) || is_int($key) ? (string) $key : ''),
            'max' => $limit->maxAttempts,
            'decay' => $limit->decaySeconds,
        ];
    }

    private function query(Request $request, string $name): ?string
    {
        $value = $request->query($name);

        return is_string($value) && $value !== '' ? $value : null;
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
