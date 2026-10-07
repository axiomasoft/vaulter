<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Vaulter\Actions\Content\ClaimScanWorkAction;
use Vaulter\Actions\Content\RequestScanWorkAction;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Content\ContentAvailabilityGuard;
use Vaulter\Content\ScanInput;
use Vaulter\Content\ScanResult;
use Vaulter\Contracts\Cas\ContentScanner;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Files\IngestFileAction;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Content\ScanBackfillReconciler;
use Vaulter\Drive\Content\ScanPolicyResolver;
use Vaulter\Drive\Content\ScanWorkReconciler;
use Vaulter\Drive\Data\Files\IngestFileInput;
use Vaulter\Drive\Jobs\Uploads\ScanFileRevision;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Enums\Cas\ScanVerdict;
use Vaulter\Exceptions\Files\ContentUnavailableException;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Content\ScanObligationRepository;

it('SEC-BACKFILL materializes bounded durable activation work and checkpoints it', function (): void {
    Storage::fake('local');
    $drive = UploadTestHelpers::storage();
    $context = OperationContext::system($drive, 'fixture');
    foreach (['a.txt', 'b.txt'] as $name) {
        $stream = UploadTestHelpers::streamFor($name);
        app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, $name, 'text/plain'));
        fclose($stream);
    }
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $run = app(VaulterConfig::class)->model('scan_backfill_run');
    $work = app(VaulterConfig::class)->model('scan_obligation');
    expect($run::query()->count())->toBe(1)->and($work::query()->count())->toBe(0);
    $result = app(ScanBackfillReconciler::class)->expand(1);
    expect($result['backfill_materialized'])->toBe(1)->and($work::query()->count())->toBe(1)
        ->and($run::query()->first()->last_admission_sequence)->toBe(1);
    app(ScanBackfillReconciler::class)->expand(1);
    expect($work::query()->count())->toBe(2);
    app(ScanBackfillReconciler::class)->expand(1);
    expect($run::query()->first()->state)->toBe('completed');
});

it('SEC-RECOVERY-bounds rejects contradictory command scope and dry run creates no work', function (): void {
    $this->artisan('vaulter:scan-reconcile', ['--limit' => 0])->assertExitCode(2);
    $this->artisan('vaulter:scan-reconcile', ['--tenant' => 'a', '--all-tenants' => true])->assertExitCode(2);
    $this->artisan('vaulter:scan-rescan', ['--request-id' => 'not-a-uuid', '--dry-run' => true])->assertExitCode(2);
    $this->artisan('vaulter:scan-reconcile', ['--dry-run' => true])->assertExitCode(0);
    $class = app(VaulterConfig::class)->model('scan_obligation');
    expect($class::query()->count())->toBe(0);
});

it('SEC-R09 committed work survives missing delivery and reconciles publication SEC-R10', function (): void {
    Storage::fake('local');
    Bus::fake();
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    $drive = UploadTestHelpers::storage();
    $context = OperationContext::system($drive, 'fixture');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $stream = UploadTestHelpers::streamFor('durable');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    $revision = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    $work = $revision->scanObligations()->firstOrFail();
    expect($work->state)->toBe('pending')->and($work->attempt)->toBe(0);
    Bus::fake();
    $result = app(ScanWorkReconciler::class)->reconcile();
    expect($result['published'])->toBe(1);
    Bus::assertDispatched(ScanFileRevision::class, 1);
    $repository = app(ScanObligationRepository::class);
    $attempt = app(ClaimScanWorkAction::class)->execute($work->id, $work->generation, new DateTimeImmutable('now'));
    expect($attempt)->not->toBeNull()->and(app(ClaimScanWorkAction::class)->execute($work->id, $work->generation, new DateTimeImmutable('now')))->toBeNull();
    expect($repository->settle($attempt, new ScanResult(ScanVerdict::Clean)))->toBeTrue()
        ->and($repository->settle($attempt, new ScanResult(ScanVerdict::Clean)))->toBeFalse()
        ->and($revision->scanEvidence()->count())->toBe(1);
});

