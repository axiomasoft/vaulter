<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Vaulter\Configuration\ScanConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Content\ContentAvailabilityGuard;
use Vaulter\Content\ScanResult;
use Vaulter\Content\ScanSnapshot;
use Vaulter\Contracts\Profiles\CommentsFeature;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Files\IngestFileAction;
use Vaulter\Drive\Actions\Trash\RestoreNodeAction;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Content\ScanBackfillReconciler;
use Vaulter\Drive\Content\ScanPolicyResolver;
use Vaulter\Drive\Data\Files\IngestFileInput;
use Vaulter\Drive\Data\Trash\RestoreNodeInput;
use Vaulter\Drive\Providers\BindingsRegistrar;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Enums\Cas\ScanMode;
use Vaulter\Enums\Cas\ScanVerdict;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Exceptions\Files\ContentUnavailableException;
use Vaulter\Operations\OperationContext;
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfilePolicy;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Profiles\UploadPolicy;
use Vaulter\Repositories\Content\ScanObligationRepository;
use Vaulter\Repositories\Content\ScanPolicyStateRepository;

it('SEC-POLICY rejects enabled unactivated drives without allocating admission', function (): void {
    Storage::fake('local');
    $drive = UploadTestHelpers::storage();
    config()->set('vaulter.scan.mode', 'all_content');
    $stream = UploadTestHelpers::streamFor('policy test');
    try {
        expect(fn () => app(IngestFileAction::class)->execute(OperationContext::system($drive, 'fixture'),
            new IngestFileInput($drive, $stream, 'p.txt', 'text/plain')))->toThrow(InvalidConfigurationException::class);
        expect($drive->refresh()->scan_admission_sequence)->toBe(0);
    } finally {
        fclose($stream);
    }
});

it('SEC-POLICY preserves first new content boundary across reactivation and off', function (): void {
    $drive = UploadTestHelpers::storage();
    $drive->forceFill(['scan_admission_sequence' => 7])->save();
    config()->set('vaulter.scan.mode', 'new_content_only');
    config()->set('vaulter.scan.rollout_key', 'first');
    $context = OperationContext::system($drive, 'fixture');
    app(VaulterDatabase::class)->transaction(fn () => app(ScanPolicyResolver::class)->initialize($drive, $context));
    $class = app(VaulterConfig::class)->model('scan_policy_state');
    expect($class::query()->where('drive_id', $drive->id)->pluck('boundary_sequence')->all())->toBe([7, 7]);
    config()->set('vaulter.scan.mode', 'off');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $drive->forceFill(['scan_admission_sequence' => 12])->save();
    config()->set('vaulter.scan.mode', 'new_content_only');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    expect($class::query()->where('drive_id', $drive->id)->pluck('boundary_sequence')->all())->toBe([7, 7]);
});

it('SEC-R01 all content blocks legacy bytes until versioned rescan evidence is clean', function (): void {
    Storage::fake('local');
    Bus::fake();
    $drive = UploadTestHelpers::storage();
    $context = OperationContext::system($drive, 'fixture');
    $stream = UploadTestHelpers::streamFor('legacy');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    $revision = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    $revision->forceFill(['scan_status' => 'clean'])->save();
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    expect(fn () => app(ContentAvailabilityGuard::class)->assertAvailable($context, $node, $revision))
        ->toThrow(ContentUnavailableException::class);
    app(ScanBackfillReconciler::class)->expand();
    $row = $revision->scanObligations()->firstOrFail();
    $attempt = app(ScanObligationRepository::class)->claim($row->id, $row->generation, new DateTimeImmutable('now'));
    app(ScanObligationRepository::class)->settle($attempt, new ScanResult(ScanVerdict::Clean));
    app(ContentAvailabilityGuard::class)->assertAvailable($context, $node, $revision);
    expect($revision->scanEvidence()->count())->toBe(1);
});

it('SEC-R02 forged timestamps cannot exempt a new admission and failures remain unavailable SEC-R07', function (): void {
    Storage::fake('local');
    Bus::fake();
    config()->set('vaulter.scan.mode', 'new_content_only');
    config()->set('vaulter.scan.rollout_key', 'first');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    $drive = UploadTestHelpers::storage();
    $context = OperationContext::system($drive, 'fixture');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $stream = UploadTestHelpers::streamFor('fresh');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    $revision = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    $revision->forceFill(['created_at' => '1990-01-01', 'committed_at' => '1990-01-01'])->save();
    expect($revision->scan_admission_sequence)->toBe(1);
    expect(fn () => app(ContentAvailabilityGuard::class)->assertAvailable($context, $node, $revision))
        ->toThrow(ContentUnavailableException::class);
    $row = $revision->scanObligations()->firstOrFail();
    $attempt = app(ScanObligationRepository::class)->claim($row->id, $row->generation, new DateTimeImmutable('now'));
    app(ScanObligationRepository::class)->settle($attempt, new ScanResult(ScanVerdict::Failed, 'unsupported'));
    expect($row->refresh()->outcome)->toBe('failed');
    expect(fn () => app(ContentAvailabilityGuard::class)->assertAvailable($context, $node, $revision))
        ->toThrow(ContentUnavailableException::class);
});

