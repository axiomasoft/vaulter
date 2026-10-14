<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Content\ScanInput;
use Vaulter\Content\ScanResult;
use Vaulter\Contracts\Cas\ContentScanner;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Doctor\DoctorRegistry;
use Vaulter\Doctor\DoctorRunner;
use Vaulter\Doctor\DoctorSeverity;
use Vaulter\Drive\Actions\Files\IngestFileAction;
use Vaulter\Drive\Content\ScanBackfillReconciler;
use Vaulter\Drive\Content\ScanPolicyResolver;
use Vaulter\Drive\Data\Files\IngestFileInput;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Enums\Cas\ScanVerdict;
use Vaulter\Kernel;
use Vaulter\Operations\DriveContext;
use Vaulter\Testing\FakeContentScanner;
use Vaulter\Tests\Drive\DriveTestHelpers;

// The fixtures drive Vaulter's own actions directly; its entry points open the write scope, these calls do not.
beforeEach(fn () => DriveTestHelpers::insideOperation());

/** @return list<DoctorFinding> */
function scanFindings(string $key, bool $production = false, int $sample = 1000): array
{
    $report = app(DoctorRunner::class)->run(new DoctorContext(production: $production, probeStorage: false, sampleLimit: $sample, now: CarbonImmutable::now('UTC')), [$key]);

    return $report->checks[0]->findings;
}

function scanScanner(string $key = 'fixture', string $version = '1'): void
{
    UploadTestHelpers::scanner(new class($key, $version) implements ContentScanner
    {
        public function __construct(private readonly string $key, private readonly string $version) {}

        public function key(): string
        {
            return $this->key;
        }

        public function version(): string
        {
            return $this->version;
        }

        public function scan(ScanInput $input): ScanResult
        {
            return new ScanResult(ScanVerdict::Clean);
        }
    });
}

/** A drive with one file, a scan-all policy activated and its backfill materialized into one obligation. */
function scanFixture(): array
{
    Storage::fake('local');
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    $drive = UploadTestHelpers::storage();
    $context = DriveContext::system($drive, 'fixture');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $stream = UploadTestHelpers::streamFor('payload');
    app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'a.txt', 'text/plain'));
    fclose($stream);
    app(ScanBackfillReconciler::class)->expand(10);
    $work = app(VaulterConfig::class)->model('scan_obligation')::query()->firstOrFail();

    return [$drive, $work];
}

function scanTable(string $entity): string
{
    return app(DatabaseConfig::class)->table($entity);
}

it('needs no scanner while every scan policy is off', function (): void {
    $finding = scanFindings('drive.scan.scanner')[0];

    expect($finding->code)->toBe('drive.scan.scanner_not_required')->and($finding->severity)->toBe(DoctorSeverity::Ok);
});

it('reports an enabled scan policy without a bound scanner as an error in both modes', function (): void {
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');

    expect(array_map(static fn (DoctorFinding $finding): string => $finding->code, scanFindings('drive.scan.scanner')))->toBe(['drive.scan.scanner_missing'])
        ->and(scanFindings('drive.scan.scanner')[0]->severity)->toBe(DoctorSeverity::Error)
        ->and(scanFindings('drive.scan.scanner', production: true)[0]->severity)->toBe(DoctorSeverity::Error);
});

it('accepts a bound scanner whose identity is the configured one', function (): void {
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    scanScanner();

    expect(scanFindings('drive.scan.scanner')[0]->code)->toBe('drive.scan.scanner_ok');
});

it('reports a scanner identity the policy does not name', function (): void {
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    scanScanner(version: '2');

    expect(scanFindings('drive.scan.scanner')[0]->code)->toBe('drive.scan.identity_mismatch');

    config()->set('vaulter.scan.scanner_key', null);
    config()->set('vaulter.scan.scanner_version', null);
    expect(array_unique(array_map(static fn (DoctorFinding $finding): string => $finding->code, scanFindings('drive.scan.scanner'))))->toBe(['drive.scan.identity_missing']);
});

it('reports a scanner that cannot be built without quoting its message', function (): void {
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    config()->set('vaulter.scan.scanner', FakeContentScanner::class);
    app()->bind(ContentScanner::class, static fn (): never => throw new RuntimeException('api key=hunter2'));

    $finding = scanFindings('drive.scan.scanner')[0];

    expect($finding->code)->toBe('drive.scan.scanner_unresolvable')->and(json_encode($finding))->not->toContain('hunter2');
});

it('finds a drive whose policy cutover is pending, an error in production', function (): void {
    [$drive] = scanFixture();
    expect(scanFindings('drive.scan.policy')[0]->code)->toBe('drive.scan.policy_current');

    config()->set('vaulter.scan.scanner_version', '2');
    $finding = scanFindings('drive.scan.policy')[0];

    expect($finding->code)->toBe('drive.scan.policy_cutover_pending')
        ->and($finding->severity)->toBe(DoctorSeverity::Warning)
        ->and($finding->hint)->toContain('vaulter:scan:rescan --activate-policy')
        ->and(scanFindings('drive.scan.policy', production: true)[0]->severity)->toBe(DoctorSeverity::Error);

    DB::table(scanTable('scan_policy_states'))->where('drive_id', $drive->getKey())->delete();
    expect(scanFindings('drive.scan.policy')[0]->code)->toBe('drive.scan.policy_cutover_pending');
});

it('finds a drive that has no activation state under an enabled policy', function (): void {
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    UploadTestHelpers::storage();

    expect(scanFindings('drive.scan.policy')[0]->code)->toBe('drive.scan.policy_cutover_pending');
});

