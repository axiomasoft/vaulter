<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Events;

use Vaulter\Support\Identity;

/**
 * Where an event happened: the tenant, the drive and the profile whose endpoints may
 * receive it. Ids travel as strings so the activity row, the outbox row and the
 * webhook envelope carry the same value whatever the id strategy.
 */
final readonly class EventScope
{
    public ?string $tenantId;

    public ?string $driveId;

    public function __construct(
        mixed $tenantId,
        mixed $driveId,
        public string $profile,
    ) {
        $this->tenantId = self::id($tenantId);
        $this->driveId = self::id($driveId);
    }

    private static function id(mixed $value): ?string
    {
        $id = Identity::stringify($value);

        return $id === '' ? null : $id;
    }
}
