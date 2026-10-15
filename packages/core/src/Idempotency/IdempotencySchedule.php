<?php

declare(strict_types=1);

namespace Vaulter\Idempotency;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\ScheduleConfig;
use Vaulter\Modules\ModuleSchedule;
use Vaulter\Providers\SchedulingRegistrar;

/**
 * Puts `vaulter:idempotency:prune` on the host scheduler under `vaulter.schedule.idempotency_prune` (daily by
 * default; null switches it off).
 */
#[Internal]
final class IdempotencySchedule implements ModuleSchedule
{
    public const string KEY = 'idempotency_prune';

    public function define(Schedule $schedule, Repository $config): void
    {
        $frequency = (new ScheduleConfig($config))->frequencies('vaulter', [self::KEY])[self::KEY] ?? null;
        if ($frequency !== null) {
            SchedulingRegistrar::task($schedule, 'vaulter:idempotency:prune', $frequency, SchedulingRegistrar::DEFAULT_EXPIRY);
        }
    }
}
