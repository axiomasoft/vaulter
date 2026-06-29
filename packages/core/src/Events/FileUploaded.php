<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\File;
use Vaulter\Models\FileVersion;
use Vaulter\Models\Node;

/**
 * A brand-new file node was uploaded — first version committed against a
 * (possibly deduplicated) blob (§2.4). A subsequent upload onto the same node
 * fires NodeVersionCommitted instead.
 */
final readonly class FileUploaded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Node $node,
        public File $file,
        public FileVersion $version,
        public int|string|null $actorId = null,
    ) {}
}
