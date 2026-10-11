<?php

declare(strict_types=1);

namespace Vaulter\Drive\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Vaulter\Configuration\ScanConfig;
use Vaulter\Configuration\ScheduleConfig;
use Vaulter\Enums\Cas\ScanMode;
use Vaulter\Providers\SchedulingRegistrar as CoreSchedulingRegistrar;

/** Puts the drive maintenance commands on the host scheduler. */
final class SchedulingRegistrar
{
    private const array KEYS = [
        'uploads_sweep', 'trash_purge', 'drives_purge', 'revisions_prune', 'scan_reconcile', 'drives_recount',
    ];

    public function boot(Application $app): void
    {
        // Fail at boot on a bad value; the tasks themselves read the config when the scheduler resolves.
        (new ScheduleConfig($app->make('config')))->frequencies('vaulter-drive', self::KEYS);

        CoreSchedulingRegistrar::whenScheduling($app, static function (Schedule $scheduler) use ($app): void {
            $config = $app->make('config');
            $schedule = new ScheduleConfig($config);
            if (!$schedule->enabled()) {
                return;
            }

            $expiry = CoreSchedulingRegistrar::DEFAULT_EXPIRY;
            $tasks = [
                'uploads_sweep' => ['vaulter:uploads:sweep', $expiry, []],
                'trash_purge' => ['vaulter:trash:purge', $expiry, []],
                'drives_purge' => ['vaulter:drives:purge', $expiry, []],
                'revisions_prune' => ['vaulter:revisions:prune', $expiry, []],
                // Bounded so one tick never outlives its overlap mutex; independent of webhooks, which
                // are only one of the consumers of scan results.
                'scan_reconcile' => ['vaulter:scan:reconcile', CoreSchedulingRegistrar::RECOVERY_EXPIRY,
                    ['--all-tenants', '--reason=scheduled', '--limit=100', '--max-seconds=30']],
                // Report-only: correcting drift stays an operator decision.
                'drives_recount' => ['vaulter:drives:recount', $expiry, ['--dry-run']],
            ];
            $frequencies = $schedule->frequencies('vaulter-drive', self::KEYS);
            foreach ($tasks as $key => [$command, $minutes, $arguments]) {
                $frequency = $frequencies[$key] ?? null;
                if ($frequency === null) {
                    continue;
                }
                if ($key === 'scan_reconcile' && (new ScanConfig($config))->mode() === ScanMode::Off) {
                    continue;
                }
                CoreSchedulingRegistrar::task($scheduler, $command, $frequency, $minutes, $arguments);
            }
        });
    }
}
