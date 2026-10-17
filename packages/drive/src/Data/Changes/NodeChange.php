<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Changes;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Changes\ChangeOperation;
use Vaulter\Drive\Enums\Changes\ChangeReason;

/**
 * One change of a node as the reader sees it. An upsert carries the node's current
 * snapshot; with $subtree the client re-reads the folder's subtree by listing. A remove
 * carries no snapshot: the node is gone for this reader, and a removed folder takes
 * its whole subtree with it.
 */
#[Internal]
final readonly class NodeChange
{
    public function __construct(
        public string $nodeId,
        public ChangeOperation $operation,
        public ?ChangeReason $reason,
        public bool $subtree,
        public ?NodeSnapshot $node,
        public ?int $nodeSequence,
    ) {}
}
