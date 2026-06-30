<?php

declare(strict_types=1);

namespace Vaulter\Events\Drive;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\Models\Drive\Node;

/**
 * A new version was committed onto an existing file node (§2.4): version_no
 * bumped, current_version_id repointed, the prior head marked superseded.
 */
final readonly class NodeVersionCommitted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Node $node,
        public File $file,
        public FileVersion $version,
        public int $versionNo,
        public int|string|null $actorId = null,
    ) {}
}
