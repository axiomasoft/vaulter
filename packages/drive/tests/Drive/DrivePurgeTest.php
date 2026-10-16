<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Actions\Drives\DeleteDriveAction;
use Vaulter\Drive\Actions\Drives\PurgeDriveStepAction;
use Vaulter\Drive\Actions\Trash\PurgeNodeAction;
use Vaulter\Drive\Data\Trash\PurgeNodeInput;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Events\Drives\DriveDeleted;
use Vaulter\Drive\Jobs\PurgeDriveJob;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Enums\Drive\DriveStatus;
use Vaulter\Exceptions\Nodes\NodeNotTrashedException;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;
use Vaulter\ValueObjects\Access\Actor;

beforeEach(fn () => DriveTestHelpers::insideOperation());

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

function drivePurgeFixture(): array
{
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('Project');
    $sub = $drive->folders()->create('Sub', $folder);
    $file = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('purge me'), 'p.txt'), new UploadOptions(parent: $sub));
    $trashed = $drive->folders()->create('Old');
    $drive->nodes()->trash($trashed);

    return [$drive, $drive->model()->getKey(), $file];
}

it('delete marks the drive deleting once and queues one purge', function (): void {
    Queue::fake();
    [$drive, $id] = drivePurgeFixture();
    $context = DriveContext::system($drive->model(), 'test fixture');

    app(DeleteDriveAction::class)->execute($context);
    app(DeleteDriveAction::class)->execute($context); // a repeat is a no-op; the unique job is queued once

    expect(Drive::query()->findOrFail($id)->status)->toBe(DriveStatus::Deleting);
    Queue::assertPushed(PurgeDriveJob::class, 1);
});

it('purges every node, revision edge and the drive row, keeping activity as history', function (): void {
    Event::fake([DriveDeleted::class]);
    [$drive, $id] = drivePurgeFixture();
    $blob = Blob::query()->firstOrFail();
    expect($blob->versions()->count())->toBe(1);
    $history = Activity::query()->where('drive_id', $id)->count();
    Queue::fake();

    $drive->delete();
    while (!app(PurgeDriveStepAction::class)->execute($id)) {
        // batches until done
    }

    expect(Drive::query()->whereKey($id)->exists())->toBeFalse()
        ->and(Node::query()->where('drive_id', $id)->count())->toBe(0)
        ->and($blob->refresh()->versions()->count())->toBe(0)
        ->and(Activity::query()->where('drive_id', $id)->count())->toBeGreaterThanOrEqual($history);
    Event::assertDispatched(DriveDeleted::class, fn (DriveDeleted $event): bool => $event->driveId === (string) $id);
});

it('a finished or unknown drive purge does nothing', function (): void {
    [, $id] = drivePurgeFixture();

    expect(app(PurgeDriveStepAction::class)->execute($id))->toBeTrue() // active drive: not being deleted
        ->and(Drive::query()->whereKey($id)->exists())->toBeTrue()
        ->and(app(PurgeDriveStepAction::class)->execute(9_999_999))->toBeTrue();
});

it('purges in chunks and resumes after an interruption', function (): void {
    Queue::fake();
    $drive = DriveTestHelpers::drive();
    $id = $drive->model()->getKey();
    for ($i = 0; $i < PurgeDriveJob::BATCH + 5; $i++) {
        $drive->folders()->create('f'.$i);
    }
    $drive->delete();

    expect(app(PurgeDriveStepAction::class)->execute($id))->toBeFalse()
        ->and(Node::query()->where('drive_id', $id)->count())->toBe(PurgeDriveJob::BATCH + 5 + 1 - PurgeDriveJob::BATCH);
    // A new job instance (a restarted worker) finishes the work.
    expect(app(PurgeDriveStepAction::class)->execute($id))->toBeTrue()
        ->and(Drive::query()->whereKey($id)->exists())->toBeFalse()
        ->and(Node::query()->where('drive_id', $id)->count())->toBe(0);
});

