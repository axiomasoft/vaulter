<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Drives;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * A storage container was provisioned for a host entity, together with its
 * auto-created root folder node (§2.1).
 */
final readonly class DriveCreated implements ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public Drive $storage,
        public Node $root,
        public int|string|null $actorId = null,
        ?string $eventId = null,
        public ?ActorRef $actor = null,
        public ?string $correlationId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
