<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\RestoreOptions;
use Vaulter\Data\Page;
use Vaulter\Data\Snapshots\NodeSnapshot;

interface TrashOperations
{
    /** @return Page<NodeSnapshot> */
    public function list(?ListOptions $options = null): Page;

    public function restore(NodeSnapshot|string|int $node, ?RestoreOptions $options = null): NodeSnapshot;

    /**
     * Delete a trashed node and its subtree for good (Delete). With $expectedSequence
     * the node must still be at that sequence, checked before anything is removed.
     */
    public function purge(NodeSnapshot|string|int $node, ?int $expectedSequence = null): void;

    public function empty(): int;
}
