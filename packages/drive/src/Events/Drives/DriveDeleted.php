<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Drives;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\ValueObjects\Access\ActorRef;

/** The last row of a drive is gone: its purge finished (PLAN2.D27). Ids only — the row no longer exists. */
final readonly class DriveDeleted implements ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public string $driveId,
        public string $ownerType,
        public string $ownerId,
        public ActorRef $actor,
        public ?string $correlationId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
