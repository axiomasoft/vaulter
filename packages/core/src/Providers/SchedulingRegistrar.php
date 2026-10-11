<?php

declare(strict_types=1);

namespace Vaulter\Providers;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Vaulter\Configuration\ScheduleConfig;
use Vaulter\Configuration\ScheduleFrequency;
use Vaulter\Configuration\VaulterConfig;

/**
 * Puts the core maintenance commands on the host scheduler. Sibling packages reuse
 * {@see self::task()} so every Vaulter task carries the same overlap and
 * single-server guards.
 */
final class SchedulingRegistrar
{
    /** Minutes a stale overlap mutex lives: a stuck relay/retry run must not block recovery for long. */
    public const int RECOVERY_EXPIRY = 10;

    public const int DEFAULT_EXPIRY = 120;

    public const array KEYS = ['webhooks_relay', 'webhooks_retry', 'webhooks_prune', 'changes_prune'];

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

    public function register(Application $app): void
    {
        // Fail at boot on a bad value; the tasks themselves read the config when the scheduler resolves.
        (new ScheduleConfig($app->make('config')))->frequencies('vaulter', self::KEYS);

        self::whenScheduling($app, static function (Schedule $scheduler) use ($app): void {
            $schedule = new ScheduleConfig($app->make('config'));
            if (!$schedule->enabled()) {
                return;
            }

            $frequencies = $schedule->frequencies('vaulter', self::KEYS);
            $vaulter = $app->make(VaulterConfig::class);
            $tasks = [
                'webhooks_relay' => ['vaulter:webhooks:relay', self::RECOVERY_EXPIRY, 'webhooks'],
                'webhooks_retry' => ['vaulter:webhooks:retry', self::RECOVERY_EXPIRY, 'webhooks'],
                'webhooks_prune' => ['vaulter:webhooks:prune', self::DEFAULT_EXPIRY, 'webhooks'],
                'changes_prune' => ['vaulter:changes:prune', self::DEFAULT_EXPIRY, 'changes'],
            ];
            foreach ($tasks as $key => [$command, $expiry, $feature]) {
                $frequency = $frequencies[$key] ?? null;
                if ($frequency !== null && $vaulter->featureEnabled($feature)) {
                    self::task($scheduler, $command, $frequency, $expiry);
                }
            }
        });
    }
}
