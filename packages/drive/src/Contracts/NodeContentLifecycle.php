<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Drive\Data\CopyResult;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Operations\DriveContext;

#[Spi(SpiKind::Implement)]
interface NodeContentLifecycle
{
    public function kind(): NodeKind;

    public function copy(DriveContext $context, NodeSnapshot $source, NodeSnapshot $copy): CopyResult;

    public function purge(DriveContext $context, NodeSnapshot $node): void;
}
