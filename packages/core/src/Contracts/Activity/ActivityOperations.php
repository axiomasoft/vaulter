<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Activity;

use Illuminate\Pagination\CursorPaginator;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Drive\Node;

interface ActivityOperations
{
    /** @return CursorPaginator<int, Activity> */
    public function forDrive(?ListOptions $options = null): CursorPaginator;

    /** @return CursorPaginator<int, Activity> */
    public function forNode(Node|int|string $node, ?ListOptions $options = null): CursorPaginator;
}
