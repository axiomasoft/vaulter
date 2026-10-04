<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;

/**
 * A new version was committed onto an existing file node (§2.4): number
 * bumped, current_revision_id repointed, the prior head marked superseded.
 */
final readonly class NodeVersionCommitted implements ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public Node $node,
        public File $file,
        public FileRevision $version,
        public int $versionNo,
        public int|string|null $actorId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
