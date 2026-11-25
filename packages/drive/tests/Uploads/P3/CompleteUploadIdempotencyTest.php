<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Actions\Uploads\CompleteUploadAction;
use Vaulter\Drive\Content\UploadCleanupReconciler;
use Vaulter\Drive\Contracts\DriveHandleFactory;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\Inspection\MimeSniffer;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\ValueObjects\Access\Actor;

// These tests drive handlers and repositories directly; Vaulter's entry points open the write scope, these calls do not.
beforeEach(fn () => DriveTestHelpers::insideOperation());

beforeEach(fn () => Storage::set('local', Storage::fake('uploads-p37-'.getmypid())));

function assembledIdempotencyUpload(): UploadSession
{
    $drive = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($drive, totalSizeBytes: 4, targetName: 'once.txt', parentId: $drive->root_node_id);
    app(TusUploadHandler::class)->patch(session: $session, chunk: UploadTestHelpers::streamFor('once'), offset: 0);

    return app(UploadSessionManager::class)->markAssembled($session->refresh());
}

it('replays persisted exact revision after a newer head and without staging', function (): void {
    $session = assembledIdempotencyUpload();
    $context = DriveContext::system($session->storage, 'upload test');
    $node = app(CompleteUploadAction::class)->execute(context: $context, session: $session);
    $revisionId = $session->refresh()->result_revision_id;
    app(DriveHandleFactory::class)->forDrive($context->drive)->asSystem('upload test')->files()->addRevision(file: $node->id, source: UploadSource::fromStream(UploadTestHelpers::streamFor('new head'), 'new.txt'));
    $session->forceFill(['metadata' => [...($session->metadata ?? []), 'correlation_id' => 'ignored']])->save();
    $replayed = app(CompleteUploadAction::class)->execute(context: $context, session: $session);
    expect($replayed->id)->toBe($node->id)->and(FileRevision::query()->count())->toBe(2)
        ->and($session->refresh()->result_blob_id)->toBe($session->assembled_blob_id)
        ->and($session->result_revision_id)->toBe($revisionId)
        ->and(app(StagedUpload::class)->size($session))->toBe(0);
});

it('SEC-CLEANUP-input-fingerprint rejects changed completion intent and corrupt authoritative pointers without ingest', function (): void {
    $session = assembledIdempotencyUpload();
    $context = DriveContext::system($session->storage, 'upload test');
    app(CompleteUploadAction::class)->execute(context: $context, session: $session);
    $session->refresh()->forceFill(['target_name' => 'different.txt']);
    expect(fn () => app(CompleteUploadAction::class)->execute(context: $context, session: $session))->toThrow(UploadConflictException::class);
    $session->refresh()->forceFill(['result_blob_id' => null])->save();
    expect(fn () => app(CompleteUploadAction::class)->execute(context: $context, session: $session))->toThrow(UploadConflictException::class);
    expect(FileRevision::query()->count())->toBe(1);
});

it('keeps protocol bytes until outer commit and retains them after outer rollback', function (): void {
    $session = assembledIdempotencyUpload();
    $context = DriveContext::system($session->storage, 'upload test');
    expect(fn () => app(VaulterDatabase::class)->transaction(function () use ($context, $session): void {
        app(CompleteUploadAction::class)->execute(context: $context, session: $session);
        expect($session->refresh()->cleanup_pending)->toBeTrue()->and(app(StagedUpload::class)->size($session))->toBe(4);
        throw new RuntimeException('outer rollback');
    }, attempts: 1))->toThrow(RuntimeException::class, 'outer rollback');
    expect($session->refresh()->status)->toBe(UploadStatus::Assembled)
        ->and($session->result_revision_id)->toBeNull()->and(FileRevision::query()->count())->toBe(0)
        ->and(app(StagedUpload::class)->size($session))->toBe(4);
    app(CompleteUploadAction::class)->execute(context: $context, session: $session);
    expect(FileRevision::query()->count())->toBe(1);
});

