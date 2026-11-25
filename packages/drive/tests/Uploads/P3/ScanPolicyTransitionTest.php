<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Packman\Config\Exceptions\InvalidConfiguration;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Content\ActivateScanPolicyAction;
use Vaulter\Drive\Actions\Content\ClaimScanWorkAction;
use Vaulter\Drive\Actions\Content\SettleScanWorkAction;
use Vaulter\Drive\Actions\Files\IngestFileAction;
use Vaulter\Drive\Actions\Trash\RestoreNodeAction;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Configuration\DriveOptionsValidator;
use Vaulter\Drive\Configuration\ScanOptions;
use Vaulter\Drive\Content\ContentAvailabilityGuard;
use Vaulter\Drive\Content\ScanBackfillReconciler;
use Vaulter\Drive\Content\ScanPolicyResolver;
use Vaulter\Drive\Content\ScanSnapshot;
use Vaulter\Drive\Data\Files\IngestFileInput;
use Vaulter\Drive\Data\Trash\RestoreNodeInput;
use Vaulter\Drive\Exceptions\Files\ContentUnavailableException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Profiles\UploadPolicy;
use Vaulter\Drive\Providers\BindingsRegistrar;
use Vaulter\Drive\Repositories\Content\ScanPolicyStateRepository;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Kernel;
use Vaulter\Media\Content\ScanResult;
use Vaulter\Media\Enums\Cas\ScanMode;
use Vaulter\Media\Enums\Cas\ScanVerdict;

it('SEC-POLICY rejects enabled unactivated drives without allocating admission', function (): void {
    Storage::fake('local');
    $drive = UploadTestHelpers::storage();
    config()->set('vaulter-drive.scan.mode', 'all_content');
    $stream = UploadTestHelpers::streamFor('policy test');
    try {
        expect(fn () => app(IngestFileAction::class)->execute(DriveContext::system($drive, 'fixture'),
            new IngestFileInput($drive, $stream, 'p.txt', 'text/plain')))->toThrow(InvalidConfigurationException::class);
        expect($drive->refresh()->scan_admission_sequence)->toBe(0);
    } finally {
        fclose($stream);
    }
});

it('SEC-POLICY preserves first new content boundary across reactivation and off', function (): void {
    $drive = UploadTestHelpers::storage();
    app(VaulterDatabase::class)->write(fn () => $drive->forceFill(['scan_admission_sequence' => 7])->save());
    config()->set('vaulter-drive.scan.mode', 'new_content_only');
    config()->set('vaulter-drive.scan.rollout_key', 'first');
    $context = DriveContext::system($drive, 'fixture');
    app(VaulterDatabase::class)->transaction(fn () => app(ScanPolicyResolver::class)->initialize($drive, $context));
    $class = app(DriveOptions::class)->model('scan_policy_state');
    expect($class::query()->where('drive_id', $drive->id)->pluck('boundary_sequence')->all())->toBe([7, 7]);
    config()->set('vaulter-drive.scan.mode', 'off');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    app(VaulterDatabase::class)->write(fn () => $drive->forceFill(['scan_admission_sequence' => 12])->save());
    config()->set('vaulter-drive.scan.mode', 'new_content_only');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    expect($class::query()->where('drive_id', $drive->id)->pluck('boundary_sequence')->all())->toBe([7, 7]);
});

it('all content blocks legacy bytes until versioned rescan evidence is clean', function (): void {
    Storage::fake('local');
    Bus::fake();
    $drive = UploadTestHelpers::storage();
    $context = DriveContext::system($drive, 'fixture');
    $stream = UploadTestHelpers::streamFor('legacy');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    $revision = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    app(VaulterDatabase::class)->write(fn () => $revision->forceFill(['scan_status' => 'clean'])->save());
    config()->set('vaulter-drive.scan.mode', 'all_content');
    config()->set('vaulter-drive.scan.scanner_key', 'fixture');
    config()->set('vaulter-drive.scan.scanner_version', '1');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    expect(fn () => app(ContentAvailabilityGuard::class)->assertAvailable($context, $node, $revision))
        ->toThrow(ContentUnavailableException::class);
    app(ScanBackfillReconciler::class)->expand();
    $row = $revision->scanObligations()->firstOrFail();
    $attempt = app(ClaimScanWorkAction::class)->execute($row->id, $row->generation, new DateTimeImmutable('now'));
    app(SettleScanWorkAction::class)->execute($attempt, new ScanResult(ScanVerdict::Clean));
    app(ContentAvailabilityGuard::class)->assertAvailable($context, $node, $revision);
    expect($revision->scanEvidence()->count())->toBe(1);
});