it('SEC-R13 outer rollback removes admission work revision and queued publication', function (): void {
    Storage::fake('local');
    Bus::fake();
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    $drive = UploadTestHelpers::storage();
    $context = OperationContext::system($drive, 'fixture');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $stream = UploadTestHelpers::streamFor('rollback');
    try {
        expect(fn () => app(VaulterDatabase::class)->transaction(function () use ($context, $drive, $stream): void {
            app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
            throw new RuntimeException('fixture rollback');
        }))->toThrow(RuntimeException::class);
    } finally {
        fclose($stream);
    }
    $class = app(VaulterConfig::class)->model('scan_obligation');
    expect($class::query()->count())->toBe(0)->and(FileRevision::query()->count())->toBe(0)
        ->and($drive->refresh()->scan_admission_sequence)->toBe(0);
    Bus::assertNothingDispatched();
});

it('SEC-RECOVERY ledger deduplicates old requests and terminal exhaustion requires explicit rescan', function (): void {
    Storage::fake('local');
    Bus::fake();
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    $drive = UploadTestHelpers::storage();
    $context = OperationContext::system($drive, 'fixture');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $stream = UploadTestHelpers::streamFor('dedup');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    $revision = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    $spec = app(ScanPolicyResolver::class)->forDrive($drive)[0];
    $repo = app(ScanObligationRepository::class);
    $oldKey = (string) Str::uuid();
    $newKey = (string) Str::uuid();
    $request = fn (string $key) => app(VaulterDatabase::class)->transaction(fn () => app(RequestScanWorkAction::class)->requestRescan($revision, $spec, $key, $context));
    $first = $request($oldKey);
    $second = $request($newKey);
    $replayed = $request($oldKey);
    expect($second->generation)->toBe($first->generation + 1)->and($replayed->generation)->toBe($second->generation);
    $second->forceFill(['attempt' => 4])->save();
    $attempt = app(ClaimScanWorkAction::class)->execute($second->id, $second->generation, new DateTimeImmutable('now'));
    $second->refresh()->forceFill(['lease_expires_at' => now()->subSecond()])->save();
    expect($repo->recover($second, new DateTimeImmutable('now')))->toBeTrue()
        ->and($second->refresh()->state)->toBe('settled')->and($second->reason)->toBe('attempts_exhausted')
        ->and($repo->settle($attempt, new ScanResult(ScanVerdict::Clean)))->toBeFalse();
    $next = $request((string) Str::uuid());
    expect($next->state)->toBe('pending')->and($next->attempt)->toBe(0);
});

it('SEC-RECOVERY-bounds signed cursors resume one revision and reject signature changes', function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.retry_after', 180);
    Storage::fake('local');
    Bus::fake();
    app()->instance(ContentScanner::class, new class implements ContentScanner
    {
        public function key(): string
        {
            return 'fixture';
        }

        public function version(): string
        {
            return '1';
        }

        public function scan(ScanInput $input): ScanResult
        {
            return new ScanResult(ScanVerdict::Clean);
        }
    });
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    $drive = UploadTestHelpers::storage();
    $context = OperationContext::system($drive, 'fixture');
    foreach (['a', 'b'] as $name) {
        $stream = UploadTestHelpers::streamFor($name);
        app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, $name.'.txt', 'text/plain'));
        fclose($stream);
    }
    $options = ['--drive' => (string) $drive->id, '--request-id' => (string) Str::uuid(), '--reason' => 'fixture', '--limit' => 1, '--json' => true];
    expect(Artisan::call('vaulter:scan-rescan', $options))->toBe(0);
    $first = json_decode(Artisan::output(), true);
    expect($first['created'])->toBe(1)->and($first['next_cursor'])->not->toBeNull();
    $cursor = $first['next_cursor'];
    $tampered = substr($cursor, 0, -1).(str_ends_with($cursor, '0') ? '1' : '0');
    expect(Artisan::call('vaulter:scan-rescan', [...$options, '--after' => $tampered]))->toBe(2);
    $class = app(VaulterConfig::class)->model('scan_obligation');
    expect($class::query()->count())->toBe(1);
    expect(Artisan::call('vaulter:scan-rescan', [...$options, '--after' => $cursor]))->toBe(0);
    expect($class::query()->count())->toBe(2);
    expect(Artisan::call('vaulter:scan-rescan', [...$options, '--after' => $cursor]))->toBe(0);
    expect($class::query()->count())->toBe(2);
});