it('SEC-CLEANUP-success-before-flag-clear treats missing committed staging as idempotent recovery and never creates another revision', function (): void {
    $session = assembledIdempotencyUpload();
    app(CompleteUploadAction::class)->execute(context: DriveContext::system($session->storage, 'upload test'), session: $session);
    $session->refresh()->forceFill(['cleanup_pending' => true, 'cleanup_available_at' => now()])->save();
    $result = app(UploadCleanupReconciler::class)->reconcile(limit: 1, driveIds: [$session->drive_id]);
    expect($result['cleanup_completed'])->toBe(1)->and($session->refresh()->cleanup_pending)->toBeFalse()
        ->and(FileRevision::query()->count())->toBe(1);
});

it('SEC-CLEANUP-delete-failure keeps failed postcommit deletion recoverable while completion remains successful', function (): void {
    $session = assembledIdempotencyUpload();
    $disk = Storage::disk('local');
    $proxy = Mockery::mock($disk);
    $proxy->shouldReceive('delete')->with(app(StagedUpload::class)->keyFor($session))->andReturnFalse();
    Storage::set('local', $proxy);
    $node = app(CompleteUploadAction::class)->execute(context: DriveContext::system($session->storage, 'upload test'), session: $session);
    expect($node->exists)->toBeTrue()->and($session->refresh()->status)->toBe(UploadStatus::Committed)
        ->and($session->cleanup_pending)->toBeTrue()->and($session->cleanup_attempts)->toBe(1);
    Storage::set('local', $disk);
    $session->forceFill(['cleanup_available_at' => now()])->save();
    expect(app(UploadCleanupReconciler::class)->reconcile(limit: 1, driveIds: [$session->drive_id])['cleanup_completed'])->toBe(1)
        ->and(FileRevision::query()->count())->toBe(1);
});

it('SEC-CLEANUP-sniff-throw closes the acquired protocol reader and its own preparation', function (): void {
    $session = assembledIdempotencyUpload();
    $disk = Storage::disk('local');
    $reader = $disk->readStream(app(StagedUpload::class)->keyFor($session));
    $proxy = Mockery::mock($disk);
    $proxy->shouldReceive('readStream')->with(app(StagedUpload::class)->keyFor($session))->andReturn($reader);
    Storage::set('local', $proxy);
    $sniffer = Mockery::mock(new MimeSniffer);
    $sniffer->shouldReceive('detect')->andThrow(new RuntimeException('sniff failed'));
    app()->instance(MimeSniffer::class, $sniffer);
    $before = glob(sys_get_temp_dir().'/vaulter-upload-*');
    expect(fn () => app(CompleteUploadAction::class)->execute(context: DriveContext::system($session->storage, 'upload test'), session: $session))
        ->toThrow(RuntimeException::class, 'sniff failed');
    expect(is_resource($reader))->toBeFalse()->and(glob(sys_get_temp_dir().'/vaulter-upload-*'))->toBe($before)
        ->and($session->refresh()->status)->toBe(UploadStatus::Assembled)->and(FileRevision::query()->count())->toBe(0);
    Storage::set('local', $disk);
    expect(app(StagedUpload::class)->size($session))->toBe(4);
})->group('serial', 'nofast');

it('backfills only provable legacy revision pointers and never guesses the newer head', function (): void {
    $session = assembledIdempotencyUpload();
    $context = DriveContext::system($session->storage, 'upload test');
    $node = app(CompleteUploadAction::class)->execute(context: $context, session: $session);
    $session->refresh()->forceFill(['result_blob_id' => null, 'input_fingerprint' => null])->save();
    $replayed = app(CompleteUploadAction::class)->execute(context: $context, session: $session);
    expect($replayed->id)->toBe($node->id)->and($session->refresh()->input_fingerprint)->not->toBeNull()
        ->and($session->result_blob_id)->toBe($session->assembled_blob_id)->and(FileRevision::query()->count())->toBe(1);
});

