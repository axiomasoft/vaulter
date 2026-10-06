<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Vaulter\Models\Drive\Drive;
use Vaulter\ValueObjects\Access\Actor;

final readonly class OperationContext
{
    private int|string $driveKey;

    public function __construct(
        public Drive $drive,
        public Actor $actor,
        public string $correlationId,
        public ?string $idempotencyKey = null,
    ) {
        if (trim($correlationId) === '' || strlen($correlationId) > 191 || preg_match('/[\x00-\x1F\x7F]/', $correlationId) === 1) {
            throw new InvalidArgumentException('[Vaulter] Correlation ID must be nonempty, at most 191 bytes and contain no control characters.');
        }
        $key = $drive->getRawOriginal($drive->getKeyName());
        if (!$drive->exists || (!is_int($key) && !is_string($key)) || $key === '') {
            throw new InvalidArgumentException('[Vaulter] An operation context requires a persisted drive.');
        }
        $this->driveKey = $key;
    }

    public static function system(Drive $drive, string $reason, ?string $correlationId = null): self
    {
        return new self(drive: $drive, actor: Actor::system($reason), correlationId: $correlationId ?? (string) Str::ulid());
    }

    /** @internal The bound identity must survive edits to the caller's model. */
    public function driveId(): int|string
    {
        return $this->driveKey;
    }
}
