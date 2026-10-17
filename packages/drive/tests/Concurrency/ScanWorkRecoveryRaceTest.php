<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Content\ClaimScanWorkAction;
use Vaulter\Drive\Actions\Content\FenceScanAttemptAction;
use Vaulter\Drive\Actions\Content\RecoverScanWorkAction;
use Vaulter\Drive\Actions\Content\SettleScanWorkAction;
use Vaulter\Drive\Actions\Files\IngestFileAction;
use Vaulter\Drive\Actions\Trash\PurgeNodeAction;
use Vaulter\Drive\Content\ScanAttempt;
use Vaulter\Drive\Content\ScanPolicyResolver;
use Vaulter\Drive\Content\ScanWorkReconciler;
use Vaulter\Drive\Data\Files\IngestFileInput;
use Vaulter\Drive\Data\Trash\PurgeNodeInput;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Repositories\Content\ScanObligationRepository;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Media\Content\ScanResult;
use Vaulter\Media\Enums\Cas\ScanVerdict;
use Vaulter\Testing\Concurrency\ForkRace;

beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    Storage::fake('local');
    Bus::fake();
});

it('SEC-R11 concurrent claims acquire one live lease and late old token cannot settle', function (): void {
    config()->set('vaulter-drive.scan.mode', 'all_content');
    config()->set('vaulter-drive.scan.scanner_key', 'fixture');
    config()->set('vaulter-drive.scan.scanner_version', '1');
    $drive = UploadTestHelpers::storage();
    $context = DriveContext::system($drive, 'fixture');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $stream = UploadTestHelpers::streamFor('race bytes');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    $revision = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    $work = $revision->scanObligations()->firstOrFail();
    $outcomes = ForkRace::run([
        fn (): bool => app(ClaimScanWorkAction::class)->execute($work->id, $work->generation, new DateTimeImmutable('now')) !== null,
        fn (): bool => app(ClaimScanWorkAction::class)->execute($work->id, $work->generation, new DateTimeImmutable('now')) !== null,
    ]);
    expect($outcomes[0]->ok)->toBeTrue($outcomes[0]->message ?? '')
        ->and($outcomes[1]->ok)->toBeTrue($outcomes[1]->message ?? '')
        ->and(array_filter(array_map(fn ($o) => $o->value, $outcomes)))->toHaveCount(1);
    $work->refresh();
    $work->forceFill(['state' => 'retry_wait', 'lease_token' => null, 'lease_expires_at' => null, 'available_at' => now()])->save();
    $old = app(ClaimScanWorkAction::class)->execute($work->id, $work->generation, new DateTimeImmutable('now'));
    $work->refresh()->forceFill(['lease_expires_at' => now()->subSecond()])->save();
    app(RecoverScanWorkAction::class)->execute($work, new DateTimeImmutable('now'));
    $fresh = app(ClaimScanWorkAction::class)->execute($work->id, $work->generation, new DateTimeImmutable('now'));
    $otherToken = ($fresh->leaseToken[0] === '0' ? '1' : '0').substr($fresh->leaseToken, 1);
    $forged = new ScanAttempt(
        $fresh->obligationId, $fresh->revisionId, $fresh->blobId, $fresh->driveId, $fresh->tenantId,
        $fresh->contentIdentity, $fresh->spec, $fresh->generation, $fresh->attempt,
        $otherToken, $fresh->leaseExpiresAt, $fresh->deadline,
    );
    expect(app(FenceScanAttemptAction::class)->execute($forged))->toBeNull()
        ->and(app(SettleScanWorkAction::class)->execute($forged, new ScanResult(ScanVerdict::Clean)))->toBeFalse();
    expect($fresh->leaseToken)->not->toBe($old->leaseToken)
        ->and(app(SettleScanWorkAction::class)->execute($old, new ScanResult(ScanVerdict::Clean)))->toBeFalse()
        ->and(app(SettleScanWorkAction::class)->execute($fresh, new ScanResult(ScanVerdict::Clean)))->toBeTrue()
        ->and($revision->scanEvidence()->count())->toBe(1)
        ->and($node->refresh()->file()->value('current_revision_id'))->toBe($revision->id);
});

