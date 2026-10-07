<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Nodes;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\Models\Drive\Node;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * A node (folder, file or document) was created in a storage tree (§2.2).
 */
final readonly class FolderCreated implements ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public Node $node,
        public int|string|null $actorId = null,
        ?string $eventId = null,
        public ?ActorRef $actor = null,
        public ?string $correlationId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
