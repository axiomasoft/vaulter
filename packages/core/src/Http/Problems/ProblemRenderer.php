<?php

declare(strict_types=1);

namespace Vaulter\Http\Problems;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;

/**
 * Answers every failure of a versioned route as RFC 9457 `application/problem+json`.
 * The body never carries an exception message: `detail` comes from lang and `meta`
 * passes an allowlist, so internals cannot leak to a client.
 */
final readonly class ProblemRenderer
{
    private const string ROUTE_PREFIX = 'vaulter.v1.';

    private const string API_VERSION = '1';

    /** Keys that must never reach a client, at any depth of `meta`. */
    private const array FORBIDDEN_META_KEYS = ['path', 'storage_key', 'tenant_id', 'token', 'sql', 'trace', 'file', 'line', 'password', 'secret'];

    /**
     * Headers a framework exception may hand on to the client: the retry and method
     * hints, and the tus protocol headers a refused tus request still has to carry
     * (the supported version on a 412, for instance).
     */
    private const array PASSTHROUGH_HEADERS = ['Retry-After', 'Allow', 'Tus-Resumable', 'Tus-Version'];

    public function __construct(
        private ProblemCatalog $catalog,
        private RoutesConfig $routes,
        private Translator $translator,
        private LoggerInterface $logger,
    ) {}

    /**
     * Whether the request belongs to the versioned API: a matched `vaulter.v1.*` route,
     * or a path under the versioned prefix (a 404/405 never matches a route). Host
     * routes and the unversioned routes are not the renderer's business.
     */
    public function handles(Request $request): bool
    {
        return self::isVersioned($request, $this->routes);
    }

    public static function isVersioned(Request $request, RoutesConfig $routes): bool
    {
        if (str_starts_with((string) $request->route()?->getName(), self::ROUTE_PREFIX)) {
            return true;
        }

        $prefix = $routes->versionedPrefix();

        return $request->is($prefix) || $request->is($prefix.'/*');
    }

    public function render(Throwable $e, Request $request): ?Response
    {
        // A prepared response (redirect, abort(response())) is the caller's own answer.
        if (!$this->handles($request) || $e instanceof HttpResponseException) {
            return null;
        }

        [$status, $code, $errors, $meta, $headers] = $this->classify($e);

        $requestId = $this->requestId($request);
        $detail = $this->catalog->detail($code, $meta);
        if ($e instanceof ProblemException) {
            // The field map of a domain error carries the exception message, which is
            // written for logs; the client gets the localized detail under the same keys.
            $errors = array_map(static fn (): array => $detail === null ? [] : [$detail], $errors);
        }
        $title = $this->catalog->title($code) ?? (Response::$statusTexts[$status] ?? 'Error');

        return new JsonResponse(
            [
                'type' => $this->catalog->type($code),
                'title' => $title,
                'status' => $status,
                'code' => $code,
                'detail' => $detail,
                'request_id' => $requestId,
                'errors' => $errors === [] ? null : $errors,
                'meta' => $meta === [] ? new \stdClass : $meta,
            ],
            $status,
            $headers + [
                'Content-Type' => 'application/problem+json',
                'Content-Language' => $this->translator->getLocale(),
                'Cache-Control' => 'no-store',
                'X-Vaulter-Api-Version' => self::API_VERSION,
                'X-Request-Id' => $requestId,
            ],
        );
    }

    /**
     * @return array{0: int, 1: string, 2: array<array-key, mixed>, 3: array<array-key, mixed>, 4: array<string, string>}
     */
    private function classify(Throwable $e): array
    {
        if ($e instanceof ProblemException) {
            $details = $e->problemDetails();
            $errors = [];
            foreach ($details as $key => $value) {
                if (strcasecmp((string) $key, 'errors') === 0) {
                    $errors = is_array($value) ? $value : [];
                    unset($details[$key]);
                }
            }

            return [$e->httpStatus(), $e->problemCode(), $errors, $this->redact($details, $e->problemCode(), $e->getMessage()), self::headersOf($e)];
        }

        if ($e instanceof ValidationException) {
            return [422, 'validation_failed', $e->errors(), [], []];
        }

        if ($e instanceof AuthenticationException) {
            return [401, 'actor_required', [], [], []];
        }

        if ($e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) {
            return [403, 'permission_denied', [], [], []];
        }

        // The framework wraps a Gate denial that carries a status into a plain HttpException.
        // A denial that hides the resource as 404 stays as indistinguishable as any unknown one.
        if ($e instanceof HttpExceptionInterface && $e->getPrevious() instanceof AuthorizationException) {
            return match ($e->getStatusCode()) {
                403 => [403, 'permission_denied', [], [], []],
                404 => [404, 'node_not_found', [], [], []],
                default => [$e->getStatusCode(), 'http_'.$e->getStatusCode(), [], [], []],
            };
        }

        if ($e instanceof ModelNotFoundException || ($e instanceof NotFoundHttpException && $e->getPrevious() instanceof ModelNotFoundException)) {
            return [404, 'node_not_found', [], [], []];
        }

        if ($e instanceof ThrottleRequestsException) {
            return [429, 'rate_limited', [], [], $this->passthrough($e)];
        }

        if ($e instanceof NotFoundHttpException) {
            return [404, 'route_not_found', [], [], []];
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return [405, 'method_not_allowed', [], [], $this->passthrough($e)];
        }

        if ($e instanceof PostTooLargeException) {
            return [413, 'payload_too_large', [], [], []];
        }

        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() >= 400) {
            return [$e->getStatusCode(), 'http_'.$e->getStatusCode(), [], [], $this->passthrough($e)];
        }

        return [500, 'internal_error', [], [], []];
    }

    /**
     * @param  array<array-key, mixed> $meta
     * @return array<array-key, mixed>
     */
    private function redact(array $meta, string $code, string $message): array
    {
        // Objects are not walked by the allowlist; the JSON form is what the client would get.
        $normalized = json_decode((string) json_encode($meta, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
        $removed = [];
        $clean = $this->strip(is_array($normalized) ? $normalized : [], '', $message, $removed);
        if ($removed !== []) {
            $this->logger->warning('Vaulter problem meta carried forbidden keys or the exception message; they were dropped.', ['code' => $code, 'keys' => $removed]);
        }

        return $clean;
    }

    /**
     * @param  array<array-key, mixed> $value
     * @param  list<string>            $removed
     * @return array<array-key, mixed>
     */
    private function strip(array $value, string $path, string $message, array &$removed): array
    {
        $clean = [];
        foreach ($value as $key => $item) {
            $here = $path === '' ? (string) $key : $path.'.'.$key;
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_META_KEYS, true)) {
                $removed[] = $here;

                continue;
            }
            // The message is written for logs; a leaf that carries it never reaches a client.
            if (is_string($item) && $message !== '' && str_contains($item, $message)) {
                $removed[] = $here;

                continue;
            }
            $clean[$key] = is_array($item) ? $this->strip($item, $here, $message, $removed) : $item;
        }

        return $clean;
    }

    /**
     * @return array<string, string>
     */
    private function passthrough(HttpExceptionInterface $e): array
    {
        $headers = [];
        foreach ($e->getHeaders() as $name => $value) {
            foreach (self::PASSTHROUGH_HEADERS as $allowed) {
                if (strcasecmp((string) $name, $allowed) === 0 && is_scalar($value)) {
                    $headers[$allowed] = (string) $value;
                }
            }
        }

        return $headers;
    }

    /**
     * The headers an exception asks for (`problemHeaders()` of {@see ProvidesProblemDetails}),
     * kept to string pairs.
     *
     * @return array<string, string>
     */
    public static function headersOf(Throwable $e): array
    {
        $headers = method_exists($e, 'problemHeaders') ? $e->problemHeaders() : [];
        $strings = [];
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (is_string($name) && is_scalar($value)) {
                    $strings[$name] = (string) $value;
                }
            }
        }

        return $strings;
    }

    private function requestId(Request $request): string
    {
        $assigned = $request->attributes->get('vaulter.request_id');
        if (is_string($assigned) && $assigned !== '') {
            return $assigned;
        }

        $incoming = $request->headers->get('X-Request-Id');
        if (is_string($incoming) && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $incoming) === 1) {
            return $incoming;
        }

        return (string) Str::ulid();
    }
}
