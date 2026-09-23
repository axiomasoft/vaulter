<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Vaulter\Contracts\Webhooks\OutboundUrlGuard;
use Vaulter\Exceptions\Webhooks\BlockedOutboundUrlException;
use Vaulter\Load\Console\QueueFixtureSupport;
use Vaulter\Load\Webhooks\LoadSinkOutboundUrlGuard;
use Vaulter\Support\Config;

require_once dirname(__DIR__, 2).'/webhook-sink/router.php';

it('records sink profiles without signature or body and bounds hang', function (): void {
    $ledger = tempnam(sys_get_temp_dir(), 'sink');
    expect($ledger)->toBeString();

    putenv('LOAD_SINK_LEDGER='.$ledger);
    putenv('LOAD_SINK_DELAY_MS=0');
    putenv('LOAD_SINK_HANG_SECONDS=0');

    expect(sink_client_allowed('127.0.0.1'))->toBeTrue()
        ->and(sink_client_allowed('10.1.2.3'))->toBeTrue()
        ->and(sink_client_allowed('172.18.0.4'))->toBeTrue()
        ->and(sink_client_allowed('8.8.8.8'))->toBeFalse()
        ->and(sink_client_allowed('169.254.169.254'))->toBeFalse()
        ->and(sink_hang_seconds())->toBe(0)
        ->and(sink_delay_ms())->toBe(0);

    $profiles = [
        '/2xx/evt-ok?run_id=r&cell_id=c' => [204, '2xx', 'evt-ok'],
        '/500/evt-fail?run_id=r&cell_id=c' => [500, '500', 'evt-fail'],
        '/delay/evt-delay?run_id=r&cell_id=c' => [204, 'delay', 'evt-delay'],
        '/hang/evt-hang?run_id=r&cell_id=c' => [504, 'hang', 'evt-hang'],
    ];

    foreach ($profiles as $uri => [$status, $profile, $eventId]) {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['HTTP_X_VAULTER_SIGNATURE'] = 'do-not-store';
        ob_start();
        sink_dispatch();
        ob_end_clean();
        expect(http_response_code())->toBe($status);
    }

    $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
    $_SERVER['REQUEST_URI'] = '/2xx/evt-public';
    ob_start();
    sink_dispatch();
    ob_end_clean();
    expect(http_response_code())->toBe(403);

    $rows = array_map(
        static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", (string) file_get_contents($ledger)))),
    );

    expect($rows)->toHaveCount(4)
        ->and($rows[0]['profile'])->toBe('2xx')
        ->and($rows[1]['profile'])->toBe('500')
        ->and($rows[2]['profile'])->toBe('delay')
        ->and($rows[3]['profile'])->toBe('hang')
        ->and($rows[3]['event_id'])->toBe('evt-hang');

    foreach ($rows as $row) {
        expect(array_keys($row))->not->toContain('signature')
            ->and(array_keys($row))->not->toContain('body')
            ->and(json_encode($row))->not->toContain('do-not-store');
    }

    putenv('LOAD_SINK_HANG_SECONDS=90');
    expect(sink_hang_seconds())->toBe(30);
    putenv('LOAD_SINK_LEDGER');
    putenv('LOAD_SINK_DELAY_MS');
    putenv('LOAD_SINK_HANG_SECONDS');
    @unlink($ledger);
});

it('restricts outbound delivery to the controlled sink', function (): void {
    $guard = app(OutboundUrlGuard::class);

    expect($guard)->toBeInstanceOf(LoadSinkOutboundUrlGuard::class);

    $pinned = $guard->pin('http://127.0.0.1:8090/2xx/evt');
    expect($pinned->host)->toBe('127.0.0.1')
        ->and($pinned->ip)->toBe('127.0.0.1')
        ->and($pinned->port)->toBe(8090);

    expect(fn () => $guard->isAllowed('https://example.com/hook'))->toThrow(BlockedOutboundUrlException::class)
        ->and(fn () => $guard->pin('http://169.254.169.254/latest/meta-data'))->toThrow(BlockedOutboundUrlException::class)
        ->and(fn () => $guard->pin('http://10.0.0.8:8090/2xx'))->toThrow(BlockedOutboundUrlException::class)
        ->and(fn () => $guard->pin('http://127.0.0.1/2xx'))->toThrow(BlockedOutboundUrlException::class);
});