it('SEC-BACKFILL new content preparation retains legacy work at same spec all content cutover', function (): void {
    Storage::fake('local');
    Bus::fake();
    $drive = UploadTestHelpers::storage();
    $context = OperationContext::system($drive, 'fixture');
    $stream = UploadTestHelpers::streamFor('pre-cutover');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    $revision = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    config()->set('vaulter.scan.mode', 'new_content_only');
    config()->set('vaulter.scan.rollout_key', 'first');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    app(ContentAvailabilityGuard::class)->assertAvailable($context, $node, $revision);
    app(ScanBackfillReconciler::class)->expand();
    app(ScanBackfillReconciler::class)->expand();
    expect($revision->scanObligations()->count())->toBe(1);
    $runClass = app(VaulterConfig::class)->model('scan_backfill_run');
    $before = $runClass::query()->firstOrFail();
    expect($before->state)->toBe('completed');
    config()->set('vaulter.scan.mode', 'all_content');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    expect($runClass::query()->firstOrFail()->last_revision_id)->toBe($before->last_revision_id)
        ->and($revision->scanObligations()->count())->toBe(1);
    expect(fn () => app(ContentAvailabilityGuard::class)->assertAvailable($context, $node, $revision))
        ->toThrow(ContentUnavailableException::class);
});

it('SEC-POLICY-superseded replacement activation terminates old work while configuration mismatch stalls', function (): void {
    Storage::fake('local');
    Bus::fake();
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    $drive = UploadTestHelpers::storage();
    $context = OperationContext::system($drive, 'fixture');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $stream = UploadTestHelpers::streamFor('versions');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    $row = $node->file()->firstOrFail()->currentRevision()->firstOrFail()->scanObligations()->firstOrFail();
    config()->set('vaulter.scan.scanner_version', '2');
    $result = app(ScanWorkReconciler::class)->reconcile();
    expect($result['stalled_configuration'])->toBe(1)->and($row->refresh()->state)->toBe('pending');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $result = app(ScanWorkReconciler::class)->reconcile();
    expect($row->refresh()->state)->toBe('settled')->and($row->reason)->toBe('superseded_policy')
        ->and($row->lease_token)->toBeNull();
    app(ScanBackfillReconciler::class)->expand();
    expect($node->file()->firstOrFail()->currentRevision()->firstOrFail()->scanObligations()->count())->toBe(2);
});

it('SEC-RECOVERY-bounds optional off rescan rejects sync before SQL or publication', function (): void {
    Bus::fake();
    app()->instance(ContentScanner::class, new class implements ContentScanner
    {
        public function key(): string
        {
            return 'fixture';
        }

        public function version(): string
        {
            return '1';
        }

        public function scan(ScanInput $input): ScanResult
        {
            throw new RuntimeException('scanner must never run');
        }
    });
    config()->set('vaulter.scan.mode', 'off');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    config()->set('queue.default', 'sync');
    $queries = 0;
    app(VaulterDatabase::class)->connection()->listen(function () use (&$queries): void {
        $queries++;
    });
    expect(Artisan::call('vaulter:scan-rescan', ['--reason' => 'fixture', '--json' => true]))->toBe(2)
        ->and($queries)->toBe(0)->and(Artisan::output())->toContain('asynchronous queue');
    Bus::assertNothingDispatched();
});

it('SEC-RECOVERY-UTC selects due work and expired leases by UTC in either app timezone', function (string $timezone): void {
    Storage::fake('local');
    Bus::fake();
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    $originalTimezone = date_default_timezone_get();
    config()->set('app.timezone', $timezone);
    date_default_timezone_set($timezone);
    try {
        $drive = UploadTestHelpers::storage();
        $context = OperationContext::system($drive, 'UTC recovery fixture');
        app(ScanPolicyResolver::class)->initialize($drive, $context);
        $stream = UploadTestHelpers::streamFor('UTC recovery');
        $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'utc.txt', 'text/plain'));
        fclose($stream);
        $work = $node->file()->firstOrFail()->currentRevision()->firstOrFail()->scanObligations()->firstOrFail();
        $work->forceFill(['last_published_at' => null, 'available_at' => now('UTC')->subSecond()])->save();
        expect(app(ScanWorkReconciler::class)->reconcile()['published'])->toBe(1)
            ->and(app(ScanWorkReconciler::class)->reconcile()['scanned'])->toBe(0);
        $attempt = app(ClaimScanWorkAction::class)->execute($work->id, $work->generation, new DateTimeImmutable('now'));
        expect($attempt)->not->toBeNull()->and(app(ScanWorkReconciler::class)->reconcile()['scanned'])->toBe(0);
        $work->refresh()->forceFill(['lease_expires_at' => now('UTC')->subSecond(), 'last_published_at' => null])->save();
        expect(app(ScanWorkReconciler::class)->reconcile()['published'])->toBe(1)
            ->and($work->refresh()->state)->toBe('retry_wait')->and($work->lease_token)->toBeNull();
        $work->forceFill(['last_published_at' => null, 'available_at' => now('UTC')->subSecond()])->save();
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('broker unavailable'));
        $publisher = new ScanWorkReconciler(database: app(VaulterDatabase::class), config: app(VaulterConfig::class), driveConfig: app(DriveConfig::class), work: app(ScanObligationRepository::class), policies: app(ScanPolicyResolver::class), bus: $bus);
        $before = now('UTC')->getTimestamp();
        $publisher->reconcile();
        $available = $work->refresh()->available_at->getTimestamp();
        $cooldown = app(DriveConfig::class)->scanWork('publish_cooldown_seconds');
        expect($available)->toBeGreaterThanOrEqual($before + $cooldown)
            ->toBeLessThanOrEqual(now('UTC')->getTimestamp() + $cooldown);
    } finally {
        date_default_timezone_set($originalTimezone);
    }
})->with(['ahead' => 'Europe/Moscow', 'behind' => 'America/New_York']);

