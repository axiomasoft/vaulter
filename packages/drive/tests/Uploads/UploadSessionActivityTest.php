<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Drive\Uploads\Models\UploadSession;
use Vaulter\Enums\Activity\ActivityVerb;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Activity\Activity;
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

it('initiate пишет activity-строку upload_session.initiated с актором и upload_id (F-P1.4-13, red-before: нет строки)', function (): void {
    $storage = Vaulter::for(DriveTestHelpers::host())->storage();

    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'activity-init.bin',
        'total_size_bytes' => 4,
    ])->json('data.upload_id');

    $row = ActivityTestHelpers::only(ActivityVerb::UploadSessionInitiated->value);

    expect($row->actor_id)->toEqual(3)
        ->and($row->storage_id)->toEqual($storage->getKey())
        ->and($row->context['upload_id'])->toBe($uploadId);
});

it('abort пишет activity-строку upload_session.aborted (F-P1.4-13, red-before: нет строки)', function (): void {
    $storage = Vaulter::for(DriveTestHelpers::host())->storage();

    $uploadId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'name' => 'activity-abort.bin',
        'total_size_bytes' => 4,
    ])->json('data.upload_id');
    Activity::query()->delete();

    $this->delete('api/vaulter/resumable-uploads/'.$uploadId, [], ['Tus-Resumable' => '1.0.0'])->assertNoContent();

    $row = ActivityTestHelpers::only(ActivityVerb::UploadSessionAborted->value);

    expect($row->actor_id)->toEqual(3)
        ->and($row->context['upload_id'])->toBe($uploadId);
});

it('sweep-expire пишет activity-строку upload_session.expired без актора (F-P1.4-13, red-before: нет строки)', function (): void {
    $storage = Vaulter::for(DriveTestHelpers::host())->storage();

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

    $row = ActivityTestHelpers::only(ActivityVerb::UploadSessionExpired->value);

    expect($row->actor_id)->toBeNull()
        ->and($row->context['upload_id'])->toBe($uploadId);
});
