<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Vaulter\Load\Console\ChaosFixtureSupport;

it('prepares an idempotent chaos fixture and cleans up only that id', function (): void {
    $runPrefix = 'p7-'.bin2hex(random_bytes(4));
    $fixtureId = 'tier-s';

    expect(Artisan::call('load:chaos-fixtures', [
        'action' => 'prepare',
        '--fixture-id' => $fixtureId,
        '--run-prefix' => $runPrefix,
    ]))->toBe(0);

    $directory = ChaosFixtureSupport::fixtureDirectory($runPrefix, $fixtureId);
    $manifestPath = $directory.'/manifest.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $mtime = filemtime($manifestPath);

    expect($manifest['aws_upload_id'])->toBeNull()
        ->and($manifest['multipart_cleanup'])->toBe('flysystem-part-keys')
        ->and($manifest['uploads']['s3_multipart']['part_keys'])->toHaveCount(2)
        ->and($manifest['uploads']['s3_multipart']['part_keys'][0])->toStartWith('tmp/uploads/')
        ->and($manifest['cas']['sha256'])->toHaveLength(64)
        ->and($manifest['cas']['tmpdir'])->toBe('/var/tmp/vaulter-chaos')
        ->and($manifest['outbox']['event_id'])->not->toBe('')
        ->and($manifest['barriers']['sleep_is_not_barrier'])->toBeTrue()
        ->and($manifest['performance_verdict'])->toBeFalse();

    expect(Artisan::call('load:chaos-fixtures', [
        'action' => 'prepare',
        '--fixture-id' => $fixtureId,
        '--run-prefix' => $runPrefix,
    ]))->toBe(0)
        ->and(filemtime($manifestPath))->toBe($mtime);

    expect(Artisan::call('load:chaos-fixtures', [
        'action' => 'verify',
        '--fixture-id' => $fixtureId,
        '--run-prefix' => $runPrefix,
    ]))->toBe(0);

    expect(Artisan::call('load:chaos-fixtures', [
        'action' => 'prepare',
        '--fixture-id' => 'keep-me',
        '--run-prefix' => $runPrefix,
    ]))->toBe(0);

    expect(Artisan::call('load:chaos-fixtures', [
        'action' => 'cleanup',
        '--fixture-id' => $fixtureId,
        '--run-prefix' => $runPrefix,
    ]))->toBe(0)
        ->and(is_dir($directory))->toBeFalse()
        ->and(is_dir(ChaosFixtureSupport::fixtureDirectory($runPrefix, 'keep-me')))->toBeTrue();

    expect(Artisan::call('load:chaos-fixtures', [
        'action' => 'cleanup',
        '--run-prefix' => $runPrefix,
    ]))->toBe(0)
        ->and(is_dir(ChaosFixtureSupport::fixturesRoot().'/'.ChaosFixtureSupport::sanitizeToken($runPrefix)))->toBeFalse();
});
