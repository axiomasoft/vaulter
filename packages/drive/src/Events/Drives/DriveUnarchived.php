<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Drives;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\Models\Drive\Drive;
use Vaulter\ValueObjects\Access\ActorRef;

/** The drive changed its lifecycle status (PLAN2.D27). */
final readonly class DriveUnarchived implements ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public Drive $drive,
        public ActorRef $actor,
        public ?string $correlationId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
