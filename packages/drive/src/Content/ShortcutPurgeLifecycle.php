<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Vaulter\Contracts\Drive\NodeContentLifecycle;
use Vaulter\Data\Drive\CopyResult;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\UnsupportedNodeKindException;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;

final readonly class ShortcutPurgeLifecycle implements NodeContentLifecycle
{
    public function kind(): NodeKind
    {
        return NodeKind::Shortcut;
    }

    public function copy(OperationContext $context, Node $source, Node $copy): CopyResult
    {
        throw UnsupportedNodeKindException::forKind($this->kind());
    }

    public function purge(OperationContext $context, Node $node): void
    {
        // NodeStoreRepository::purgeSubtree physically deletes dependent rows by FK cascade.
    }
}
