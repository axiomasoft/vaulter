<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts;

use Vaulter\Attributes\Api;
use Vaulter\Data\Page;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\RestoreOptions;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;

#[Api]
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
