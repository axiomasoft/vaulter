<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Models\Drive\Drive;
use Vaulter\ValueObjects\Access\Actor;
use Vaulter\ValueObjects\Operations\ScopeRef;
use Vaulter\ValueObjects\Operations\TenantRef;

/**
 * The context of an operation on one drive: the neutral OperationContext scoped to
 * the drive, plus the bound drive model. Owned by drive; it lives in the core
 * namespace until the drive package takes it over.
 */
#[Api]
final readonly class DriveContext
{
    public Actor $actor;

    public string $correlationId;

    public ?string $idempotencyKey;

    private int|string $driveKey;

    #[Internal]
    public function __construct(
        public OperationContext $operation,
        public Drive $drive,
    ) {
        $key = self::persistedKey($drive);
        if (!$operation->scope->equals(ScopeRef::drive($key))) {
            throw new InvalidArgumentException('[Vaulter] A drive context requires an operation scoped to that drive.');
        }
        $this->driveKey = $key;
        $this->actor = $operation->actor;
        $this->correlationId = $operation->correlationId;
        $this->idempotencyKey = $operation->idempotencyKey;
    }

    public static function for(Drive $drive, Actor $actor, string $correlationId, ?string $idempotencyKey = null, ?string $requestFingerprint = null): self
    {
        return new self(
            operation: new OperationContext(
                actor: $actor,
                scope: ScopeRef::drive(self::persistedKey($drive)),
                tenant: TenantRef::of($drive->tenant_id),
                correlationId: $correlationId,
                idempotencyKey: $idempotencyKey,
                requestFingerprint: $requestFingerprint,
            ),
            drive: $drive,
        );
    }

    public static function system(Drive $drive, string $reason, ?string $correlationId = null): self
    {
        return self::for(drive: $drive, actor: Actor::system($reason), correlationId: $correlationId ?? (string) Str::ulid());
    }

    /** The bound identity must survive edits to the caller's model. */
    #[Internal]
    public function driveId(): int|string
    {
        return $this->driveKey;
    }

    private static function persistedKey(Drive $drive): int|string
    {
        $key = $drive->getRawOriginal($drive->getKeyName());
        if (!$drive->exists || (!is_int($key) && !is_string($key)) || $key === '') {
            throw new InvalidArgumentException('[Vaulter] An operation context requires a persisted drive.');
        }

        return $key;
    }
}