it('SEC-R09 killed committed publisher leaves intent recoverable by an independent process', function (): void {
    config()->set('vaulter-drive.scan.mode', 'all_content');
    config()->set('vaulter-drive.scan.scanner_key', 'fixture');
    config()->set('vaulter-drive.scan.scanner_version', '1');
    $drive = UploadTestHelpers::storage();
    $context = DriveContext::system($drive, 'fixture');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $outcomes = ForkRace::run([function () use ($drive, $context): void {
        app(VaulterDatabase::class)->transaction(function () use ($drive, $context): void {
            app(VaulterDatabase::class)->afterCommit(fn () => posix_kill(getmypid(), SIGKILL));
            $stream = UploadTestHelpers::streamFor('killed publisher');
            try {
                app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'killed.txt', 'text/plain'));
            } finally {
                fclose($stream);
            }
        });
    }]);
    expect($outcomes[0]->ok)->toBeFalse();
    $workClass = app(VaulterConfig::class)->model('scan_obligation');
    $row = $workClass::query()->where('drive_id', $drive->id)->firstOrFail();
    expect($row->state)->toBe('pending')->and($row->attempt)->toBe(0);
    $recovered = ForkRace::run([function () use ($row): bool {
        $result = app(ScanWorkReconciler::class)->reconcile();
        $attempt = app(ClaimScanWorkAction::class)->execute($row->id, $row->generation, new DateTimeImmutable('now'));

        return $result['published'] === 1 && $attempt !== null
            && app(SettleScanWorkAction::class)->execute($attempt, new ScanResult(ScanVerdict::Clean));
    }]);
    expect($recovered[0]->ok)->toBeTrue($recovered[0]->message ?? '')->and($recovered[0]->value)->toBeTrue()
        ->and($row->refresh()->outcome)->toBe('clean');
});

it('SEC-R11 killed lease owner is fenced while a surviving process recovers', function (): void {
    config()->set('vaulter-drive.scan.mode', 'all_content');
    config()->set('vaulter-drive.scan.scanner_key', 'fixture');
    config()->set('vaulter-drive.scan.scanner_version', '1');
    $drive = UploadTestHelpers::storage();
    $context = DriveContext::system($drive, 'fixture');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $stream = UploadTestHelpers::streamFor('killed worker');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    $row = $node->file()->firstOrFail()->currentRevision()->firstOrFail()->scanObligations()->firstOrFail();
    $killed = ForkRace::run([function () use ($row): void {
        $attempt = app(ClaimScanWorkAction::class)->execute($row->id, $row->generation, new DateTimeImmutable('now'));
        if ($attempt === null) {
            throw new RuntimeException('Fixture failed to claim.');
        }
        posix_kill(getmypid(), SIGKILL);
    }]);
    expect($killed[0]->ok)->toBeFalse()->and($row->refresh()->state)->toBe('leased');
    $row->forceFill(['lease_expires_at' => now()->subSecond()])->save();
    $surviving = ForkRace::run([function () use ($row): bool {
        app(RecoverScanWorkAction::class)->execute($row, new DateTimeImmutable('now'));
        $attempt = app(ClaimScanWorkAction::class)->execute($row->id, $row->generation, new DateTimeImmutable('now'));

        return $attempt !== null && app(SettleScanWorkAction::class)->execute($attempt, new ScanResult(ScanVerdict::Clean));
    }]);
    expect($surviving[0]->ok)->toBeTrue($surviving[0]->message ?? '')->and($surviving[0]->value)->toBeTrue()
        ->and($row->refresh()->attempt)->toBe(2)->and($row->outcome)->toBe('clean');
});

