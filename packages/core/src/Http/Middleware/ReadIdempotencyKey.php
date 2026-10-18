<?php

declare(strict_types=1);

namespace Vaulter\Http\Middleware;

use Closure;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Attributes\Internal;
use Vaulter\Idempotency\IdempotencyDigest;
use Vaulter\Idempotency\IdempotencyOutcome;

/**
 * Reads `Idempotency-Key` on the routes of operations that declare idempotency (D25, ADR-0029; alias
 * `vaulter.idempotency`). The value is one Structured Field String (`"…"` with `\"` and `\\` escapes) or one
 * plain token without whitespace, controls or commas; it is decoded once and never trimmed or lowercased. Several
 * headers, a list or anything malformed is refused before the operation runs. The request is fingerprinted here
 * (method, route, route parameters, query, body, If-Match, X-Vaulter-Destination-If-Match) so a retry is recognized
 * even after its precondition went stale. The fingerprint is an HMAC under a key derived from the application key:
 * a body may carry a secret (a share password, a webhook secret), and the stored request hash must not let whoever
 * reads the receipts table test guesses of it without that key. A response answered from a receipt carries
 * `Idempotent-Replayed: true`.
 *
 * The IETF idempotency-key draft expired unpublished; this follows its shape without claiming conformance.
 */
#[Internal]
final readonly class ReadIdempotencyKey
{
    public const string ALIAS = 'vaulter.idempotency';

    public const string HEADER = 'Idempotency-Key';

    public const string REPLAYED_HEADER = 'Idempotent-Replayed';

    private const string KEY_ATTRIBUTE = 'vaulter.idempotency_key';

    private const string FINGERPRINT_ATTRIBUTE = 'vaulter.idempotency_fingerprint';

    /** The optional precondition on a second resource of the request (the destination folder of a move). */
    private const string DESTINATION_IF_MATCH = 'X-Vaulter-Destination-If-Match';

    public function __construct(private IdempotencyOutcome $outcome, private Encrypter $encrypter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $values = $request->headers->all(strtolower(self::HEADER));
        if ($values !== []) {
            $request->attributes->set(self::KEY_ATTRIBUTE, self::parse($values));
            $request->attributes->set(self::FINGERPRINT_ATTRIBUTE, $this->fingerprint($request));
        }

        /** @var Response $response */
        $response = $next($request);
        if ($values !== [] && $this->outcome->replayed()) {
            $response->headers->set(self::REPLAYED_HEADER, 'true');
        }

        return $response;
    }

    /** The decoded key of the request, or null when it carries none (or the route does not read it). */
    public static function key(Request $request): ?string
    {
        $key = $request->attributes->get(self::KEY_ATTRIBUTE);

        return is_string($key) ? $key : null;
    }

    public static function requestFingerprint(Request $request): ?string
    {
        $fingerprint = $request->attributes->get(self::FINGERPRINT_ATTRIBUTE);

        return is_string($fingerprint) ? $fingerprint : null;
    }

    /**
     * @param list<string|null> $values
     *
     * @throws ValidationException
     */
    public static function parse(array $values): string
    {
        if (count($values) !== 1 || !is_string($values[0])) {
            throw self::invalid('Send exactly one Idempotency-Key header.');
        }
        // Optional whitespace around a field value is not part of it (RFC 9110 §5.5).
        $value = trim($values[0], " \t");
        $key = str_starts_with($value, '"') ? self::sfString($value) : self::token($value);
        if ($key === null || $key === '' || strlen($key) > 255) {
            throw self::invalid('The Idempotency-Key must be one string of 1 to 255 bytes: a quoted Structured Field String or a token without spaces, controls or commas.');
        }

        return $key;
    }

    /** A Structured Field String (RFC 8941 §3.3.3) and nothing after it; null when malformed. */
    private static function sfString(string $value): ?string
    {
        $decoded = '';
        $length = strlen($value);
        for ($i = 1; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '\\') {
                $escaped = $value[++$i] ?? '';
                if ($escaped !== '"' && $escaped !== '\\') {
                    return null;
                }
                $decoded .= $escaped;

                continue;
            }
            if ($char === '"') {
                // The closing quote must end the value: a second member would make it a list.
                return $i === $length - 1 ? $decoded : null;
            }
            $ord = ord($char);
            if ($ord < 0x20 || $ord > 0x7E) {
                return null;
            }
            $decoded .= $char;
        }

        return null;
    }

    /** A plain value: visible ASCII without commas (a comma would make it a list); null otherwise. */
    private static function token(string $value): ?string
    {
        return preg_match('/^[\x21-\x2B\x2D-\x7E]+$/', $value) === 1 ? $value : null;
    }

    private function fingerprint(Request $request): string
    {
        $route = $request->route();
        $parameters = [];
        if ($route instanceof Route) {
            foreach ($route->parameters() as $name => $value) {
                $parameters[$name] = is_scalar($value) ? (string) $value : get_debug_type($value);
            }
        }

        $canonical = IdempotencyDigest::canonicalJson([
            'method' => $request->getMethod(),
            'route' => $route instanceof Route ? (string) $route->getName() : $request->path(),
            'parameters' => $parameters,
            'query' => $request->query->all(),
            'body' => $request->isJson() ? $request->json()->all() : $request->request->all(),
            'if_match' => $request->headers->get('If-Match'),
            'destination_if_match' => $request->headers->get(self::DESTINATION_IF_MATCH),
        ]);

        return hash_hmac('sha256', $canonical, hash_hmac('sha256', 'vaulter.idempotency-request', $this->encrypter->getKey(), true));
    }

    private static function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['header:'.self::HEADER => [$message]]);
    }
}
