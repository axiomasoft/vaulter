<?php

declare(strict_types=1);

namespace Vaulter\Data\Snapshots;

use DateTimeImmutable;

/**
 * Read-only view of an activity row after the visibility projection: fields the
 * reader may not see are null.
 */
final readonly class ActivityEntrySnapshot
{
    /**
     * @internal
     *
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $id,
        public string $verb,
        public ?string $driveId,
        public ?string $targetType,
        public ?string $targetId,
        public ?string $actorType,
        public ?string $actorId,
        public array $context,
        public ?string $ip,
        public ?string $userAgent,
        public ?string $requestId,
        public DateTimeImmutable $createdAt,
    ) {}
}
