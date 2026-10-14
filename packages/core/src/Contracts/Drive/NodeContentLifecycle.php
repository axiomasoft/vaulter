<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Vaulter\Data\Drive\CopyResult;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;

interface NodeContentLifecycle
{
    public function kind(): NodeKind;

    public function copy(DriveContext $context, Node $source, Node $copy): CopyResult;

    public function purge(DriveContext $context, Node $node): void;
}
