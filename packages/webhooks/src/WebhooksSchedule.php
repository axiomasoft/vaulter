<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\ScheduleConfig;
use Vaulter\Modules\ModuleSchedule;
use Vaulter\Providers\SchedulingRegistrar;

/** Retry and prune of the webhooks module on the host scheduler; the outbox relay is core's. */
#[Internal]
final class WebhooksSchedule implements ModuleSchedule
{
    public const array KEYS = ['webhooks_retry', 'webhooks_prune'];

    private const array TASKS = [
        'webhooks_retry' => ['vaulter:webhooks:retry', SchedulingRegistrar::RECOVERY_EXPIRY],
        'webhooks_prune' => ['vaulter:webhooks:prune', SchedulingRegistrar::DEFAULT_EXPIRY],
    ];

    public function define(Schedule $schedule, Repository $config): void
    {
        $frequencies = (new ScheduleConfig($config))->frequencies('vaulter-webhooks', self::KEYS);
        foreach (self::TASKS as $key => [$command, $expiry]) {
            $frequency = $frequencies[$key] ?? null;
            if ($frequency !== null) {
                SchedulingRegistrar::task($schedule, $command, $frequency, $expiry);
            }
        }
    }
}
