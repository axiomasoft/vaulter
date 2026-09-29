<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Vaulter\Load\Console\MemoryFixtureSupport;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.storage.default_disk', 'local');
    config()->set('filesystems.disks.local', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/disks/local'),
        'throw' => false,
    ]);
});

it('streams an exact-size fixture and bounded object checksum', function (): void {
    $runPrefix = 'test-run-'.uniqid('', true);
    $fixtureId = 'fixture-5m';
    $sizeBytes = 64 * 1024;

    expect(Artisan::call('load:memory-fixtures', [
        'action' => 'prepare',
        '--fixture-id' => $fixtureId,
        '--run-prefix' => $runPrefix,
        '--size' => "{$sizeBytes}B",
    ]))->toBe(0);

    $manifestPath = MemoryFixtureSupport::fixtureDirectory($runPrefix, $fixtureId).'/manifest.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['size_bytes'])->toBe($sizeBytes)
        ->and(filesize($manifest['payload_path']))->toBe($sizeBytes);

    expect(Artisan::call('load:memory-fixtures', [
        'action' => 'probe-object',
        '--fixture-id' => $fixtureId,
        '--run-prefix' => $runPrefix,
    ]))->toBe(0);

    expect(Artisan::call('load:memory-fixtures', [
        'action' => 'prepare',
        '--fixture-id' => $fixtureId,
        '--run-prefix' => $runPrefix,
        '--size' => "{$sizeBytes}B",
    ]))->toBe(0)
        ->and(filemtime($manifestPath))->toBeLessThanOrEqual(time());

    expect(Artisan::call('load:memory-fixtures', [
        'action' => 'cleanup',
        '--run-prefix' => $runPrefix,
    ]))->toBe(0)
        ->and(is_dir(MemoryFixtureSupport::fixtureDirectory($runPrefix, $fixtureId)))->toBeFalse();
});

it('stages through Sha256ContentStore without exceeding the CAS ceiling on a small fixture', function (): void {
    $runPrefix = 'cas-'.uniqid('', true);
    $fixtureId = 'cas-probe';
    $sizeBytes = 256 * 1024;

    Artisan::call('load:memory-fixtures', [
        'action' => 'prepare',
        '--fixture-id' => $fixtureId,
        '--run-prefix' => $runPrefix,
        '--size' => "{$sizeBytes}B",
    ]);

    $exit = Artisan::call('load:memory-probe-cas-stage', [
        '--fixture-id' => $fixtureId,
        '--run-prefix' => $runPrefix,
        '--ceiling-mib' => 64,
    ]);

    expect($exit)->toBe(0);

    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($output['within_ceiling'])->toBeTrue()
        ->and($output['stage_duration_seconds'])->toBeFloat()->toBeGreaterThan(0)
        ->and($output['checksum_match'])->toBeTrue();

    Artisan::call('load:memory-fixtures', [
        'action' => 'cleanup',
        '--run-prefix' => $runPrefix,
    ]);
});

it('does not use whole-file read helpers in P4.2 console commands', function (): void {
    $files = [
        dirname(__DIR__, 2).'/app/Console/PrepareMemoryFixturesCommand.php',
        dirname(__DIR__, 2).'/app/Console/ProbeCasStageMemoryCommand.php',
        dirname(__DIR__, 2).'/app/Console/MemoryFixtureSupport.php',
    ];

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->not->toMatch('/file_get_contents\s*\(/')
            ->and($contents)->not->toMatch('/stream_get_contents\s*\(/')
            ->and($contents)->not->toMatch('/Storage::get\s*\(/');
    }
});