it('forged timestamps cannot exempt a new admission and failures remain unavailable', function (): void {
    Storage::fake('local');
    Bus::fake();
    config()->set('vaulter-drive.scan.mode', 'new_content_only');
    config()->set('vaulter-drive.scan.rollout_key', 'first');
    config()->set('vaulter-drive.scan.scanner_key', 'fixture');
    config()->set('vaulter-drive.scan.scanner_version', '1');
    $drive = UploadTestHelpers::storage();
    $context = DriveContext::system($drive, 'fixture');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $stream = UploadTestHelpers::streamFor('fresh');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    $revision = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    app(VaulterDatabase::class)->write(fn () => $revision->forceFill(['created_at' => '1990-01-01', 'committed_at' => '1990-01-01'])->save());
    expect($revision->scan_admission_sequence)->toBe(1);
    expect(fn () => app(ContentAvailabilityGuard::class)->assertAvailable($context, $node, $revision))
        ->toThrow(ContentUnavailableException::class);
    $row = $revision->scanObligations()->firstOrFail();
    $attempt = app(ClaimScanWorkAction::class)->execute($row->id, $row->generation, new DateTimeImmutable('now'));
    app(SettleScanWorkAction::class)->execute($attempt, new ScanResult(ScanVerdict::Failed, 'unsupported'));
    expect($row->refresh()->outcome)->toBe('failed');
    expect(fn () => app(ContentAvailabilityGuard::class)->assertAvailable($context, $node, $revision))
        ->toThrow(ContentUnavailableException::class);
});

it('SEC-POLICY-two-boundaries evaluates global and profile eligibility independently', function (): void {
    Storage::fake('local');
    Bus::fake();
    config()->set('vaulter-drive.scan.mode', 'new_content_only');
    config()->set('vaulter-drive.scan.rollout_key', 'first');
    config()->set('vaulter-drive.scan.scanner_key', 'fixture');
    config()->set('vaulter-drive.scan.scanner_version', '1');
    $bind = function (ScanMode $mode): void {
        $registry = new ProfileRegistry;
        $registry->register((new Profile)->key('default')->uploads(new UploadPolicy(scanMode: $mode, scanPolicyVersion: 'profile-v1')));
        $policy = new ProfilePolicy(app(DriveOptions::class), app('config'), $registry, app(ScanOptions::class), app(Kernel::class));
        $resolver = new ScanPolicyResolver($policy, $registry, app(ScanPolicyStateRepository::class), app(ScanSnapshot::class), app(ActivateScanPolicyAction::class));
        app()->instance(ScanPolicyResolver::class, $resolver);
        app()->instance(Vaulter\Drive\Contracts\Content\ScanPolicyResolver::class, $resolver);
    };
    $bind(ScanMode::Off);
    $drive = UploadTestHelpers::storage();
    $context = DriveContext::system($drive, 'fixture');
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

it('trash restoration preserves admission while current scan policy blocks bytes', function (): void {
    Storage::fake('local');
    $drive = UploadTestHelpers::storage();
    $context = DriveContext::system($drive, 'fixture');
    $stream = UploadTestHelpers::streamFor('restore');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    $revision = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    app(VaulterDatabase::class)->write(fn () => $node->forceFill(['trashed_at' => now(), 'trashed_from_parent_id' => $drive->root_node_id, 'trash_root_id' => $node->getKey()])->save());
    config()->set('vaulter-drive.scan.mode', 'all_content');
    config()->set('vaulter-drive.scan.scanner_key', 'fixture');
    config()->set('vaulter-drive.scan.scanner_version', '1');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $restored = app(RestoreNodeAction::class)->execute($context,
        new RestoreNodeInput($node, $drive->rootNode()->firstOrFail()));
    expect($restored->trashed_at)->toBeNull()->and($restored->file()->value('current_revision_id'))->toBe($revision->id)
        ->and($revision->refresh()->scan_admission_sequence)->toBe(1);
    expect(fn () => app(ContentAvailabilityGuard::class)->assertAvailable($context, $restored, $revision))
        ->toThrow(ContentUnavailableException::class);
});

it('SEC-POLICY missing scanner and conflicting required aliases fail scanner boot validation', function (): void {
    config()->set('vaulter-drive.scan.mode', 'all_content');
    expect(fn () => (new BindingsRegistrar)->register(app()))
        ->toThrow(InvalidConfigurationException::class);
    config()->set('vaulter-drive.scan.mode');
    config()->set('vaulter-drive.scan.required', true);
    config()->set('vaulter-drive.uploads.scan.required', false);
    expect(fn () => app(ScanOptions::class)->globalSpec())
        ->toThrow(InvalidConfiguration::class);
});

it('SEC-POLICY rejects sync null or missing broker retry time with a configured scanner', function (string $driver, ?int $retry): void {
    config()->set('queue.default', 'scanner');
    config()->set('queue.connections.scanner', ['driver' => $driver, 'retry_after' => $retry]);
    expect(fn () => app(DriveOptionsValidator::class)->validateScanWork(app(DriveOptions::class), requireBroker: true))
        ->toThrow(InvalidConfigurationException::class);
})->with(['sync' => ['sync', 180], 'null' => ['null', 180], 'missing visibility' => ['database', null], 'short visibility' => ['database', 120]]);

it('SEC-POLICY permits asynchronous scanner work only with actual ordered broker settings', function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.retry_after', 180);
    app(DriveOptionsValidator::class)->validateScanWork(app(DriveOptions::class), requireBroker: true);
    expect(config('queue.connections.database.driver'))->toBe('database');
});

it('SEC-POLICY off without a scanner leaves unrelated broker timing unrestricted', function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.retry_after', 90);
    app(DriveOptionsValidator::class)->validateScanWork(app(DriveOptions::class));
    expect(config('queue.connections.database.retry_after'))->toBe(90);
});
