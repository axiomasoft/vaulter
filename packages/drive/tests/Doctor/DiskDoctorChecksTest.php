<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Doctor\DoctorRegistry;
use Vaulter\Doctor\DoctorRunner;
use Vaulter\Doctor\DoctorSeverity;
use Vaulter\Drive\Doctor\DedupScopeCheck;
use Vaulter\Drive\Doctor\DiskProbeCheck;
use Vaulter\Drive\Doctor\DiskSet;
use Vaulter\Drive\DriveModule;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Media\MediaModule;
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileRegistry;

/** @return list<DoctorFinding> */
function mediaFindings(string $key, bool $production = false, bool $probe = false): array
{
    $report = app(DoctorRunner::class)->run(new DoctorContext(production: $production, probeStorage: $probe, sampleLimit: 1000, now: CarbonImmutable::now('UTC')), [$key]);

    return $report->checks[0]->findings;
}

it('does not touch a disk without --probe-storage', function (): void {
    Storage::fake('local');

    $findings = mediaFindings('media.disk.probe');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(DoctorSeverity::Ok)
        ->and($findings[0]->message)->toContain('not evaluated; pass --probe-storage')
        ->and($findings[0]->evidence['skipped'])->toBeTrue()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('writes, reads and deletes a test object on the disk when probing', function (): void {
    Storage::fake('local');

    $findings = mediaFindings('media.disk.probe', probe: true);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code)->toBe('media.disk.probe_ok')
        ->and($findings[0]->evidence['disk'])->toBe('local')
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('probes in production without the flag', function (): void {
    Storage::fake('local');

    expect(mediaFindings('media.disk.probe', production: true)[0]->code)->toBe('media.disk.probe_ok');
});

it('probes every profile disk once', function (): void {
    Storage::fake('local');
    Storage::fake('other');
    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key('default'));
    $registry->register((new Profile)->key('second')->disk('other'));
    $registry->register((new Profile)->key('third')->disk('other'));
    $this->app->instance(ProfileRegistry::class, $registry);

    $disks = array_map(static fn (DoctorFinding $finding): mixed => $finding->evidence['disk'], mediaFindings('media.disk.probe', probe: true));

    expect($disks)->toBe(['local', 'other']);
});

it('reports a disk that fails without quoting the driver message and leaves nothing behind', function (): void {
    Storage::fake('local');
    $disk = Storage::disk('local');
    Storage::set('local', new class($disk) extends FilesystemAdapter
    {
        public function __construct(private readonly Filesystem $inner)
        {
            parent::__construct($inner->getDriver(), $inner->getAdapter(), []);
        }

        public function readStream($path)
        {
            throw new RuntimeException('s3 secret=hunter2');
        }
    });

    $finding = mediaFindings('media.disk.probe', probe: true)[0];

    expect($finding->severity)->toBe(DoctorSeverity::Error)
        ->and($finding->code)->toBe('media.disk.probe_failed')
        ->and($finding->evidence['stage'])->toBe('read')
        ->and(json_encode($finding))->not->toContain('hunter2')
        ->and($disk->allFiles())->toBe([]);
});

it('does not call a disk healthy when the test object cannot be deleted', function (): void {
    Storage::fake('local');
    $disk = Storage::disk('local');
    Storage::set('local', new class($disk) extends FilesystemAdapter
    {
        public function __construct(private readonly Filesystem $inner)
        {
            parent::__construct($inner->getDriver(), $inner->getAdapter(), []);
        }

        public function delete($paths)
        {
            throw new RuntimeException('AccessDenied secret=hunter2');
        }
    });

    $finding = mediaFindings('media.disk.probe', probe: true)[0];

    expect($finding->code)->toBe('media.disk.probe_delete_failed')
        ->and($finding->severity)->toBe(DoctorSeverity::Error)
        ->and($finding->evidence['object'])->toStartWith('.vaulter-doctor/')
        ->and($disk->allFiles())->toHaveCount(1)
        ->and(json_encode($finding))->not->toContain('hunter2');
});

it('reports a disk that is not configured', function (): void {
    config(['vaulter-media.disk' => 'no-such-disk']);

    $finding = mediaFindings('media.disk.probe', probe: true)[0];

    expect($finding->code)->toBe('media.disk.probe_failed')->and($finding->severity)->toBe(DoctorSeverity::Error);
});

it('warns when tenant-wide deduplication spans disks', function (): void {
    expect(mediaFindings('media.dedup.scope')[0]->code)->toBe('media.dedup.scope_ok');

    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key('default'));
    $registry->register((new Profile)->key('second')->disk('other'));
    $this->app->instance(ProfileRegistry::class, $registry);
    config(['vaulter-media.dedup.scope' => 'tenant']);

    $finding = mediaFindings('media.dedup.scope')[0];
    expect($finding->code)->toBe('media.dedup.scope_spans_disks')
        ->and($finding->severity)->toBe(DoctorSeverity::Warning)
        ->and(mediaFindings('media.dedup.scope', production: true)[0]->severity)->toBe(DoctorSeverity::Warning);

    foreach (['drive', 'off'] as $scope) {
        config(['vaulter-media.dedup.scope' => $scope]);
        expect(mediaFindings('media.dedup.scope')[0]->code)->toBe('media.dedup.scope_ok');
    }
});

it('makes no write to the database', function (): void {
    Storage::fake('local');
    $statements = [];
    DB::listen(static function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    app(DoctorRunner::class)->run(new DoctorContext(production: false, probeStorage: false, sampleLimit: 10, now: CarbonImmutable::now('UTC')), ['media']);

    expect(array_filter($statements, static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|create|alter|drop)\b/i', $sql) === 1))->toBe([]);
});

it('contributes the disk and dedup-scope checks as the drive and keeps them with the drive module (D33)', function (): void {
    $keys = array_map(static fn ($check): string => $check->key(), app(DoctorRegistry::class)->all());
    $drive = (new ReflectionClassConstant(DriveModule::class, 'DOCTOR_CHECKS'))->getValue();
    $media = new ReflectionMethod(MediaModule::class, 'register');

    expect($keys)->toContain('media.disk.probe', 'media.dedup.scope')
        ->and($drive)->toHaveKeys(['media.disk.probe', 'media.dedup.scope'])
        ->and($drive['media.disk.probe'])->toBe(DiskProbeCheck::class)
        ->and($drive['media.dedup.scope'])->toBe(DedupScopeCheck::class)
        ->and(file_get_contents((string) $media->getFileName()))->not->toContain('DoctorCheck');
});

it('lists the media default disk and each distinct profile disk once', function (): void {
    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key('default'));
    $registry->register((new Profile)->key('second')->disk('beta'));
    $registry->register((new Profile)->key('third')->disk('beta'));
    config()->set('vaulter-media.disk', 'alpha');

    expect(DiskSet::of(app(MediaConfig::class), $registry))->toBe(['alpha', 'beta']);
});
