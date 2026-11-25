<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Uploads\CompleteUploadAction;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Exceptions\Uploads\StagedUploadMissingException;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Repositories\Uploads\UploadSessionStoreRepository;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\S3MultipartHandler;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;

// These tests drive handlers and repositories directly; Vaulter's entry points open the write scope, these calls do not.
beforeEach(fn () => DriveTestHelpers::insideOperation());

/**
 * The upload-session critical section. These are the DETERMINISTIC siblings of the
 * PATCH↔sweep race fork test (tests/Concurrency/TusPatchVsSweepRaceTest.php, PostgreSQL
 * only): each mechanism in the file-corruption chain is checked on its own here, without
 * real concurrency, so they also run in the SQLite lane.
 */
beforeEach(function (): void {
    Storage::fake('local');
});

/*
 * Fail CLOSED. Before the fix append() carried the already accepted bytes over only under
 * `if ($offset > 0 && $disk->exists($key))`: without a staged object the branch was silently
 * skipped and ONE new chunk was written to the key. The caller took its length as the new
 * authoritative offset and the file prefix was gone for good. A data-integrity path must
 * refuse, not truncate.
 */
it('append() at offset > 0 with the staged object gone REFUSES instead of truncating the file', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 20);

    $staged = app(StagedUpload::class);

    // 10 bytes accepted normally.
    expect($staged->append($session, UploadTestHelpers::streamFor('0123456789'), 0))->toBe(10);

    // Something removed the staged object (sweep, abort or a manual bucket cleanup) while the
    // session still believes it holds 10 bytes.
    Storage::disk('local')->delete($staged->keyFor($session));

    expect(fn () => $staged->append($session, UploadTestHelpers::streamFor('ABCDE'), 10))
        ->toThrow(StagedUploadMissingException::class);

    // The key point: before the fix this would hold the 5 bytes 'ABCDE' and append would return 5,
    // a regressed offset. Now no bytes are written at all.
    expect($staged->size($session->fresh()))->toBe(0);
});

/*
 * advanceReceived() forced `status => Uploading` unconditionally, bypassing
 * UploadStatus::canTransitionTo, and was the mechanism that RESURRECTED a terminal session in
 * the PATCH ↔ sweep race. Compare-and-set on the open statuses: 0 affected rows means a conflict.
 */
it('advanceReceived() does NOT resurrect a terminal session (CAS on the open statuses)', function (UploadStatus $terminal): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 100);

    // The session went terminal (sweep, abort or commit), as in the PATCH ↔ sweep race.
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['status' => $terminal])->save());

    expect(fn () => app(UploadSessionStoreRepository::class)->advanceReceived($session, 42))
        ->toThrow(UploadConflictException::class);

    $after = UploadSession::query()->findOrFail($session->getKey());

    // Before the fix: status → uploading, received_bytes → 42. The terminal session came back to life.
    expect($after->status)->toBe($terminal)
        ->and($after->received_bytes)->toBe(0);
})->with([
    'expired' => [UploadStatus::Expired],
    'aborted' => [UploadStatus::Aborted],
    'committed' => [UploadStatus::Committed],
]);

/*
 * The deterministic half of the PATCH ↔ sweep race: expire() and abort() must enter the same
 * critical section as PATCH and REREAD the status under the lock. This checks the
 * second invariant: a session that went terminal between the candidate selection and the lock is
 * NOT touched again, so the bytes of another (newer) stage are not removed and no event is duplicated.
 */
it('expire() rereads the status under the lock and leaves an already terminal session alone', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 100);

    // A candidate the sweep selected BEFORE the client aborted it itself.
    $stale = UploadSession::query()->findOrFail($session->getKey());

    app(UploadSessionManager::class)->abort($session);
    expect(UploadSession::query()->findOrFail($session->getKey())->status)->toBe(UploadStatus::Aborted);

    // The sweep arrives with a STALE snapshot: the status is reread under the lock, so it is a no-op
    // and the session stays Aborted (instead of being rewritten to Expired).
    $result = app(UploadSessionManager::class)->expire($stale);

    expect($result->status)->toBe(UploadStatus::Aborted)
        ->and(UploadSession::query()->findOrFail($session->getKey())->status)->toBe(UploadStatus::Aborted);
});

/*
 * The sweep must remove exactly the STALE sessions. A sanity check on the conflict-free path
 * (skipping a locked session needs a real second connection; that lives in
 * tests/Concurrency/TusPatchVsSweepRaceTest.php, the PostgreSQL lane).
 */
it('vaulter:uploads:sweep reclaims a stale session and cleans the staged bytes', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 100);

    app(StagedUpload::class)->append($session, UploadTestHelpers::streamFor('0123456789'), 0);
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['expires_at' => Carbon::now()->subHour()])->save());

    $this->artisan('vaulter:uploads:sweep')->assertSuccessful();

    $after = UploadSession::query()->findOrFail($session->getKey());

    expect($after->status)->toBe(UploadStatus::Expired)
        ->and(app(StagedUpload::class)->size($after))->toBe(0);
});

/*
 * markCommitted() was the ONLY status writer without a
 * compare-and-set: a bare forceFill('committed'). While the commit path ran OUTSIDE the
 * critical section, the sweep could reclaim an Assembled session (scopeSweepable
 * includes it) in the middle of the commit, and markCommitted resurrected the terminal row as
 * Committed, adding an assembled_blob_id. CAS on Assembled: 0 rows means a conflict.
 */
