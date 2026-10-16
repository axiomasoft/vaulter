<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Doctor\DoctorRunner;
use Vaulter\Doctor\DoctorSeverity;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Providers\BindingsRegistrar;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

beforeEach(fn () => DriveTestHelpers::insideOperation());

/** @return list<DoctorFinding> */
function driveFindings(string $key, bool $production = false, int $sample = 1000): array
{
    $report = app(DoctorRunner::class)->run(new DoctorContext(production: $production, probeStorage: false, sampleLimit: $sample, now: CarbonImmutable::now('UTC')), [$key]);

    return $report->checks[0]->findings;
}

function driveTable(string $entity): string
{
    return app(DatabaseConfig::class)->table($entity);
}

it('accepts a writable temp directory with more free space than the largest file', function (): void {
    config()->set('vaulter-drive.uploads.max_file_size', 1024);

    $finding = driveFindings('drive.uploads.tmp')[0];

    expect($finding->code)->toBe('drive.uploads.tmp_ok')
        ->and($finding->evidence['writable'])->toBeTrue()
        ->and($finding->evidence['max_file_size'])->toBe(1024);
});

it('reports a temp directory smaller than the largest accepted file as an error', function (): void {
    config()->set('vaulter-drive.uploads.max_file_size', PHP_INT_MAX);

    $finding = driveFindings('drive.uploads.tmp')[0];

    expect($finding->code)->toBe('drive.uploads.tmp_too_small')
        ->and($finding->severity)->toBe(DoctorSeverity::Error)
        ->and(driveFindings('drive.uploads.tmp', production: true)[0]->severity)->toBe(DoctorSeverity::Error);
});

it('reports the intl extension', function (): void {
    $finding = driveFindings('drive.intl')[0];

    expect($finding->code)->toBe(extension_loaded('intl') ? 'drive.intl_ok' : 'drive.intl_missing')
        ->and($finding->severity)->toBe(extension_loaded('intl') ? DoctorSeverity::Ok : DoctorSeverity::Warning);
});

it('finds drives stuck in deleting for more than a day', function (): void {
    $drive = DriveTestHelpers::drive();
    $id = $drive->model()->getKey();
    DB::table(driveTable('drives'))->where('id', $id)->update(['status' => 'deleting', 'updated_at' => now()->subHours(2)]);
    expect(driveFindings('drive.drives.deleting')[0]->code)->toBe('drive.drives.deleting_none');

    DB::table(driveTable('drives'))->where('id', $id)->update(['updated_at' => now()->subHours(30)]);
    $finding = driveFindings('drive.drives.deleting')[0];

    expect($finding->code)->toBe('drive.drives.deleting_stuck')
        ->and($finding->severity)->toBe(DoctorSeverity::Warning)
        ->and($finding->evidence['drives'])->toBe(1)
        ->and($finding->hint)->toContain('vaulter:drives:purge --dry-run')
        ->and(driveFindings('drive.drives.deleting', production: true)[0]->severity)->toBe(DoctorSeverity::Warning);
});

it('finds the drift of used_bytes with the quota calculator and writes nothing', function (): void {
    Storage::fake('local');
    $drive = DriveTestHelpers::drive();
    $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('abcde'), 'a.bin'));
    $id = $drive->model()->getKey();
    expect(driveFindings('drive.drives.usage')[0]->code)->toBe('drive.drives.usage_ok');

    DB::table(driveTable('drives'))->where('id', $id)->update(['used_bytes' => 105]);
    $statements = [];
    DB::listen(static function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });
    $finding = driveFindings('drive.drives.usage')[0];

    expect($finding->code)->toBe('drive.drives.usage_drift')
        ->and($finding->severity)->toBe(DoctorSeverity::Warning)
        ->and($finding->evidence)->toBe(['drifted' => 1, 'checked' => 1, 'largest_drift_bytes' => 100])
        ->and($finding->hint)->toContain('vaulter:drives:recount --dry-run')
        ->and(DB::table(driveTable('drives'))->where('id', $id)->value('used_bytes'))->toBe(105)
        ->and(array_filter($statements, static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete)\b|for update/i', $sql) === 1))->toBe([]);
});

it('samples only the drives within the limit', function (): void {
    Storage::fake('local');
    foreach ([1, 2] as $_) {
        $drive = DriveTestHelpers::drive();
        $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('abc'), 'a.bin'));
        DB::table(driveTable('drives'))->where('id', $drive->model()->getKey())->update(['used_bytes' => 999]);
    }

    expect(driveFindings('drive.drives.usage', sample: 1)[0]->evidence['checked'])->toBe(1)
        ->and(driveFindings('drive.drives.usage')[0]->evidence['checked'])->toBe(2);
});

it('does not count a drive that is being deleted in the usage check', function (): void {
    $drive = DriveTestHelpers::drive();
    DB::table(driveTable('drives'))->where('id', $drive->model()->getKey())->update(['status' => 'deleting', 'used_bytes' => 5]);

    expect(driveFindings('drive.drives.usage')[0]->code)->toBe('drive.drives.usage_ok');
});

it('validates the drive configuration as one of the config validators', function (): void {
    config()->set('vaulter-drive.models.upload_session', stdClass::class);

    $finding = driveFindings('core.config.valid')[0];

    expect($finding->severity)->toBe(DoctorSeverity::Error)
        ->and($finding->evidence['validator'])->toBe(BindingsRegistrar::class)
        ->and(app(DriveConfig::class))->toBeInstanceOf(DriveConfig::class);
});
