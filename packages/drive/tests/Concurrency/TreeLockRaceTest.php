<?php

declare(strict_types=1);

use Vaulter\Drive\Actions\CreateFolderAction;
use Vaulter\Drive\Actions\TrashNodeAction;
use Vaulter\Drive\Data\CreateDriveFolderData;
use Vaulter\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Concurrency\ForkRace;
use Vaulter\Tests\Concurrency\RaceOutcome;
use Vaulter\Tests\Drive\DriveTestHelpers;

/**
 * A create under a folder must not slip past a concurrent trash of that folder's
 * ancestor: the trash reads the subtree once, so a child committed after that read
 * would stay live under a trashed folder. Creates hold the tree lock in shared mode
 * and trash holds it exclusively, so one runs entirely before the other.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
});

it('never leaves a live node under a folder trashed concurrently', function (): void {
    $drive = DriveTestHelpers::drive();
    $storageId = $drive->storage()->getKey();
    $top = $drive->createFolder('Top');
    $inner = $drive->createFolder('Inner', $top);
    $topId = $top->getKey();
    $innerId = $inner->getKey();

    $outcomes = ForkRace::run([
        static fn (): string => (string) app(TrashNodeAction::class)->execute(Node::query()->findOrFail($topId))->getKey(),
        static fn (): string => (string) app(CreateFolderAction::class)->execute(new CreateDriveFolderData(
            storage: Drive::query()->findOrFail($storageId),
            name: 'Late',
            parent: Node::query()->findOrFail($innerId),
        ))->getKey(),
    ]);

    expect($outcomes[0]->ok)->toBeTrue("trash failed with {$outcomes[0]->exception}: {$outcomes[0]->message}");

    /** @var RaceOutcome $create */
    $create = $outcomes[1];

    if ($create->ok) {
        // The create ran first, so the trash took the new folder with it.
        expect(Node::query()->findOrFail($create->value)->trashed_at)->not->toBeNull();
    } else {
        expect($create->exception)->toBe(InvalidDestinationException::class);
    }

    expect(Node::query()->where('parent_id', $innerId)->whereNull('trashed_at')->count())->toBe(0);
});