it('SEC-C03-db-clock decides which scan work is due regardless of application clock skew', function (): void {
    Storage::fake('local');
    Bus::fake();
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    $drive = UploadTestHelpers::storage();
    $context = OperationContext::system($drive, 'clock skew fixture');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $stream = UploadTestHelpers::streamFor('clock skew');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'skew.txt', 'text/plain'));
    fclose($stream);
    $work = $node->file()->firstOrFail()->currentRevision()->firstOrFail()->scanObligations()->firstOrFail();
    $work->forceFill(['last_published_at' => null, 'available_at' => now('UTC')->addMinutes(5)])->save();

    // The application clock runs ten minutes ahead; the database clock still says "not due yet".
    $this->travel(10)->minutes();
    try {
        expect(app(ScanWorkReconciler::class)->reconcile()['scanned'])->toBe(0)
            ->and(app(ClaimScanWorkAction::class)->execute($work->id, $work->generation, app(ScanObligationRepository::class)->now()))->toBeNull();
    } finally {
        $this->travelBack();
    }
});

it('SEC-C03-db-clock-utc reports naive UTC whatever timezone the database session runs in', function (): void {
    $connection = app(VaulterDatabase::class)->connection();
    $pgsql = $connection->getDriverName() === 'pgsql';
    if ($pgsql) {
        $connection->statement("SET TIME ZONE 'Asia/Tokyo'");
    }
    try {
        $now = app(ScanObligationRepository::class)->now();

        // The wall clock is what Laravel binds into the naive-UTC scan columns.
        expect($now->getOffset())->toBe(0)
            ->and(abs(strtotime($now->format('Y-m-d H:i:s').' UTC') - time()))->toBeLessThan(30);
    } finally {
        if ($pgsql) {
            $connection->statement('RESET TIME ZONE');
        }
    }
});

it('SEC-C03-db-clock-live keeps advancing inside an open transaction', function (): void {
    $work = app(ScanObligationRepository::class);

    app(VaulterDatabase::class)->transaction(function () use ($work): void {
        $first = $work->now();
        usleep(1_100_000);

        // A transaction-frozen clock would age every lease taken after a lock wait.
        expect($work->now()->getTimestamp())->toBeGreaterThan($first->getTimestamp());
    });
});

it('SEC-RECOVERY-remaining-due counts all still-due work whatever the batch limit', function (): void {
    Storage::fake('local');
    Bus::fake();
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    $drive = UploadTestHelpers::storage();
    $context = OperationContext::system($drive, 'remaining fixture');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    foreach (['a', 'b', 'c'] as $name) {
        $stream = UploadTestHelpers::streamFor($name);
        app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, $name.'.txt', 'text/plain'));
        fclose($stream);
    }
    $class = app(VaulterConfig::class)->model('scan_obligation');
    $class::query()->update(['last_published_at' => null, 'available_at' => now('UTC')->subSecond()]);

    // The count must not inherit the batch's ORDER BY/LIMIT (PostgreSQL rejects ORDER BY on a bare aggregate).
    $dry = app(ScanWorkReconciler::class)->reconcile(limit: 1, dryRun: true);
    $live = app(ScanWorkReconciler::class)->reconcile(limit: 1);

    expect($dry['scanned'])->toBe(1)->and($dry['remaining_due'])->toBe(3)
        ->and($live['published'])->toBe(1)->and($live['remaining_due'])->toBe(2);
});