it('SEC-CLEANUP-revoked-result requires fresh View and rejects another actor and foreign drive', function (): void {
    $session = assembledIdempotencyUpload();
    $user = AccessTestHelpers::user(991);
    $session->forceFill(['created_by' => $user->getKey(), 'metadata' => ['actor_type' => $user->getMorphClass()]])->save();
    $context = DriveContext::for(drive: $session->storage, actor: Actor::user($user), correlationId: 'upload-current-auth');
    $node = app(CompleteUploadAction::class)->execute(context: $context, session: $session);
    $other = DriveContext::for(drive: $context->drive, actor: Actor::user(AccessTestHelpers::user(992)), correlationId: 'other-owner');
    expect(fn () => app(CompleteUploadAction::class)->execute(context: $other, session: $session->refresh()))->toThrow(PermissionDeniedException::class);
    $foreign = DriveContext::system(UploadTestHelpers::storage(), 'foreign upload');
    expect(fn () => app(CompleteUploadAction::class)->execute(context: $foreign, session: $session->refresh()))->toThrow(NodeNotFoundException::class);
    config()->set('vaulter-drive.access.permission_driver', 'database');
    expect(fn () => app(CompleteUploadAction::class)->execute(context: $context, session: $session->refresh()))->toThrow(NodeNotFoundException::class);
    config()->set('vaulter-drive.access.permission_driver', 'null');
    $node->forceFill(['trashed_at' => now(), 'trash_root_id' => $node->getKey()])->save();
    expect(fn () => app(CompleteUploadAction::class)->execute(context: $context, session: $session->refresh()))->toThrow(NodeNotFoundException::class);
    expect(FileRevision::query()->count())->toBe(1);
});

it('SEC-CLEANUP-backoff follows the bounded retry schedule without re-ingest', function (): void {
    $this->freezeTime();
    $session = assembledIdempotencyUpload();
    $disk = Storage::disk('local');
    $proxy = Mockery::mock($disk);
    $proxy->shouldReceive('delete')->with(app(StagedUpload::class)->keyFor($session))->andReturnFalse();
    Storage::set('local', $proxy);
    try {
        app(CompleteUploadAction::class)->execute(DriveContext::system($session->storage, 'cleanup backoff'), $session);
        foreach ([30, 120, 600, 1800, 3600, 3600] as $index => $delay) {
            $session->refresh();
            expect($session->cleanup_attempts)->toBe($index + 1)
                ->and($session->cleanup_available_at->getTimestamp() - now()->getTimestamp())->toBe($delay)
                ->and($session->cleanup_pending)->toBeTrue()
                ->and(app(UploadCleanupReconciler::class)->reconcile(limit: 1, driveIds: [$session->drive_id])['cleanup_failed'])->toBe(0)
                ->and(FileRevision::query()->count())->toBe(1);
            $this->travel($delay)->seconds();
            if ($index < 5) {
                expect(app(UploadCleanupReconciler::class)->reconcile(limit: 1, driveIds: [$session->drive_id])['cleanup_failed'])->toBe(1);
            }
        }
        Storage::set('local', $disk);
        expect(app(UploadCleanupReconciler::class)->reconcile(limit: 1, driveIds: [$session->drive_id])['cleanup_completed'])->toBe(1)
            ->and(FileRevision::query()->count())->toBe(1);
    } finally {
        Storage::set('local', $disk);
        $this->travelBack();
    }
});

it('SEC-C03-replay-ability replays a committed completion only while the actor still holds the mutation right', function (): void {
    config()->set('vaulter-drive.access.permission_driver', 'database');
    $session = assembledIdempotencyUpload();
    $user = AccessTestHelpers::user(993);
    $session->forceFill(['created_by' => $user->getKey(), 'metadata' => ['actor_type' => $user->getMorphClass()]])->save();
    $grant = AccessTestHelpers::grant($session->storage->rootNode()->firstOrFail(), RoleRegistry::WRITER, scope: GrantScope::Subtree, subjectId: $user->getKey());
    $context = DriveContext::for(drive: $session->storage, actor: Actor::user($user), correlationId: 'replay-ability');
    $node = app(CompleteUploadAction::class)->execute(context: $context, session: $session);

    expect(app(CompleteUploadAction::class)->execute(context: $context, session: $session->refresh())->id)->toBe($node->id);

    // Still able to read the result, no longer allowed to create in the parent.
    $grant->forceFill(['role_key' => RoleRegistry::READER])->save();

    expect(fn () => app(CompleteUploadAction::class)->execute(context: $context, session: $session->refresh()))->toThrow(PermissionDeniedException::class)
        ->and(FileRevision::query()->count())->toBe(1);
});
