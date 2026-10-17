<?php

declare(strict_types=1);

namespace Vaulter\Drive\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\ScheduleConfig;
use Vaulter\Drive\Configuration\ScanConfig;
use Vaulter\Media\Enums\Cas\ScanMode;
use Vaulter\Modules\ModuleSchedule;
use Vaulter\Providers\SchedulingRegistrar as CoreSchedulingRegistrar;

/** Puts the drive maintenance commands, with the change-journal prune, on the host scheduler. */
#[Internal]
final class SchedulingRegistrar implements ModuleSchedule
{
    public const array KEYS = [
        'uploads_sweep', 'trash_purge', 'drives_purge', 'revisions_prune', 'scan_reconcile', 'drives_recount', 'changes_prune',
    ];

    /** The key of the change-journal prune. */
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
            'changes_prune' => ['vaulter:changes:prune', $expiry, []],
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
            // The change journal is a feature flag: its prune task is only scheduled while the journal is on.
            if ($key === self::CHANGES_PRUNE_KEY && $config->get('vaulter-drive.features.changes') !== true) {
                continue;
            }
            CoreSchedulingRegistrar::task($schedule, $command, $frequency, $minutes, $arguments);
        }

    }
}
