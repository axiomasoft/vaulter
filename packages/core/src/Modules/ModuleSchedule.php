<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;

/**
 * The scheduled tasks of a module, declared by class with {@see ModuleBuilder::schedule()} and defined
 * only when the host's scheduler runs.
 */
interface ModuleSchedule
{
    public function define(Schedule $schedule, Repository $config): void;
}
