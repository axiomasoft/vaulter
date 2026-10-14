<?php

declare(strict_types=1);

namespace Vaulter\Idempotency;

use Closure;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use LogicException;
use stdClass;
use Throwable;
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
 *
 * @internal
 */
final readonly class IdempotencyClaim implements OperationClaim
{
    /** The receipt kind of a stored refusal. */
    public const string REFUSAL = 'vaulter.problem';

    public const int DEFAULT_TTL_SECONDS = 86400;

    private const array TUPLE = ['tenant_kind', 'tenant_id', 'scope_kind', 'scope_id', 'actor_kind', 'actor_id', 'operation'];

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
        $requestHash = IdempotencyDigest::request($fingerprint === null ? $idempotency->fingerprint : ['transport' => $fingerprint]);

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
    private function replay(Operation $operation, IdempotentWrite $idempotency, stdClass $receipt, array $tuple, string $requestHash): mixed
    {
        // The digest is the same; a different stored tuple is a hash collision, and is refused like a reused key.
        foreach (self::TUPLE as $field) {
            $stored = $receipt->{$field};
            if (($stored === null ? null : (string) $stored) !== $tuple[$field]) {
                throw IdempotencyKeyReusedException::forOperation($operation->spec->name);
            }
        }
        if (base64_decode((string) $receipt->idempotency_key, true) !== $tuple['idempotency_key']
            || !hash_equals((string) $receipt->request_hash, $requestHash)) {
            throw IdempotencyKeyReusedException::forOperation($operation->spec->name);
        }

        $idempotency->authorizer->authorize(
            self::ref($receipt->resource_kind, $receipt->resource_id),
            self::ref($receipt->auth_scope_kind, $receipt->auth_scope_id),
        );

        $body = json_decode((string) $receipt->receipt_body, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($body)) {
            throw new LogicException('[Vaulter] An idempotency receipt has no body.');
        }
        $this->outcome->markReplayed();

        if ($receipt->receipt_kind === self::REFUSAL) {
            $headers = $receipt->receipt_headers === null ? [] : json_decode((string) $receipt->receipt_headers, true, flags: JSON_THROW_ON_ERROR);

            return new CachedRefusal(new ReplayedProblemException(
                (string) $body['code'],
                (int) $body['status'],
                is_array($body['details'] ?? null) ? $body['details'] : [],
                is_array($headers) ? array_map(strval(...), $headers) : [],
            ));
        }
        if ($receipt->receipt_kind !== $idempotency->codec->kind()) {
            throw new LogicException(sprintf('[Vaulter] The idempotency receipt of "%s" was written by the codec "%s", not "%s".', $operation->spec->name, (string) $receipt->receipt_kind, $idempotency->codec->kind()));
        }

        return $idempotency->codec->decode($body, (int) $receipt->codec_version);
    }

    private function ttl(): int
    {
        $ttl = $this->config->get('vaulter.idempotency.ttl_seconds', self::DEFAULT_TTL_SECONDS);

        return is_int($ttl) && $ttl > 0 ? $ttl : self::DEFAULT_TTL_SECONDS;
    }

    private static function ref(mixed $kind, mixed $id): ?ResourceRef
    {
        return is_string($kind) && $kind !== '' && $id !== null && (string) $id !== '' ? new ResourceRef($kind, (string) $id) : null;
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
