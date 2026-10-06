<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Vaulter\Data\Drive\CopyResult;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;

interface NodeContentLifecycle
{
    public function kind(): NodeKind;

    public function copy(OperationContext $context, Node $source, Node $copy): CopyResult;

    public function purge(OperationContext $context, Node $node): void;
}