it('prepares an idempotent queue fixture and cleans up only that id', function (): void {
    $runPrefix = 'p6-'.bin2hex(random_bytes(4));
    $fixtureId = 'tier-s';

    expect(Artisan::call('load:queue-fixtures', [
        'action' => 'prepare',
        '--fixture-id' => $fixtureId,
        '--run-prefix' => $runPrefix,
    ]))->toBe(0);

    $directory = QueueFixtureSupport::fixtureDirectory($runPrefix, $fixtureId);
    $manifestPath = $directory.'/manifest.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $mtime = filemtime($manifestPath);

    expect($manifest['volumes']['outbox_rows'])->toBe(10_000)
        ->and($manifest['volumes']['due_rows'])->toBe(5_000)
        ->and($manifest['volumes']['endpoints'])->toBe(20)
        ->and($manifest['ingress'])->toBe(['1x' => 20, '5x' => 100, '10x' => 200])
        ->and($manifest['densities']['0']['subscribed_endpoints'])->toBe(0)
        ->and($manifest['densities']['1']['subscribed_endpoints'])->toBe(1)
        ->and($manifest['densities']['all']['subscribed_endpoints'])->toBe(20)
        ->and($manifest['state_machine']['backoff']['configured_backoff_seconds'])->toBe(Config::webhookBackoff(1))
        ->and($manifest['state_machine']['backoff']['recorded_before_force_due'])->toBeTrue()
        ->and($manifest['state_machine']['backoff']['elapsed_time_slo'])->toBeFalse()
        ->and($manifest['state_machine']['dead']['status'])->toBe('dead')
        ->and($manifest['state_machine']['dead']['attempts'])->toBe(Config::webhookMaxAttempts())
        ->and($manifest['state_machine']['disable']['is_active'])->toBeFalse()
        ->and($manifest['state_machine']['disable']['failure_count'])->toBe(Config::webhookDisableAfterFailures())
        ->and($manifest['duplicates']['concurrent']['shared_delivery'])->toBeTrue()
        ->and($manifest['duplicates']['serial']['proves_concurrent_exactly_once'])->toBeFalse()
        ->and($manifest['duplicates']['concurrent']['queue_uuids'])->not->toBe($manifest['duplicates']['serial']['queue_uuids'])
        ->and($manifest['probes'][1]['count'])->toBe(1000)
        ->and($manifest['probes'][3]['count'])->toBe(500_000)
        ->and($manifest['probes'][0]['snapshot_restore_before'])->toBeTrue()
        ->and($manifest['fanout_source'])->toBe('deliveries_per_jobs')
        ->and($manifest['performance_verdict'])->toBeFalse();

    expect(count(array_unique([
        ...$manifest['duplicates']['serial']['queue_uuids'],
        ...$manifest['duplicates']['concurrent']['queue_uuids'],
    ])))->toBe(4)
        ->and($manifest['duplicates']['serial']['delivery_id'])->toBe($manifest['duplicates']['concurrent']['delivery_id']);

    expect(Artisan::call('load:queue-fixtures', [
        'action' => 'prepare',
        '--fixture-id' => $fixtureId,
        '--run-prefix' => $runPrefix,
    ]))->toBe(0)
        ->and(filemtime($manifestPath))->toBe($mtime);

    expect(Artisan::call('load:queue-fixtures', [
        'action' => 'verify',
        '--fixture-id' => $fixtureId,
        '--run-prefix' => $runPrefix,
    ]))->toBe(0);

    expect(Artisan::call('load:queue-fixtures', [
        'action' => 'prepare',
        '--fixture-id' => 'keep-me',
        '--run-prefix' => $runPrefix,
    ]))->toBe(0);

    expect(Artisan::call('load:queue-fixtures', [
        'action' => 'cleanup',
        '--fixture-id' => $fixtureId,
        '--run-prefix' => $runPrefix,
    ]))->toBe(0)
        ->and(is_dir($directory))->toBeFalse()
        ->and(is_dir(QueueFixtureSupport::fixtureDirectory($runPrefix, 'keep-me')))->toBeTrue();

    expect(Artisan::call('load:queue-fixtures', [
        'action' => 'cleanup',
        '--run-prefix' => $runPrefix,
    ]))->toBe(0)
        ->and(is_dir(QueueFixtureSupport::fixturesRoot().'/'.QueueFixtureSupport::sanitizeToken($runPrefix)))->toBeFalse();
});

it('detects missing and extra delivery rows, jobs and sink receives', function (): void {
    $directory = sys_get_temp_dir().'/p6-integrity-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $bad = $directory.'/bad.json';
    $good = $directory.'/good.json';

    file_put_contents($bad, json_encode([
        'expected' => ['delivery_rows' => 1, 'queue_jobs' => 2, 'sink_receives' => 2],
        'actual' => ['delivery_rows' => 2, 'queue_jobs' => 1, 'sink_receives' => 2],
        'sink_fields' => ['event_id', 'body'],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($good, json_encode([
        'expected' => ['delivery_rows' => 1, 'queue_jobs' => 2, 'sink_receives' => 2],
        'actual' => ['delivery_rows' => 1, 'queue_jobs' => 2, 'sink_receives' => 2],
        'sink_fields' => ['event_id', 'profile', 'status'],
    ], JSON_THROW_ON_ERROR));

    expect(Artisan::call('load:queue-fixtures', [
        'action' => 'check-integrity',
        '--report' => $bad,
    ]))->toBe(1)
        ->and(Artisan::call('load:queue-fixtures', [
            'action' => 'check-integrity',
            '--report' => $good,
        ]))->toBe(0);

    @unlink($bad);
    @unlink($good);
    @rmdir($directory);
});