it('SEC-POLICY-two-boundaries evaluates global and profile eligibility independently', function (): void {
    Storage::fake('local');
    Bus::fake();
    config()->set('vaulter.scan.mode', 'new_content_only');
    config()->set('vaulter.scan.rollout_key', 'first');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    $bind = function (ScanMode $mode): void {
        $registry = new ProfileRegistry;
        $registry->register((new Profile)->key('default')->uploads(new UploadPolicy(scanMode: $mode, scanPolicyVersion: 'profile-v1')));
        $policy = new ProfilePolicy(app(VaulterConfig::class), app('config'), $registry, app(ScanConfig::class), app(CommentsFeature::class));
        $resolver = new ScanPolicyResolver($policy, $registry, app(ScanPolicyStateRepository::class), app(ScanSnapshot::class));
        app()->instance(ScanPolicyResolver::class, $resolver);
        app()->instance(Vaulter\Contracts\Content\ScanPolicyResolver::class, $resolver);
    };
    $bind(ScanMode::Off);
    $drive = UploadTestHelpers::storage();
    $context = OperationContext::system($drive, 'fixture');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $stream = UploadTestHelpers::streamFor('between');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    $revision = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    $bind(ScanMode::NewContentOnly);
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    [$global, $profile] = app(ScanPolicyResolver::class)->forDrive($drive);
    expect(app(ScanPolicyResolver::class)->required($revision, $global))->toBeTrue()
        ->and(app(ScanPolicyResolver::class)->required($revision, $profile))->toBeFalse();
    expect(fn () => app(ContentAvailabilityGuard::class)->assertAvailable($context, $node, $revision))
        ->toThrow(ContentUnavailableException::class);
});

it('SEC-R05 trash restoration preserves admission while current scan policy blocks bytes', function (): void {
    Storage::fake('local');
    $drive = UploadTestHelpers::storage();
    $context = OperationContext::system($drive, 'fixture');
    $stream = UploadTestHelpers::streamFor('restore');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    $revision = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    $node->forceFill(['trashed_at' => now(), 'trashed_from_parent_id' => $drive->root_node_id])->save();
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $restored = app(RestoreNodeAction::class)->execute($context,
        new RestoreNodeInput($node, $drive->rootNode()->firstOrFail()));
    expect($restored->trashed_at)->toBeNull()->and($restored->file()->value('current_revision_id'))->toBe($revision->id)
        ->and($revision->refresh()->scan_admission_sequence)->toBe(1);
    expect(fn () => app(ContentAvailabilityGuard::class)->assertAvailable($context, $restored, $revision))
        ->toThrow(ContentUnavailableException::class);
});

it('SEC-POLICY missing scanner and conflicting required aliases fail scanner boot validation', function (): void {
    config()->set('vaulter.scan.mode', 'all_content');
    expect(fn () => (new BindingsRegistrar(app()))->register())
        ->toThrow(InvalidConfigurationException::class);
    config()->set('vaulter.scan.mode', null);
    config()->set('vaulter.scan.required', true);
    config()->set('vaulter-drive.uploads.scan.required', false);
    expect(fn () => app(ScanConfig::class)->globalSpec())
        ->toThrow(InvalidConfigurationException::class);
});

it('SEC-POLICY rejects sync null or missing broker retry time with a configured scanner', function (string $driver, ?int $retry): void {
    config()->set('queue.default', 'scanner');
    config()->set('queue.connections.scanner', ['driver' => $driver, 'retry_after' => $retry]);
    expect(fn () => app(DriveConfig::class)->validateScanWork(requireBroker: true))
        ->toThrow(InvalidConfigurationException::class);
})->with(['sync' => ['sync', 180], 'null' => ['null', 180], 'missing visibility' => ['database', null], 'short visibility' => ['database', 120]]);

it('SEC-POLICY permits asynchronous scanner work only with actual ordered broker settings', function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.retry_after', 180);
    app(DriveConfig::class)->validateScanWork(requireBroker: true);
    expect(config('queue.connections.database.driver'))->toBe('database');
});

it('SEC-POLICY off without a scanner leaves unrelated broker timing unrestricted', function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.retry_after', 90);
    app(DriveConfig::class)->validateScanWork();
    expect(config('queue.connections.database.retry_after'))->toBe(90);
});
