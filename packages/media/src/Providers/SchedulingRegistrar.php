<?php

declare(strict_types=1);

namespace Vaulter\Media\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Vaulter\Configuration\ScheduleConfig;
use Vaulter\Providers\SchedulingRegistrar as CoreSchedulingRegistrar;

/** Puts the blob maintenance commands on the host scheduler. */
final class SchedulingRegistrar
{
    private const array COMMANDS = [
        'blobs_gc' => 'vaulter:blobs:gc',
        'blobs_reconcile' => 'vaulter:blobs:reconcile',
    ];

    public function boot(Application $app): void
    {
        // Fail at boot on a bad value; the tasks themselves read the config when the scheduler resolves.
        (new ScheduleConfig($app->make('config')))->frequencies('vaulter-media', array_keys(self::COMMANDS));

        CoreSchedulingRegistrar::whenScheduling($app, static function (Schedule $scheduler) use ($app): void {
            $schedule = new ScheduleConfig($app->make('config'));
            if (!$schedule->enabled()) {
                return;
            }

            foreach ($schedule->frequencies('vaulter-media', array_keys(self::COMMANDS)) as $key => $frequency) {
                if ($frequency !== null) {
                    CoreSchedulingRegistrar::task($scheduler, self::COMMANDS[$key], $frequency, CoreSchedulingRegistrar::DEFAULT_EXPIRY);
                }
            }
        });
    }
}
