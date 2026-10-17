<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Illuminate\Contracts\Foundation\Application;
use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/**
 * The container wiring of a module that a declaration cannot express (scoped or factory bindings,
 * policies, rate limiters), declared with {@see ModuleBuilder::bindings()}. The kernel instantiates it
 * only for an active module: `register()` runs once the graph is sealed, before any provider boots;
 * `boot()` runs while the core provider boots.
 */
#[Spi(SpiKind::Implement)]
interface ModuleBindings
{
    public function register(Application $app): void;

    public function boot(Application $app): void;
}
