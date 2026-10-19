<?php

declare(strict_types=1);

namespace Vaulter\Idempotency;

use Closure;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use LogicException;
use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\Idempotency\IdempotencyKeyReusedException;
use Vaulter\Http\Problems\ProblemCatalog;
use Vaulter\Http\Problems\ProblemRenderer;
use Vaulter\Operations\Operation;
use Vaulter\Operations\OperationClaim;

/**
 * Stage 4 of the operation pipeline (D25, ADR-0029), inside the root transaction of a keyed write:
 *
 * - claim the scope digest first (an uncommitted row; a concurrent request waits on it, at most two seconds);
 * - a committed receipt with another request hash or another stored tuple → `idempotency_key_reused`;
 * - a committed receipt → replay: the owner's {@see ReplayAuthorizer} checks the current right first, then the
 *   stored result is decoded (no mutation, no events); a denial answers like a new request and keeps the receipt;
 * - otherwise run the attempt under a savepoint: a result is encoded into the receipt in the same transaction; a
 *   refusal whose code is `receipt: cacheable` rolls back to the savepoint (the mutation and its event sinks) and
 *   is stored instead; any other failure rolls back everything, the claim included.
 */
#[Internal]
final readonly class IdempotencyClaim implements OperationClaim
{
    /** The receipt kind of a stored refusal. */
    public const string REFUSAL = 'vaulter.problem';

    public const int DEFAULT_TTL_SECONDS = 86400;

    public function __construct(
        private IdempotencyStore $store,
        private VaulterDatabase $database,
        private ProblemCatalog $problems,
        private IdempotencyOutcome $outcome,
        private Repository $config,
    ) {}

    public function admit(Operation $operation, ?IdempotentWrite $idempotency): void
    {
        if ($operation->context->idempotencyKey !== null && $idempotency === null) {
            throw new InvalidArgumentException(sprintf('[Vaulter] The operation "%s" does not accept an idempotency key.', $operation->spec->name));
        }
    }

    public function around(Operation $operation, ?IdempotentWrite $idempotency, Closure $attempt): mixed
    {
        $key = $operation->context->idempotencyKey;
        if ($key === null || $idempotency === null) {
            return $attempt();
        }

        $tuple = IdempotencyDigest::tuple($operation->context, $operation->spec->name, $key);
        $digest = IdempotencyDigest::scope($tuple);
        // A transport that fingerprints its own request (HTTP) replaces the operation's fingerprint.
        $fingerprint = $operation->context->requestFingerprint;
        $requestHash = $fingerprint === null ? IdempotencyDigest::request($idempotency->fingerprint) : IdempotencyDigest::transport($fingerprint);

        $receipt = $this->store->claim($tuple, $digest, $requestHash, $this->ttl());
        if ($receipt !== null) {
            return $this->replay($operation, $idempotency, $receipt, $tuple, $requestHash);
        }

        try {
            // A nested transaction is a savepoint: its rollback undoes the mutation, the event sinks and the
            // after-commit callbacks of the attempt, and leaves the claim row of this transaction in place.
            $result = $this->database->transaction(static fn (): mixed => $attempt(), attempts: 1);
        } catch (Throwable $refusal) {
            if (!$refusal instanceof ProblemException || !$this->problems->isCacheable($refusal->problemCode())) {
                throw $refusal;
            }
            $headers = ProblemRenderer::headersOf($refusal);
            $this->store->complete(
                digest: $digest,
                kind: self::REFUSAL,
                version: 1,
                body: ['code' => $refusal->problemCode(), 'status' => $refusal->httpStatus(), 'details' => self::plain($refusal->problemDetails())],
                httpCode: $refusal->httpStatus(),
                headers: $headers === [] ? null : $headers,
                resource: null,
                scope: $idempotency->authorizer->scope(null),
            );

            return new CachedRefusal($refusal);
        }

        $codec = $idempotency->codec;
        $this->store->complete(
            digest: $digest,
            kind: $codec->kind(),
            version: $codec->version(),
            body: $codec->encode($result),
            httpCode: null,
            headers: null,
            resource: $codec->resource($result),
            scope: $idempotency->authorizer->scope($result),
        );

        return $result;
    }

    /**
     * @param array<string, string|null> $tuple
     */
    private function replay(Operation $operation, IdempotentWrite $idempotency, StoredReceipt $receipt, array $tuple, string $requestHash): mixed
    {
        // The digest is the same; a different stored tuple is a hash collision, and is refused like a reused key.
        foreach (StoredReceipt::TUPLE as $field) {
            if ($receipt->tuple[$field] !== $tuple[$field]) {
                throw IdempotencyKeyReusedException::forOperation($operation->spec->name);
            }
        }
        if (base64_decode($receipt->idempotencyKey, true) !== $tuple['idempotency_key']
            || !hash_equals($receipt->requestHash, $requestHash)) {
            throw IdempotencyKeyReusedException::forOperation($operation->spec->name);
        }

        $idempotency->authorizer->authorize($receipt->resource, $receipt->scope);

        $body = self::decoded($receipt->body);
        $this->outcome->markReplayed();

        if ($receipt->kind === self::REFUSAL) {
            $headers = $receipt->headers === null ? [] : self::decoded($receipt->headers);
            $details = $body['details'] ?? [];

            return new CachedRefusal(new ReplayedProblemException(
                self::text($body['code'] ?? null),
                is_int($body['status'] ?? null) ? $body['status'] : 500,
                is_array($details) ? self::keyed($details) : [],
                array_map(self::text(...), $headers),
            ));
        }
        if ($receipt->kind !== $idempotency->codec->kind()) {
            throw new LogicException(sprintf('[Vaulter] The idempotency receipt of "%s" was written by the codec "%s", not "%s".', $operation->spec->name, $receipt->kind, $idempotency->codec->kind()));
        }

        return $idempotency->codec->decode($body, $receipt->codecVersion);
    }

    private function ttl(): int
    {
        $ttl = $this->config->get('vaulter.idempotency.ttl_seconds', self::DEFAULT_TTL_SECONDS);

        return is_int($ttl) && $ttl > 0 ? $ttl : self::DEFAULT_TTL_SECONDS;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decoded(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new LogicException('[Vaulter] An idempotency receipt has no body.');
        }

        return self::keyed($decoded);
    }

    /**
     * @param  array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private static function keyed(array $values): array
    {
        return array_filter($values, is_string(...), ARRAY_FILTER_USE_KEY);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The JSON form of problem details, as a client would receive them before redaction.
     *
     * @param  array<array-key, mixed> $details
     * @return array<array-key, mixed>
     */
    private static function plain(array $details): array
    {
        $plain = json_decode((string) json_encode($details, JSON_PARTIAL_OUTPUT_ON_ERROR), true);

        return is_array($plain) ? $plain : [];
    }
}
