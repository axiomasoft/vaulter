<?php

declare(strict_types=1);

namespace Vaulter\Drive\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Packman\Attributes\Internal;
use Vaulter\Configuration\ScheduleFrequency;
use Vaulter\Drive\Configuration\DriveOptions;
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

    private const array TASKS = [
        'uploads_sweep' => ['vaulter:uploads:sweep', CoreSchedulingRegistrar::DEFAULT_EXPIRY, []],
        'trash_purge' => ['vaulter:trash:purge', CoreSchedulingRegistrar::DEFAULT_EXPIRY, []],
        'drives_purge' => ['vaulter:drives:purge', CoreSchedulingRegistrar::DEFAULT_EXPIRY, []],
        'revisions_prune' => ['vaulter:revisions:prune', CoreSchedulingRegistrar::DEFAULT_EXPIRY, []],
        'scan_reconcile' => [
            'vaulter:scan:reconcile',
            CoreSchedulingRegistrar::RECOVERY_EXPIRY,
            ['--all-tenants', '--reason=scheduled', '--limit=100', '--max-seconds=30'],
        ],
        'drives_recount' => ['vaulter:drives:recount', CoreSchedulingRegistrar::DEFAULT_EXPIRY, ['--dry-run']],
        'changes_prune' => ['vaulter:changes:prune', CoreSchedulingRegistrar::DEFAULT_EXPIRY, []],
    ];

    public function define(Schedule $schedule, Repository $config): void
    {
        $raw = $config->get('vaulter-drive', []);
        $options = DriveOptions::fromArray(is_array($raw) ? $raw : [], 'vaulter-drive');

        foreach (self::TASKS as $key => [$command, $minutes, $arguments]) {
            $frequency = ScheduleFrequency::parse('vaulter-drive.schedule.'.$key, $config->get('vaulter-drive.schedule.'.$key));
            if ($frequency === null) {
                continue;
            }
            if ($key === 'scan_reconcile' && $options->scan->mode === ScanMode::Off) {
                continue;
            }
            // The change journal is a feature flag: its prune task is only scheduled while the journal is on.
            if ($key === self::CHANGES_PRUNE_KEY && !$options->features->changes) {
                continue;
            }
            CoreSchedulingRegistrar::task($schedule, $command, $frequency, $minutes, $arguments);
        }
    }
}
