<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Activity;

use Packman\Attributes\Api;
use Vaulter\Data\Page;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\Snapshots\ActivityEntrySnapshot;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;

#[Api]
interface ActivityOperations
{
    /** @return Page<ActivityEntrySnapshot> */
    public function forDrive(?ListOptions $options = null): Page;

    /** @return Page<ActivityEntrySnapshot> */
    public function forNode(NodeSnapshot|string|int $node, ?ListOptions $options = null): Page;
}
