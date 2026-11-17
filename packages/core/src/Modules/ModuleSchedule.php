<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * The scheduled tasks of a module, declared by class with {@see ModuleBuilder::schedule()} and defined
 * only when the host's scheduler runs.
 */
#[Spi(SpiKind::Implement)]
interface ModuleSchedule
{
    public function define(Schedule $schedule, Repository $config): void;
}
