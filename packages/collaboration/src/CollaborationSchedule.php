<?php

declare(strict_types=1);

namespace Vaulter\Collaboration;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\ScheduleConfig;
use Vaulter\Modules\ModuleSchedule;
use Vaulter\Providers\SchedulingRegistrar;

/** The prune of closed collaboration generations on the host scheduler (C11). */
#[Internal]
final class CollaborationSchedule implements ModuleSchedule
{
    public const array KEYS = ['collaboration_prune'];

    private const array TASKS = [
        'collaboration_prune' => ['vaulter:collaboration:prune', SchedulingRegistrar::DEFAULT_EXPIRY],
    ];

    public function define(Schedule $schedule, Repository $config): void
    {
        $frequencies = app(ScheduleConfig::class)->frequencies('vaulter-collaboration', self::KEYS);
        foreach (self::TASKS as $key => [$command, $expiry]) {
            $frequency = $frequencies[$key] ?? null;
            if ($frequency !== null) {
                SchedulingRegistrar::task($schedule, $command, $frequency, $expiry);
            }
        }
    }
}
