<?php

declare(strict_types=1);

namespace Vaulter\Media\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Vaulter\Configuration\ScheduleConfig;
use Vaulter\Modules\ModuleSchedule;
use Vaulter\Providers\SchedulingRegistrar as CoreSchedulingRegistrar;

/** Puts the blob maintenance commands of the media module on the host scheduler. */
final class SchedulingRegistrar implements ModuleSchedule
{
    public const array KEYS = ['blobs_gc', 'blobs_reconcile'];

    private const array COMMANDS = [
        'blobs_gc' => 'vaulter:blobs:gc',
        'blobs_reconcile' => 'vaulter:blobs:reconcile',
    ];

    public function define(Schedule $schedule, Repository $config): void
    {
        foreach ((new ScheduleConfig($config))->frequencies('vaulter-media', self::KEYS) as $key => $frequency) {
            if ($frequency !== null) {
                CoreSchedulingRegistrar::task($schedule, self::COMMANDS[$key], $frequency, CoreSchedulingRegistrar::DEFAULT_EXPIRY);
            }
        }
    }
}