it('SEC-POLICY-activation-race captures an admitted writer before exclusive activation', function (): void {
    $drive = UploadTestHelpers::storage();
    $context = DriveContext::system($drive, 'fixture');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $ready = tempnam(sys_get_temp_dir(), 'vaulter_activation_');
    unlink($ready);
    try {
        $outcomes = ForkRace::run([
            function () use ($drive, $context, $ready): int {
                return app(VaulterDatabase::class)->transaction(function () use ($drive, $context, $ready): int {
                    app(VaulterDatabase::class)->lockTreeShared($drive->id);
                    $stream = UploadTestHelpers::streamFor('before activation');
                    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'before.txt', 'text/plain'));
                    fclose($stream);
                    file_put_contents($ready, 'admitted');
                    usleep(150_000);

                    return $node->file()->firstOrFail()->currentRevision()->firstOrFail()->scan_admission_sequence;
                });
            },
            function () use ($drive, $context, $ready): int {
                $deadline = microtime(true) + 5;
                while (!file_exists($ready)) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Activation barrier timed out.');
                    }
                    usleep(1000);
                }
                config()->set('vaulter-drive.scan.mode', 'new_content_only');
                config()->set('vaulter-drive.scan.rollout_key', 'first');
                config()->set('vaulter-drive.scan.scanner_key', 'fixture');
                config()->set('vaulter-drive.scan.scanner_version', '1');
                app(ScanPolicyResolver::class)->initialize($drive, $context);
                $class = app(VaulterConfig::class)->model('scan_policy_state');

                return $class::query()->where('drive_id', $drive->id)->where('predicate_key', 'global')->firstOrFail()->boundary_sequence;
            },
        ]);
        foreach ($outcomes as $outcome) {
            expect($outcome->ok)->toBeTrue($outcome->message ?? '')->and($outcome->value)->toBe(1);
        }
        config()->set('vaulter-drive.scan.mode', 'new_content_only');
        config()->set('vaulter-drive.scan.rollout_key', 'first');
        config()->set('vaulter-drive.scan.scanner_key', 'fixture');
        config()->set('vaulter-drive.scan.scanner_version', '1');
        $stream = UploadTestHelpers::streamFor('after activation');
        $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'after.txt', 'text/plain'));
        fclose($stream);
        expect($node->file()->firstOrFail()->currentRevision()->firstOrFail()->scan_admission_sequence)->toBe(2)
            ->and($node->file()->firstOrFail()->currentRevision()->firstOrFail()->scanObligations()->count())->toBe(1);
    } finally {
        if (file_exists($ready)) {
            unlink($ready);
        }
    }
});

it('SEC-R12 purge and settlement serialize without resurrecting a head or work', function (): void {
    config()->set('vaulter-drive.scan.mode', 'all_content');
    config()->set('vaulter-drive.scan.scanner_key', 'fixture');
    config()->set('vaulter-drive.scan.scanner_version', '1');
    $drive = UploadTestHelpers::storage();
    $context = DriveContext::system($drive, 'fixture');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $stream = UploadTestHelpers::streamFor('purge race');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    $revision = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    $row = $revision->scanObligations()->firstOrFail();
    $attempt = app(ClaimScanWorkAction::class)->execute($row->id, $row->generation, new DateTimeImmutable('now'));
    $node->newQuery()->whereKey($node->id)->update(['trashed_at' => now(), 'trash_root_id' => DB::raw('id')]);
    $outcomes = ForkRace::run([
        function () use ($node, $context): bool {
            app(PurgeNodeAction::class)->execute($context, new PurgeNodeInput($node));

            return true;
        },
        fn (): bool => app(SettleScanWorkAction::class)->execute($attempt, new ScanResult(ScanVerdict::Clean)),
    ]);
    expect($outcomes[0]->ok)->toBeTrue($outcomes[0]->message ?? '')->and($outcomes[1]->ok)->toBeTrue($outcomes[1]->message ?? '')
        ->and(FileRevision::query()->whereKey($revision->id)->exists())->toBeFalse()
        ->and(app(ScanObligationRepository::class)->find($row->id))->toBeNull()
        ->and(Node::query()->whereKey($node->id)->exists())->toBeFalse();
});
