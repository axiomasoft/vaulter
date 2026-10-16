<?php

declare(strict_types=1);

namespace Vaulter\Drive\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\ScanConfig;
use Vaulter\Configuration\ScheduleConfig;
use Vaulter\Media\Enums\Cas\ScanMode;
use Vaulter\Modules\ModuleSchedule;
use Vaulter\Providers\SchedulingRegistrar as CoreSchedulingRegistrar;

/**
 * Puts the drive maintenance commands on the host scheduler, with the change-journal prune whose command
 * and key (`vaulter.schedule.changes_prune`) are still located in core (D39).
 */
#[Internal]
final class SchedulingRegistrar implements ModuleSchedule
{
    public const array KEYS = [
        'uploads_sweep', 'trash_purge', 'drives_purge', 'revisions_prune', 'scan_reconcile', 'drives_recount',
    ];

    /** The core-located key of the change-journal prune; P4 moves it into the drive config. */
    public const string CHANGES_PRUNE_KEY = 'changes_prune';

    public function define(Schedule $schedule, Repository $config): void
    {
        $schedules = new ScheduleConfig($config);
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
        $frequencies = $schedules->frequencies('vaulter-drive', self::KEYS);
        foreach ($tasks as $key => [$command, $minutes, $arguments]) {
            $frequency = $frequencies[$key] ?? null;
            if ($frequency === null) {
                continue;
            }
            if ($key === 'scan_reconcile' && (new ScanConfig($config))->mode() === ScanMode::Off) {
                continue;
            }
            CoreSchedulingRegistrar::task($schedule, $command, $frequency, $minutes, $arguments);
        }

        $changes = $schedules->frequencies('vaulter', [self::CHANGES_PRUNE_KEY])[self::CHANGES_PRUNE_KEY] ?? null;
        if ($changes !== null && $config->get('vaulter.features.changes') === true) {
            CoreSchedulingRegistrar::task($schedule, 'vaulter:changes:prune', $changes, $expiry);
        }
    }
}
