<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Vaulter\Access\OperationGuard;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Actions\Files\DeleteFileRevisionAction;
use Vaulter\Drive\Actions\Files\IngestFileAction;
use Vaulter\Drive\Actions\Files\RestoreFileRevisionAction;
use Vaulter\Drive\Actions\Files\SetFileRevisionRetentionAction;
use Vaulter\Drive\Actions\Nodes\CopyNodeAction;
use Vaulter\Drive\Actions\Nodes\CreateFolderAction;
use Vaulter\Drive\Actions\Nodes\MoveNodeAction;
use Vaulter\Drive\Actions\Nodes\RenameNodeAction;
use Vaulter\Drive\Actions\Nodes\SetNodePropertiesAction;
use Vaulter\Drive\Actions\Trash\PurgeNodeAction;
use Vaulter\Drive\Actions\Trash\RestoreNodeAction;
use Vaulter\Drive\Actions\Trash\TrashNodeAction;
use Vaulter\Drive\Data\Files\IngestFileInput;
use Vaulter\Drive\Data\Files\RestoreFileRevisionInput;
use Vaulter\Drive\Data\Nodes\CopyNodeInput;
use Vaulter\Drive\Data\Nodes\CreateFolderInput;
use Vaulter\Drive\Data\Nodes\MoveNodeInput;
use Vaulter\Drive\Data\Nodes\RenameNodeInput;
use Vaulter\Drive\Data\Nodes\SetNodePropertiesInput;
use Vaulter\Drive\Data\Trash\PurgeNodeInput;
use Vaulter\Drive\Data\Trash\RestoreNodeInput;
use Vaulter\Drive\Data\Trash\TrashNodeInput;
use Vaulter\Drive\Events\Drives\DriveArchived;
use Vaulter\Drive\Events\Drives\DriveUnarchived;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Drive\DriveStatus;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Drive\DriveArchivedException;
use Vaulter\Exceptions\Drive\InvalidDriveStateException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;
use Vaulter\Testing\Fixtures\TestUser;
use Vaulter\ValueObjects\Access\Actor;

beforeEach(function (): void {
    Storage::fake('local');
    Event::fake([DriveArchived::class, DriveUnarchived::class]);
});

it('archive flips the status, returns a snapshot and unarchive reverts it', function (): void {
    $drive = DriveTestHelpers::drive();

    $archived = $drive->archive();
    expect($archived->status)->toBe(DriveStatus::Archived)
        ->and($drive->snapshot()->status)->toBe(DriveStatus::Archived);
    Event::assertDispatched(DriveArchived::class);

    expect($drive->unarchive()->status)->toBe(DriveStatus::Active);
    Event::assertDispatched(DriveUnarchived::class);
});

it('refuses an invalid transition with invalid_drive_state', function (): void {
    $drive = DriveTestHelpers::drive();

    expect(fn () => $drive->unarchive())->toThrow(InvalidDriveStateException::class);
    $drive->archive();
    expect(fn () => $drive->archive())->toThrow(InvalidDriveStateException::class);
    try {
        $drive->archive();
    } catch (InvalidDriveStateException $exception) {
        expect($exception->problemCode())->toBe('invalid_drive_state')->and($exception->httpStatus())->toBe(409);
    }
});

it('an archived drive refuses mutations, purge and creation of non-system actors but still reads', function (): void {
    config()->set('vaulter.access.permission_driver', 'database');
    $system = DriveTestHelpers::drive();
    $folder = $system->folders()->create('Docs');
    $trashed = $system->folders()->create('Old');
    $system->nodes()->trash($trashed);
    $user = DriveTestHelpers::actor($system, 31);
    $actor = $system->actingAs($user);

    $system->archive();

    expect(fn () => $actor->folders()->create('New'))->toThrow(DriveArchivedException::class)
        ->and(fn () => $actor->nodes()->rename($folder, 'Renamed'))->toThrow(DriveArchivedException::class)
        ->and(fn () => $actor->nodes()->trash($folder))->toThrow(DriveArchivedException::class)
        ->and(fn () => $actor->trash()->restore($trashed))->toThrow(DriveArchivedException::class)
        ->and(fn () => $actor->trash()->purge($trashed))->toThrow(DriveArchivedException::class)
        ->and(fn () => $actor->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('x'), 'x.bin'), new UploadOptions))
        ->toThrow(DriveArchivedException::class);

    // Reads and metadata stay open.
    expect($actor->root()->id)->toBe($system->root()->id)
        ->and($actor->nodes()->find($folder)->name)->toBe('Docs');
    // The system actor is not held back.
    expect($system->folders()->create('System')->name)->toBe('System');
});

it('an archived drive refuses grant and share changes', function (): void {
    config()->set('vaulter.access.permission_driver', 'database');
    config()->set('vaulter.features.share_links', true);
    $system = DriveTestHelpers::drive();
    $folder = $system->folders()->create('Docs');
    $user = DriveTestHelpers::actor($system, 32);
    $actor = $system->actingAs($user);
    $system->archive();

    expect(fn () => $actor->shares()->create($folder, new ShareLinkInput))->toThrow(DriveArchivedException::class);
});

