<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Vaulter\Drive\Actions\Drives\ArchiveDriveAction;
use Vaulter\Drive\Actions\Drives\DeleteDriveAction;
use Vaulter\Drive\Actions\Nodes\CreateFolderAction;
use Vaulter\Drive\Actions\Nodes\RenameNodeAction;
use Vaulter\Drive\Data\Nodes\CreateFolderInput;
use Vaulter\Drive\Data\Nodes\RenameNodeInput;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Testing\Concurrency\ForkRace;
use Vaulter\Testing\Concurrency\RaceOutcome;
use Vaulter\ValueObjects\Access\Actor;

/**
 * On real backends: an archive racing a user's mutation of the same drive
 * serialises on the tree lock. The mutation lands entirely before the archive or is
 * refused with drive_archived after it; nothing is written into an archived drive.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
});

it('an archive does not hold back a system create racing it: both land', function (): void {
    $drive = DriveTestHelpers::drive();
    $driveId = $drive->model()->getKey();
    $rootId = DriveTestHelpers::model($drive->root())->getKey();
    $count = Node::query()->where('drive_id', $driveId)->count();

    $outcomes = ForkRace::run([
        static fn (): string => (string) app(ArchiveDriveAction::class)->execute(DriveContext::system(Drive::query()->findOrFail($driveId), 'test fixture'))->status->value,
        static fn (): string => (string) app(CreateFolderAction::class)->execute(
            DriveContext::system(Drive::query()->findOrFail($driveId), 'race-system'),
            new CreateFolderInput(storage: Drive::query()->findOrFail($driveId), name: 'late', parent: Node::query()->findOrFail($rootId)),
        )->getKey(),
    ]);

    /** @var RaceOutcome $archive */
    [$archive, $create] = $outcomes;
    expect($archive->ok)->toBeTrue($archive->message ?? '')
        ->and(Drive::query()->findOrFail($driveId)->status)->toBe(DriveStatus::Archived);
    // The system actor is not held back by an archive, in either order.
    expect($create->ok)->toBeTrue($create->message ?? '')
        ->and(Node::query()->where('drive_id', $driveId)->count())->toBe($count + 1);
});

it('a user rename racing an archive lands before it or is refused after it, never into the archived drive', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('old');
    $driveId = $drive->model()->getKey();
    $folderId = DriveTestHelpers::model($folder)->getKey();
    $user = DriveTestHelpers::actor($drive, 61);
    $userClass = $user::class;

    $outcomes = ForkRace::run([
        static fn (): string => (string) app(ArchiveDriveAction::class)->execute(DriveContext::system(Drive::query()->findOrFail($driveId), 'test fixture'))->status->value,
        static function () use ($driveId, $folderId, $userClass): string {
            $actor = new $userClass;
            $actor->forceFill(['id' => 61]);
            $actor->exists = true;

            return (string) app(RenameNodeAction::class)->execute(
                DriveContext::for(Drive::query()->findOrFail($driveId), Actor::user($actor), 'race'),
                new RenameNodeInput(node: Node::query()->findOrFail($folderId), name: 'new'),
            )->getKey();
        },
    ]);

    [$archive, $rename] = $outcomes;
    $name = Node::query()->findOrFail($folderId)->name;
    expect($archive->ok)->toBeTrue($archive->message ?? '')
        ->and(Drive::query()->findOrFail($driveId)->status)->toBe(DriveStatus::Archived);
    if ($rename->ok) {
        expect($name)->toBe('new');
    } else {
        expect($rename->failedWith(DriveArchivedException::class))->toBeTrue($rename->exception.': '.$rename->message)
            ->and($name)->toBe('old');
    }
});

it('a user rename racing a delete lands before it or finds the drive gone', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('old');
    $driveId = $drive->model()->getKey();
    $folderId = DriveTestHelpers::model($folder)->getKey();
    $user = DriveTestHelpers::actor($drive, 62);
    $userClass = $user::class;
    Queue::fake();

    $outcomes = ForkRace::run([
        static fn (): string => (string) app(DeleteDriveAction::class)->execute(DriveContext::system(Drive::query()->findOrFail($driveId), 'test fixture'))->status->value,
        static function () use ($driveId, $folderId, $userClass): string {
            $actor = new $userClass;
            $actor->forceFill(['id' => 62]);
            $actor->exists = true;

            return (string) app(RenameNodeAction::class)->execute(
                DriveContext::for(Drive::query()->findOrFail($driveId), Actor::user($actor), 'race'),
                new RenameNodeInput(node: Node::query()->findOrFail($folderId), name: 'new'),
            )->getKey();
        },
    ]);

    [$delete, $rename] = $outcomes;
    expect($delete->ok)->toBeTrue($delete->message ?? '')
        ->and(Drive::query()->findOrFail($driveId)->status)->toBe(DriveStatus::Deleting);
    if (!$rename->ok) {
        expect($rename->failedWith(NodeNotFoundException::class))->toBeTrue($rename->exception.': '.$rename->message)
            ->and(Node::query()->findOrFail($folderId)->name)->toBe('old');
    }
});
