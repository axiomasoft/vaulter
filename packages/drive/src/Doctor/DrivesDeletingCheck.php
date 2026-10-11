<?php

declare(strict_types=1);

namespace Vaulter\Drive\Doctor;

use Carbon\CarbonImmutable;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Enums\Drive\DriveStatus;

final readonly class DrivesDeletingCheck implements DoctorCheck
{
    private const int HOURS = 24;

    public function __construct(private VaulterConfig $config) {}

    public function key(): string
    {
        return 'drive.drives.deleting';
    }

    public function title(): string
    {
        return 'Drives waiting to be purged';
    }

    public function run(DoctorContext $context): array
    {
        // Timestamps of the drive table follow the application's timezone.
        $since = CarbonImmutable::instance($context->now)->setTimezone(date_default_timezone_get())->subHours(self::HOURS);
        $stuck = $this->config->model('drive')::query()->where('status', DriveStatus::Deleting->value)->where('updated_at', '<', $since)->count();

        if ($stuck === 0) {
            return [DoctorFinding::ok(code: 'drive.drives.deleting_none', message: 'No drive has been waiting to be purged for more than a day.')];
        }

        return [DoctorFinding::warning(
            code: 'drive.drives.deleting_stuck',
            message: $stuck.' drive(s) have been in deleting status for more than '.self::HOURS.' hours.',
            hint: 'run vaulter:drives:purge --dry-run; check that it is scheduled',
            evidence: ['drives' => $stuck],
        )];
    }
}
