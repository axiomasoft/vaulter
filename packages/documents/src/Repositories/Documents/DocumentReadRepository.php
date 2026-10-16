<?php

declare(strict_types=1);

namespace Vaulter\Documents\Repositories\Documents;

use Vaulter\Attributes\Internal;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Models\Drive\Node;

/**
 * Lookups of the document aggregate of a node. Opens no transactions and makes no
 * access decisions.
 */
#[Internal]
final class DocumentReadRepository
{
    public function forNode(Node $node): ?Document
    {
        return Document::query()->where('node_id', $node->getKey())->first();
    }

    /**
     * The aggregate row locked FOR UPDATE — the D level of the T→N→D lock order, so
     * the caller must already hold the tree and node locks.
     */
    public function lockForNode(Node $node): ?Document
    {
        return Document::query()->where('node_id', $node->getKey())->lockForUpdate()->first();
    }
}
