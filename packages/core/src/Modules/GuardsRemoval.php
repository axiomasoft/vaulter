<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/**
 * A module manifest whose module can refuse its own removal while its data is in a state only it can judge (the
 * collaboration edits not in a checkpoint yet): it names the class that reports what stops the removal. Static,
 * so the kernel reads it from the compiled manifest class of an inactive module too — and a module is switched off
 * before `vaulter:module:uninstall` removes it.
 */
#[Spi(SpiKind::Implement)]
interface GuardsRemoval
{
    /** @return class-string<RemovalGuard> */
    public static function removalGuard(): string;
}
