<?php

declare(strict_types=1);

namespace Vaulter\Providers;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Vaulter\Configuration\ScheduleFrequency;

/**
 * The shared scheduling mechanism: {@see self::task()} gives every Vaulter task the same overlap and
 * single-server guards, {@see self::whenScheduling()} defines tasks whenever the scheduler resolves.
 * Modules declare their tasks with a {@see \Vaulter\Modules\ModuleSchedule}.
 */
final class SchedulingRegistrar
{
    /** Minutes a stale overlap mutex lives: a stuck relay/retry run must not block recovery for long. */
    public const int RECOVERY_EXPIRY = 10;

    public const int DEFAULT_EXPIRY = 120;

    /** @param list<string> $arguments */
    public static function task(Schedule $schedule, string $command, ScheduleFrequency $frequency, int $expiry, array $arguments = []): Event
    {
        $event = $schedule->command($command, $arguments)->name($command);

        return $frequency->applyTo($event)->withoutOverlapping($expiry)->onOneServer();
    }

    /** Runs $callback on the scheduler whether it is resolved before or after this call. */
    public static function whenScheduling(Application $app, \Closure $callback): void
    {
        $app->afterResolving(Schedule::class, $callback);
        if ($app->resolved(Schedule::class)) {
            $callback($app->make(Schedule::class));
        }
    }
}
