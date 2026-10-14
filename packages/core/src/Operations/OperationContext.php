<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Vaulter\ValueObjects\Access\Actor;
use Vaulter\ValueObjects\Operations\ScopeRef;
use Vaulter\ValueObjects\Operations\TenantRef;

/**
 * Who runs an operation, for which tenant, within which scope and under which
 * correlation. Neutral: it references the scope instead of holding a domain model,
 * so the kernel's operation pipeline needs no module's ORM. Drive paths receive a
 * DriveContext, which pairs this context with the bound drive.
 */
final readonly class OperationContext
{
    /** The reason a system actor runs this operation; null for any other actor. */
    public ?string $systemReason;

    public function __construct(
        public Actor $actor,
        public ScopeRef $scope,
        public TenantRef $tenant,
        public string $correlationId,
        public ?string $causationId = null,
        public ?string $idempotencyKey = null,
        public ?string $requestFingerprint = null,
    ) {
        self::assertTraceId($correlationId, 'Correlation ID');
        if ($causationId !== null) {
            self::assertTraceId($causationId, 'Causation ID');
        }
        if ($idempotencyKey !== null && ($idempotencyKey === '' || strlen($idempotencyKey) > 255)) {
            throw new InvalidArgumentException('[Vaulter] An idempotency key must be between 1 and 255 bytes.');
        }
        if ($requestFingerprint !== null && ($idempotencyKey === null || $requestFingerprint === '')) {
            throw new InvalidArgumentException('[Vaulter] A request fingerprint travels only with an idempotency key.');
        }
        $this->systemReason = $actor->isSystem() ? $actor->ref()->reason : null;
    }

    public static function for(
        Actor $actor,
        ScopeRef $scope,
        TenantRef $tenant,
        string $correlationId,
        ?string $causationId = null,
        ?string $idempotencyKey = null,
        ?string $requestFingerprint = null,
    ): self {
        return new self(
            actor: $actor,
            scope: $scope,
            tenant: $tenant,
            correlationId: $correlationId,
            causationId: $causationId,
            idempotencyKey: $idempotencyKey,
            requestFingerprint: $requestFingerprint,
        );
    }

    public static function system(ScopeRef $scope, TenantRef $tenant, string $reason, ?string $correlationId = null): self
    {
        return new self(actor: Actor::system($reason), scope: $scope, tenant: $tenant, correlationId: $correlationId ?? (string) Str::ulid());
    }

    /**
     * The key arrives already decoded (opaque bytes, 1–255, compared exactly: never trimmed or lowercased).
     * A transport that fingerprints its own request (the HTTP middleware) passes that digest; it then
     * replaces the operation's own fingerprint in the request hash (D25).
     */
    public function withIdempotencyKey(string $key, ?string $requestFingerprint = null): self
    {
        return new self(
            actor: $this->actor,
            scope: $this->scope,
            tenant: $this->tenant,
            correlationId: $this->correlationId,
            causationId: $this->causationId,
            idempotencyKey: $key,
            requestFingerprint: $requestFingerprint,
        );
    }

    public function withCausation(string $causationId): self
    {
        return new self(
            actor: $this->actor,
            scope: $this->scope,
            tenant: $this->tenant,
            correlationId: $this->correlationId,
            causationId: $causationId,
            idempotencyKey: $this->idempotencyKey,
            requestFingerprint: $this->requestFingerprint,
        );
    }

    /**
     * Span and log attributes of this context. Carries no personal data (the actor and
     * the tenant appear only as tagged kinds) and never the idempotency key's value or fingerprint.
     *
     * @return array<string, string>
     */
    public function telemetry(): array
    {
        $attributes = [
            'vaulter.correlation_id' => $this->correlationId,
            'vaulter.scope.kind' => $this->scope->kind,
            'vaulter.tenant' => $this->tenant->kind,
            'vaulter.actor.kind' => $this->actor->isSystem() ? 'system' : 'user',
        ];
        if ($this->causationId !== null) {
            $attributes['vaulter.causation_id'] = $this->causationId;
        }

        return $attributes;
    }

    private static function assertTraceId(string $id, string $label): void
    {
        if (trim($id) === '' || strlen($id) > 191 || preg_match('/[\x00-\x1F\x7F]/', $id) === 1) {
            throw new InvalidArgumentException("[Vaulter] {$label} must be nonempty, at most 191 bytes and contain no control characters.");
        }
    }
}