it('archive and unarchive need Manage on the root or the system actor', function (): void {
    config()->set('vaulter.access.permission_driver', 'database');
    $system = DriveTestHelpers::drive();
    $reader = new TestUser;
    $reader->forceFill(['id' => 33]);
    $reader->exists = true;
    AccessTestHelpers::grant($system->root(), RoleRegistry::READER, subjectId: 33, subjectType: $reader->getMorphClass());

    expect(fn () => $system->actingAs($reader)->archive())->toThrow(PermissionDeniedException::class)
        ->and($system->snapshot()->status)->toBe(DriveStatus::Active);

    $owner = DriveTestHelpers::actor($system, 34);
    expect($system->actingAs($owner)->archive()->status)->toBe(DriveStatus::Archived)
        ->and($system->actingAs($owner)->unarchive()->status)->toBe(DriveStatus::Active);
});

it('a drive being deleted is absent for users and the handle', function (): void {
    Queue::fake();
    config()->set('vaulter.access.permission_driver', 'database');
    $system = DriveTestHelpers::drive();
    $folder = $system->folders()->create('Docs');
    $user = DriveTestHelpers::actor($system, 35);
    $actor = $system->actingAs($user);
    $model = $system->model();

    $deleted = $system->delete();
    expect($deleted->status)->toBe(DriveStatus::Deleting);

    expect(fn () => $actor->root())->toThrow(StorageNotFoundException::class)
        ->and(fn () => $system->snapshot())->toThrow(StorageNotFoundException::class);
    $context = DriveContext::system($model, 'test fixture');
    expect(fn () => app(OperationGuard::class)->authorize($context, Ability::View, DriveTestHelpers::model($folder)))
        ->toThrow(NodeNotFoundException::class);
});

/** The drive writers, each called past the guard with the node rows read BEFORE the archive. */
function archiveWriters(): array
{
    return [
        'create folder' => fn ($c, $f) => app(CreateFolderAction::class)->execute($c, new CreateFolderInput(storage: $f['drive']->model(), name: 'n', parent: $f['folder'])),
        'rename' => fn ($c, $f) => app(RenameNodeAction::class)->execute($c, new RenameNodeInput(node: $f['folder'], name: 'renamed')),
        'move' => fn ($c, $f) => app(MoveNodeAction::class)->execute($c, new MoveNodeInput(node: $f['folder'], into: $f['other'])),
        'copy' => fn ($c, $f) => app(CopyNodeAction::class)->execute($c, new CopyNodeInput(node: $f['folder'], into: $f['other'])),
        'trash' => fn ($c, $f) => app(TrashNodeAction::class)->execute($c, new TrashNodeInput(node: $f['folder'])),
        'restore' => fn ($c, $f) => app(RestoreNodeAction::class)->execute($c, new RestoreNodeInput(node: $f['trashed'], destination: $f['other'])),
        'purge' => fn ($c, $f) => app(PurgeNodeAction::class)->execute($c, new PurgeNodeInput(node: $f['trashed'])),
        'properties' => fn ($c, $f) => app(SetNodePropertiesAction::class)->execute($c, new SetNodePropertiesInput(node: $f['folder'], namespace: 'app', set: ['k' => 'v'])),
        'ingest' => fn ($c, $f) => app(IngestFileAction::class)->execute($c, new IngestFileInput(storage: $f['drive']->model(), stream: DriveTestHelpers::streamFor('late'), originalName: 'late.bin')),
        'delete revision' => fn ($c, $f) => app(DeleteFileRevisionAction::class)->execute($c, $f['file'], $f['oldRevision']),
        'restore revision' => fn ($c, $f) => app(RestoreFileRevisionAction::class)->execute($c, new RestoreFileRevisionInput(node: $f['file'], revision: $f['oldRevision'])),
        'revision retention' => fn ($c, $f) => app(SetFileRevisionRetentionAction::class)->execute($c, $f['file'], $f['oldRevision'], true),
    ];
}

it('every drive writer re-checks the status under its lock: a user context on an archived drive is refused', function (string $writer): void {
    $system = DriveTestHelpers::drive();
    $fixture = [
        'drive' => $system,
        'folder' => DriveTestHelpers::model($system->folders()->create('A')),
        'other' => DriveTestHelpers::model($system->folders()->create('B')),
        'trashed' => DriveTestHelpers::model($system->folders()->create('Old')),
    ];
    $system->nodes()->trash($fixture['trashed']->getKey());
    $file = $system->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('one'), 'f.bin'));
    $system->files()->addRevision($file, UploadSource::fromStream(DriveTestHelpers::streamFor('two!'), 'f.bin'));
    $fixture['file'] = DriveTestHelpers::model($file);
    $fixture['oldRevision'] = $fixture['file']->file()->firstOrFail()->versions()->orderBy('number')->firstOrFail();
    $user = DriveTestHelpers::actor($system, 71);
    $context = DriveContext::for($system->model(), Actor::user($user), 'archived-writer');
    $before = [Node::query()->count(), Node::query()->whereNotNull('trashed_at')->count(), FileRevision::query()->count(), $fixture['folder']->refresh()->name];
    $system->archive();

    expect(fn () => archiveWriters()[$writer]($context, $fixture))->toThrow(DriveArchivedException::class);
    expect([Node::query()->count(), Node::query()->whereNotNull('trashed_at')->count(), FileRevision::query()->count(), $fixture['folder']->refresh()->name])->toBe($before);
})->with(array_keys(archiveWriters()));
