<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Drive\Data\CopyResult;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;

#[Spi(SpiKind::Implement)]
interface NodeContentLifecycle
{
    public function kind(): NodeKind;

    public function copy(DriveContext $context, Node $source, Node $copy): CopyResult;

    public function purge(DriveContext $context, Node $node): void;
}
