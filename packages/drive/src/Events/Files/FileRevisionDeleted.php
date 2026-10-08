<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Files;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\Node;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * A non-current revision was deleted, by the API or by the retention pruning
 * (PLAN2.D48): its blob edges are gone and its bytes left the drive's quota. The
 * revision row no longer exists, so the event carries its id, number and size.
 */
final readonly class FileRevisionDeleted implements ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public Node $node,
        public File $file,
        public int|string $revisionId,
        public int $revisionNo,
        public int $sizeBytes,
        public int|string|null $actorId = null,
        ?string $eventId = null,
        public ?ActorRef $actor = null,
        public ?string $correlationId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
