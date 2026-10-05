<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Enums\Events\EventType;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Activity\ActivityTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * F-P1.4-13: initiate/abort/expire had NO activity trace at all before this fix —
 * only the terminal commit (FileUploaded/NodeVersionCommitted) landed a row. These
 * pin the three new lifecycle events (§2.9, one row per event, no fan-out).
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    $this->actingAs((new TestUser)->forceFill(['id' => 3]));
});

it('initiate пишет activity-строку upload.initiated с актором и upload_id (F-P1.4-13, red-before: нет строки)', function (): void {
    $storage = Vaulter::for(DriveTestHelpers::host())->model();

    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'activity-init.bin',
        'total_size_bytes' => 4,
    ])->json('data.upload_id');

    $row = ActivityTestHelpers::only(EventType::UploadInitiated->value);

    expect($row->actor_id)->toEqual(3)
        ->and($row->drive_id)->toEqual($storage->getKey())
        ->and($row->context['upload_id'])->toBe($uploadId);
});

it('abort пишет activity-строку upload.aborted (F-P1.4-13, red-before: нет строки)', function (): void {
    $storage = Vaulter::for(DriveTestHelpers::host())->model();

    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'activity-abort.bin',
        'total_size_bytes' => 4,
    ])->json('data.upload_id');
    Activity::query()->delete();

    $this->delete('api/vaulter/resumable-uploads/'.$uploadId, [], ['Tus-Resumable' => '1.0.0'])->assertNoContent();

    $row = ActivityTestHelpers::only(EventType::UploadAborted->value);

    expect($row->actor_id)->toEqual(3)
        ->and($row->context['upload_id'])->toBe($uploadId);
});

it('sweep-expire пишет activity-строку upload.expired без актора (F-P1.4-13, red-before: нет строки)', function (): void {
    $storage = Vaulter::for(DriveTestHelpers::host())->model();

    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'activity-expire.bin',
        'total_size_bytes' => 4,
    ])->json('data.upload_id');
    Activity::query()->delete();

    UploadSession::query()
        ->where('upload_id', $uploadId)
        ->update(['expires_at' => now()->subHour()]);

    // The sweeper is a console/schedule path with no HTTP actor: log out the
    // request-scoped actingAs user so ActivityRecorder's auth()->user() fallback
    // (used only when the event carries no actorId) has nothing to fall back to —
    // the same as a real `schedule:run` process.
    Auth::logout();

    $this->artisan('vaulter:sweep-uploads')->assertSuccessful();

    $row = ActivityTestHelpers::only(EventType::UploadExpired->value);

    expect($row->actor_id)->toBeNull()
        ->and($row->context['upload_id'])->toBe($uploadId);
});

/**
 * P4.10 morph-invariant (ADR-0017 namespace move). The pre-mortem R-refactor risk is a
 * forgotten morph alias: a moved model whose OLD FQCN is frozen in a polymorphic
 * `*_type` column would silently break in production while a fresh-DB test stays green.
 * This guards the invariant that makes the move safe WITHOUT any DB migration: the moved
 * upload models are never persisted as a morph-type string. The upload lifecycle records
 * activity with `target = null` (only node-attached events use `recordForNode`), so no
 * `v_activity` row ever holds `UploadSession`'s FQCN — the move cannot orphan a row. The
 * ONE Vaulter model that IS persisted as `target_type` is `Node` (via recordForNode), and
 * Node did NOT move, so historical rows stay resolvable. A future regression that made an
 * upload a morph target (or moved `Node`) trips this.
 */
it('переезд Uploads morph-безопасен: upload-session activity не пишет target_type/target_id, единственный morph-таргет Node не переезжал (P4.10)', function (): void {
    $storage = Vaulter::for(DriveTestHelpers::host())->model();

    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'morph-invariant.bin',
        'total_size_bytes' => 4,
    ])->json('data.upload_id');

    $row = ActivityTestHelpers::only(EventType::UploadInitiated->value);

    expect(UploadSession::class)->toBe(UploadSession::class)
        ->and($row->target_type)->toBeNull()
        ->and($row->target_id)->toBeNull()
        ->and((new Node)->getMorphClass())->toBe(Node::class);
});
