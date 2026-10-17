<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Contracts\NodeContentLifecycle;
use Vaulter\Drive\Contracts\PurgeOnlyContentLifecycle;
use Vaulter\Drive\Data\CopyResult;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Exceptions\Nodes\UnsupportedNodeKindException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;

#[Internal]
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
