<?php

declare(strict_types=1);

namespace Vaulter\Events\Drive;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\Models\Drive\Node;

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