it('markCommitted() does NOT resurrect a reclaimed session (CAS on Assembled)', function (UploadStatus $terminal): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 10);

    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['status' => $terminal])->save());

    expect(fn () => app(UploadSessionStoreRepository::class)->markCommitted($session, 777))
        ->toThrow(UploadConflictException::class);

    $after = UploadSession::query()->findOrFail($session->getKey());

    // Before the fix: status → committed, assembled_blob_id → 777. A session whose staged bytes the
    // sweep had already removed was declared successfully committed.
    expect($after->status)->toBe($terminal)
        ->and($after->assembled_blob_id)->toBeNull();
})->with([
    'expired' => [UploadStatus::Expired],
    'aborted' => [UploadStatus::Aborted],
]);

/*
 * The end-to-end case: a session reclaimed WHILE its bytes are still in the staging area (exactly the
 * window between markAssembled and BlobStore.commit) must not commit. Before the fix
 * CommitUploadAction carried it through: the node was created and the terminal row
 * came back as Committed, so sweep and commit silently shared the same bytes.
 */
it('committing a reclaimed session is rejected: no node is created and the status is not resurrected', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 6, parentId: $storage->root_node_id);

    app(StagedUpload::class)->append($session, UploadTestHelpers::streamFor('abcdef'), 0);
    app(UploadSessionStoreRepository::class)->advanceReceived($session, 6);
    app(UploadSessionManager::class)->markAssembled($session->refresh());

    $nodesBefore = Node::query()->count();

    // The sweep already moved the session to a terminal status, but the staged object is still readable (discard and
    // commit share one window; in a real race the bytes disappear in the MIDDLE of the stream).
    UploadSession::query()->whereKey($session->getKey())->update(['status' => UploadStatus::Expired->value]);

    expect(fn () => app(CompleteUploadAction::class)->execute(context: DriveContext::system($session->storage, 'test fixture'), session: $session->refresh()))
        ->toThrow(UploadConflictException::class);

    $after = UploadSession::query()->findOrFail($session->getKey());

    expect($after->status)->toBe(UploadStatus::Expired)
        ->and($after->assembled_blob_id)->toBeNull()
        ->and(Node::query()->count())->toBe($nodesBefore);
});

/*
 * commitAssembled() (the seam through which the controller runs assemble→commit in THE SAME
 * critical section as PATCH) must reread the status under the lock: a session that went
 * terminal between the last chunk and the commit is rejected with a conflict, and the commit itself
 * is never called.
 */
it('commitAssembled() rereads the status under the lock and does not commit a terminal session', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 6, parentId: $storage->root_node_id);

    app(StagedUpload::class)->append($session, UploadTestHelpers::streamFor('abcdef'), 0);
    app(UploadSessionStoreRepository::class)->advanceReceived($session, 6);

    // The snapshot the controller came to commit with; the row has already been reclaimed by the sweeper.
    $stale = UploadSession::query()->findOrFail($session->getKey());
    app(UploadSessionManager::class)->expire($session->refresh());

    expect(fn () => app(UploadSessionManager::class)->commitAssembled(
        $stale,
        static fn (UploadSession $locked): Node => throw new RuntimeException('the commit of a terminal session must not be called'),
    ))->toThrow(UploadConflictException::class);

    expect(UploadSession::query()->findOrFail($session->getKey())->status)->toBe(UploadStatus::Expired);
});

/*
 * putPart() (S3 multipart) stayed OUTSIDE the critical section: it took no lock and checked
 * isOpen() on the snapshot the controller had read BEFORE authorization. A session reclaimed between
 * that read and the part write passed the check against a STALE model, and the part object
 * landed on disk for an already terminal session: scopeSweepable never selects it again, so the bytes
 * were orphaned forever. The same class of race that is closed for PATCH/expire/abort/commit.
 */
it('putPart() rereads the status under the lock: a part of a terminal session is not written', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, protocol: UploadProtocol::S3Multipart, totalSizeBytes: 100);

    // The snapshot the controller came to write the part with.
    $stale = UploadSession::query()->findOrFail($session->getKey());

    // While the part was in flight the sweep reclaimed the session: staged bytes removed, status terminal.
    app(UploadSessionManager::class)->expire($session->refresh());

    expect(fn () => app(S3MultipartHandler::class)->putPart(
        $stale,
        partNo: 1,
        byteOffset: 0,
        chunk: UploadTestHelpers::streamFor('0123456789'),
    ))->toThrow(ValidationException::class);

    // Before the fix: a part row and a part object on disk for an Expired session, orphaned bytes.
    expect(UploadPart::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists("tmp/uploads/{$stale->upload_id}.parts/1"))->toBeFalse()
        ->and(UploadSession::query()->findOrFail($session->getKey())->status)->toBe(UploadStatus::Expired);
});

/*
 * Sanity: a regular PATCH still works after the fix (the CAS lets an open session
 * through and append finds the staged object), so the fix did not break the happy path.
 */
it('the regular PATCH sequence still works', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage, totalSizeBytes: 6);

    $handler = app(TusUploadHandler::class);

    expect($handler->patch($session->fresh(), UploadTestHelpers::streamFor('abc'), 0)['offset'])->toBe(3);

    $result = $handler->patch($session->fresh(), UploadTestHelpers::streamFor('def'), 3);

    expect($result)->toBe(['offset' => 6, 'complete' => true])
        ->and(UploadSession::query()->findOrFail($session->getKey())->status)->toBe(UploadStatus::Uploading);
});
