<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Vaulter domain routes, operation policies and lifecycle metadata accompanying a code plugin. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface WithModuleRuntime
{
    public function moduleRuntime(): PluginModuleSpec;
}
