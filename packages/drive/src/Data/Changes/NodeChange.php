<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Changes;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Changes\ChangeOperation;

/**
 * One change of a node as the reader sees it. An upsert carries the node's current
 * snapshot; with $subtree the client re-reads the folder's subtree by listing. A remove
 * is minimal: only the operation and the reader-scoped $syncKey of the node that is gone
 * for this reader (a removed folder takes its whole subtree with it) — no node id, no
 * reason, no snapshot. $syncKey is null only for an anonymous reader's upsert.
 */
#[Internal]
final readonly class NodeChange
{
    public function __construct(
        public ChangeOperation $operation,
        public ?string $syncKey,
        public ?string $nodeId,
        public bool $subtree,
        public ?NodeSnapshot $node,
        public ?int $nodeSequence,
    ) {}
}
