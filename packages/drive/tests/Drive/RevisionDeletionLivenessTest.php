<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Actions\Files\DeleteFileRevisionAction;
use Vaulter\Drive\Events\Files\FileRevisionDeleted;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\FileRevisionBlob;
use Vaulter\Operations\DriveContext;
use Vaulter\ValueObjects\Access\Actor;

it('refuses a late revision deletion after trash without changing history, quota or CAS edges', function (bool $system): void {
    Storage::fake('local');
    $drive = DriveTestHelpers::drive();
    $file = $drive->files()->upload(UploadSource::fromStream(stream: DriveTestHelpers::streamFor('one'), name: 'a.txt'));
    $drive->files()->addRevision($file, UploadSource::fromStream(stream: DriveTestHelpers::streamFor('two'), name: 'a.txt'));
    $stale = DriveTestHelpers::model($file);
    $revision = $stale->file()->firstOrFail()->versions()->orderBy('number')->firstOrFail();
    $context = $system
        ? DriveContext::system($drive->model(), 'unrelated-maintenance')
        : DriveContext::for(drive: $drive->model(), actor: Actor::user(AccessTestHelpers::user(5)), correlationId: (string) Str::ulid());
    $drive->nodes()->trash($file);
    $used = $drive->model()->fresh()->used_bytes;
    $edges = FileRevisionBlob::query()->count();
    $history = $stale->file()->firstOrFail()->versions()->pluck('id')->all();
    Event::fake([FileRevisionDeleted::class]);

    expect(fn () => app(DeleteFileRevisionAction::class)->execute(context: $context, node: $stale, revision: $revision))->toThrow(NodeNotFoundException::class)
        ->and(FileRevision::query()->whereKey($revision->id)->exists())->toBeTrue()
        ->and($stale->file()->firstOrFail()->versions()->pluck('id')->all())->toBe($history)
        ->and($drive->model()->fresh()->used_bytes)->toBe($used)
        ->and(FileRevisionBlob::query()->count())->toBe($edges);
    Event::assertNotDispatched(FileRevisionDeleted::class);
})->with(['user' => false, 'other system reason' => true]);
