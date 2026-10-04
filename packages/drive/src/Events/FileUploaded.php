<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;

/**
 * A brand-new file node was uploaded — first version committed against a
 * (possibly deduplicated) blob (§2.4). A subsequent upload onto the same node
 * fires NodeVersionCommitted instead.
 */
final readonly class FileUploaded implements ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public Node $node,
        public File $file,
        public FileRevision $version,
        public int|string|null $actorId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