it('finds a backfill that made no progress for a day', function (): void {
    scanFixture();
    DB::table(scanTable('scan_backfill_runs'))->update(['state' => 'pending', 'updated_at' => now('UTC')->subHours(30)]);

    $codes = array_map(static fn (DoctorFinding $finding): string => $finding->code, scanFindings('drive.scan.policy'));

    expect($codes)->toBe(['drive.scan.backfill_stalled']);

    DB::table(scanTable('scan_backfill_runs'))->update(['state' => 'completed']);
    expect(scanFindings('drive.scan.policy')[0]->code)->toBe('drive.scan.policy_current');
});

it('finds an expired lease and overdue work as stuck, errors in production', function (): void {
    [, $work] = scanFixture();
    expect(scanFindings('drive.scan.obligations')[0]->code)->toBe('drive.scan.obligations_ok');

    DB::table(scanTable('scan_obligations'))->where('id', $work->getKey())->update([
        'state' => 'leased', 'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now('UTC')->subMinutes(5),
    ]);
    $finding = scanFindings('drive.scan.obligations')[0];
    expect($finding->code)->toBe('drive.scan.obligations_stuck')
        ->and($finding->severity)->toBe(DoctorSeverity::Warning)
        ->and($finding->evidence)->toBe(['expired_leases' => 1, 'overdue' => 0])
        ->and(scanFindings('drive.scan.obligations', production: true)[0]->severity)->toBe(DoctorSeverity::Error);

    DB::table(scanTable('scan_obligations'))->where('id', $work->getKey())->update([
        'state' => 'retry_wait', 'lease_token' => null, 'lease_expires_at' => null, 'available_at' => now('UTC')->subHour(),
    ]);
    expect(scanFindings('drive.scan.obligations')[0]->evidence)->toBe(['expired_leases' => 0, 'overdue' => 1]);

    DB::table(scanTable('scan_obligations'))->where('id', $work->getKey())->update(['available_at' => now('UTC')->subMinutes(10)]);
    expect(scanFindings('drive.scan.obligations')[0]->code)->toBe('drive.scan.obligations_ok');
});

it('counts work that settled without a verdict per reason, as a warning also in production', function (): void {
    [, $work] = scanFixture();
    DB::table(scanTable('scan_obligations'))->where('id', $work->getKey())->update(['state' => 'settled', 'outcome' => 'failed', 'reason' => 'too_large']);

    $finding = scanFindings('drive.scan.obligations', production: true)[0];

    expect($finding->code)->toBe('drive.scan.obligations_unscanned')
        ->and($finding->severity)->toBe(DoctorSeverity::Warning)
        ->and($finding->evidence)->toBe(['too_large' => 1])
        ->and($finding->hint)->toContain('vaulter:scan:reconcile --all-tenants --dry-run');

    DB::table(scanTable('scan_obligations'))->where('id', $work->getKey())->update(['outcome' => 'clean', 'reason' => null]);
    expect(scanFindings('drive.scan.obligations')[0]->code)->toBe('drive.scan.obligations_ok');
});

it('finds scan rows whose drive is missing or belongs to another tenant', function (): void {
    [$drive, $work] = scanFixture();
    expect(scanFindings('drive.scan.scope')[0]->code)->toBe('drive.scan.scope_ok');

    DB::table(scanTable('scan_obligations'))->where('id', $work->getKey())->update(['tenant_id' => 'other']);
    DB::table(scanTable('scan_backfill_runs'))->where('drive_id', $drive->getKey())->update(['tenant_id' => 'other']);
    $finding = scanFindings('drive.scan.scope')[0];

    expect($finding->code)->toBe('drive.scan.scope_invalid')
        ->and($finding->severity)->toBe(DoctorSeverity::Error)
        ->and($finding->evidence)->toHaveKey('obligations_without_drive_scope', 1)
        ->and($finding->evidence)->toHaveKey('backfill_runs_without_drive_scope', 1)
        ->and(scanFindings('drive.scan.scope', production: true)[0]->severity)->toBe(DoctorSeverity::Error);
});

it('finds an unavailable content scope under a policy that scans everything', function (): void {
    [, $work] = scanFixture();
    DB::table(scanTable('scan_obligations'))->where('id', $work->getKey())->update(['scope_key' => 'unavailable']);

    expect(scanFindings('drive.scan.scope')[0]->evidence)->toHaveKey('unavailable_scope_under_required_policy', 1);

    config()->set('vaulter.scan.mode', 'off');
    expect(scanFindings('drive.scan.scope')[0]->code)->toBe('drive.scan.scope_ok');
});

it('runs the scan checks with webhooks disabled', function (): void {
    [, $work] = scanFixture();
    expect(app(Kernel::class)->isActive('webhooks'))->toBeFalse();
    DB::table(scanTable('scan_obligations'))->where('id', $work->getKey())->update([
        'state' => 'leased', 'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now('UTC')->subMinutes(5),
    ]);

    expect(scanFindings('drive.scan.obligations')[0]->code)->toBe('drive.scan.obligations_stuck')
        // The inactive webhooks module contributes no delivery check at all.
        ->and(app(DoctorRegistry::class)->unmatched(['core.webhooks.deliveries']))->toBe(['core.webhooks.deliveries']);
});

it('limits the scope sample', function (): void {
    [$drive] = scanFixture();
    $table = scanTable('scan_backfill_runs');
    DB::table($table)->update(['tenant_id' => 'other']);

    expect(scanFindings('drive.scan.scope', sample: 1)[0]->evidence)->toHaveKey('backfill_runs_without_drive_scope', 1);
});
