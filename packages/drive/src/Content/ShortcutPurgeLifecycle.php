<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Vaulter\Contracts\Drive\NodeContentLifecycle;
use Vaulter\Contracts\Drive\PurgeOnlyContentLifecycle;
use Vaulter\Data\Drive\CopyResult;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\UnsupportedNodeKindException;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;

final readonly class ShortcutPurgeLifecycle implements NodeContentLifecycle, PurgeOnlyContentLifecycle
{
    public function kind(): NodeKind
    {
        return NodeKind::Shortcut;
    }

    public function copy(DriveContext $context, Node $source, Node $copy): CopyResult
    {
        throw UnsupportedNodeKindException::forKind($this->kind());
    }

    public function purge(DriveContext $context, Node $node): void
    {
        // NodeStoreRepository::purgeSubtree physically deletes dependent rows by FK cascade.
    }
}