it('aborts open upload sessions of the drive', function (): void {
    Queue::fake();
    $drive = DriveTestHelpers::drive();
    $model = $drive->model();
    $session = app(UploadSessionManager::class)->initiate(context: DriveContext::system($model, 'test fixture'), data: new InitiateUploadInput(
        protocol: UploadProtocol::Tus, targetParent: $model->rootNode()->first(), targetName: 'big.bin', totalSizeBytes: 10, mimeType: 'application/octet-stream',
    ));
    $drive->delete();

    app(PurgeDriveStepAction::class)->execute($model->getKey());

    expect(UploadSession::query()->whereKey($session->getKey())->value('status'))->toBeIn([UploadStatus::Aborted, UploadStatus::Aborted->value, null]);
});

it('bypassing the trash requirement is reserved for the system drive-purge actor', function (): void {
    [$drive, , $file] = drivePurgeFixture();
    $node = DriveTestHelpers::model($file);
    $before = Node::query()->count();

    foreach ([DriveContext::system($drive->model(), 'something-else'), DriveContext::for(drive: $drive->model(), actor: Actor::user(DriveTestHelpers::actor($drive, 41)), correlationId: 'x')] as $context) {
        expect(fn () => app(PurgeNodeAction::class)->execute($context, new PurgeNodeInput(node: $node, bypassTrashRequirement: true)))
            ->toThrow(InvalidArgumentException::class);
    }
    expect(Node::query()->count())->toBe($before);
    // Without the flag a live node is still refused.
    expect(fn () => app(PurgeNodeAction::class)->execute(DriveContext::system($drive->model(), 'drive-purge'), new PurgeNodeInput(node: $node)))
        ->toThrow(NodeNotTrashedException::class);
});

it('vaulter:drives:purge prints counts on --dry-run and purges with --sync for the selected drives', function (): void {
    Queue::fake();
    [$first, $firstId] = drivePurgeFixture();
    [$second, $secondId] = drivePurgeFixture();
    $first->delete();
    $second->delete();

    $this->artisan('vaulter:drives:purge', ['--dry-run' => true])->expectsOutputToContain('node')->assertSuccessful();
    expect(Drive::query()->whereKey($firstId)->exists())->toBeTrue();

    $this->artisan('vaulter:drives:purge', ['--drive' => [(string) $firstId], '--sync' => true])->assertSuccessful();
    expect(Drive::query()->whereKey($firstId)->exists())->toBeFalse()
        ->and(Drive::query()->whereKey($secondId)->exists())->toBeTrue();

    $this->artisan('vaulter:drives:purge')->assertSuccessful();
    Queue::assertPushed(PurgeDriveJob::class, fn (PurgeDriveJob $job): bool => (string) $job->driveId === (string) $secondId);
});

it('purges a quarantined revision through the same path: edges, rows and quota, no exception for scan status', function (): void {
    Queue::fake();
    [$drive, $id, $file] = drivePurgeFixture();
    $blob = Blob::query()->firstOrFail();
    $revision = FileRevision::query()->where('file_id', DriveTestHelpers::model($file)->file()->firstOrFail()->getKey())->firstOrFail();
    $revision->forceFill(['scan_status' => 'infected', 'quarantined_at' => now()])->save();
    $blob->forceFill(['quarantined_at' => now()])->save();

    $drive->delete();
    while (!app(PurgeDriveStepAction::class)->execute($id)) {
        // batches until done
    }

    expect(Drive::query()->whereKey($id)->exists())->toBeFalse()
        ->and($blob->refresh()->versions()->count())->toBe(0);
    // SQLite does not enforce the FK cascades that remove the revision rows with their node.
    if (DB::connection()->getDriverName() === 'pgsql') {
        expect(FileRevision::query()->whereKey($revision->getKey())->exists())->toBeFalse();
    }
});
