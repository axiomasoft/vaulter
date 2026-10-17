<?php

declare(strict_types=1);

namespace Vaulter\Events\Outbox;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\ScheduleConfig;
use Vaulter\Modules\ModuleSchedule;
use Vaulter\Providers\SchedulingRegistrar;

/**
 * Puts the outbox relay (`vaulter.schedule.outbox_relay`, every minute) and its retention
 * (`vaulter.schedule.outbox_prune`, hourly) on the host scheduler, without overlapping (D29). null switches one off.
 */
#[Internal]
final readonly class OutboxSchedule implements ModuleSchedule
{
    public const array KEYS = ['outbox_relay', 'outbox_prune'];

    private const array TASKS = [
        'outbox_relay' => ['vaulter:relay', SchedulingRegistrar::RECOVERY_EXPIRY],
        'outbox_prune' => ['vaulter:outbox:prune', SchedulingRegistrar::DEFAULT_EXPIRY],
    ];

    public function define(Schedule $schedule, Repository $config): void
    {
        $frequencies = (new ScheduleConfig($config))->frequencies('vaulter', self::KEYS);
        foreach (self::TASKS as $key => [$command, $expiry]) {
            $frequency = $frequencies[$key] ?? null;
            if ($frequency !== null) {
                SchedulingRegistrar::task($schedule, $command, $frequency, $expiry);
            }
        }
    }
}
