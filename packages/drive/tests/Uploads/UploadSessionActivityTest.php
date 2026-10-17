<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Testing\Activity\ActivityTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Facades\Vaulter;
use Vaulter\Testing\Fixtures\TestUser;
use Vaulter\ValueObjects\Access\ActorRef;

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
    $storage = Vaulter::drive(DriveTestHelpers::host())->model();

    $uploadId = $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/uploads', [
        'name' => 'activity-init.bin',
        'total_size_bytes' => 4,
    ])->json('data.upload_id');

    $row = ActivityTestHelpers::only(DriveEventType::UploadInitiated->value);

    expect($row->actor_id)->toEqual(3)
        ->and($row->drive_id)->toEqual($storage->getKey())
        ->and($row->context['upload_id'])->toBe($uploadId);
});

it('abort пишет activity-строку upload.aborted (F-P1.4-13, red-before: нет строки)', function (): void {
    $storage = Vaulter::drive(DriveTestHelpers::host())->model();

    $uploadId = $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/uploads', [
        'name' => 'activity-abort.bin',
        'total_size_bytes' => 4,
    ])->json('data.upload_id');
    app(VaulterDatabase::class)->write(fn () => Activity::query()->delete());

    $this->delete('api/vaulter/v1/uploads/'.$uploadId, [], ['Tus-Resumable' => '1.0.0'])->assertNoContent();

    $row = ActivityTestHelpers::only(DriveEventType::UploadAborted->value);

    expect($row->actor_id)->toEqual(3)
        ->and($row->context['upload_id'])->toBe($uploadId);
});

it('sweep-expire пишет activity-строку upload.expired без актора (F-P1.4-13, red-before: нет строки)', function (): void {
    $storage = Vaulter::drive(DriveTestHelpers::host())->model();

    $uploadId = $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/uploads', [
        'name' => 'activity-expire.bin',
        'total_size_bytes' => 4,
    ])->json('data.upload_id');
    app(VaulterDatabase::class)->write(fn () => Activity::query()->delete());

    app(VaulterDatabase::class)->write(fn () => UploadSession::query()
        ->where('upload_id', $uploadId)
        ->update(['expires_at' => now()->subHour()]));

    // The sweeper is a console/schedule path with no HTTP actor: the expiry is recorded
    // as the system actor, whoever is logged in.
    Auth::logout();

    $this->artisan('vaulter:uploads:sweep')->assertSuccessful();

    $row = ActivityTestHelpers::only(DriveEventType::UploadExpired->value);

    expect($row->actor_id)->toBeNull()
        ->and($row->actor_type)->toBe(ActorRef::SYSTEM_TYPE)
        ->and($row->context['upload_id'])->toBe($uploadId);
});

/**
 * P4.10 morph-invariant (ADR-0017 namespace move). The pre-mortem R-refactor risk is a
 * forgotten morph alias: a moved model whose OLD FQCN is frozen in a polymorphic
 * `*_type` column would silently break in production while a fresh-DB test stays green.
 * This guards the invariant that makes the move safe WITHOUT any DB migration: the moved
 * upload models are never persisted as a morph-type string. The upload lifecycle records
 * activity without an activity target, so no `v_activity` row ever holds
 * `UploadSession`'s FQCN — the move cannot orphan a row. The Vaulter models persisted as
 * `target_type` are `Node` and `Drive`, stored under constant aliases (D34), so a move of
 * either class cannot orphan a historical row. A future regression that made an
 * upload a morph target (or moved `Node`) trips this.
 */
it('переезд Uploads morph-безопасен: upload-session activity не пишет target_type/target_id, единственный morph-таргет Node не переезжал (P4.10)', function (): void {
    $storage = Vaulter::drive(DriveTestHelpers::host())->model();

    $uploadId = $this->postJson('api/vaulter/v1/drives/'.$storage->getKey().'/uploads', [
        'name' => 'morph-invariant.bin',
        'total_size_bytes' => 4,
    ])->json('data.upload_id');

    $row = ActivityTestHelpers::only(DriveEventType::UploadInitiated->value);

    expect(UploadSession::class)->toBe(UploadSession::class)
        ->and($row->target_type)->toBeNull()
        ->and($row->target_id)->toBeNull()
        ->and((new Node)->getMorphClass())->toBe(Node::MORPH_ALIAS);
});
