<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Activity;

use Vaulter\Attributes\Api;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Page;
use Vaulter\Data\Snapshots\ActivityEntrySnapshot;
use Vaulter\Data\Snapshots\NodeSnapshot;

#[Api]
interface ActivityOperations
{
    /** @return Page<ActivityEntrySnapshot> */
    public function forDrive(?ListOptions $options = null): Page;

    /** @return Page<ActivityEntrySnapshot> */
    public function forNode(NodeSnapshot|string|int $node, ?ListOptions $options = null): Page;
}
