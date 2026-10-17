<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/**
 * The manifest of a module: who it is, what it needs and what it declares. A package's service
 * provider offers its manifest from `register()` ({@see OffersModules}); the kernel compiles every
 * offered manifest once, after all providers registered and before any provider boots.
 *
 * `register()` only declares: no I/O, no container lookups, no actor, tenant or clock.
 */
#[Spi(SpiKind::Implement)]
interface VaulterModule
{
    public function id(): ModuleId;

    /** The SPI protocol of the module, `MAJOR.MINOR`; a dependant states `^MAJOR.MINOR`. */
    public function version(): string;

    public function activation(): Activation;

    /** @return array<string, string> module id => version constraint (`^1.0`) */
    public function requires(): array;

    public function register(ModuleBuilder $module): void;
}
