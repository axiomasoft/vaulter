<?php

declare(strict_types=1);

use Vaulter\Collaboration\Actions\PersistCollaborationStateAction;
use Vaulter\Collaboration\Data\PersistCollaborationStateInput;
use Vaulter\Collaboration\Exceptions\CollaborationEpochMismatchException;
use Vaulter\Collaboration\Exceptions\CollaborationSeedConflictException;
use Vaulter\Collaboration\Tests\Collaboration\HubTestHelpers;
use Vaulter\Collaboration\Tests\Support\DocumentTestHelpers;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Revisions\RestoreDocumentRevisionInput;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Testing\Concurrency\ForkRace;
use Vaulter\Testing\Concurrency\RaceOutcome;

/**
 * The storage fence under real concurrency (PostgreSQL, separate backends): a hub
 * store of the room opened before a restore either commits before the restore (and is
 * replaced by it) or is refused — the restored document never ends up with the old
 * room's state. Two hub instances seeding the same empty document: exactly one wins.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this, app(VaulterDatabase::class)->connection()->getName());
});

function fenceRaceDocument(): Node
{
    $drive = DocumentTestHelpers::drive();

    return app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Fence race',
        content: DocumentTestHelpers::content('restored'),
        parent: DriveTestHelpers::model($drive->root()),
    ));
}

/** @return array{kind: string, pid: int} */
function fenceRaceWrite(Closure $write): array
{
    $pid = (int) app(VaulterDatabase::class)->connection()->selectOne('select pg_backend_pid() as pid')->pid;

    try {
        $write();

        return ['kind' => 'committed', 'pid' => $pid];
    } catch (CollaborationEpochMismatchException) {
        return ['kind' => 'epoch_mismatch', 'pid' => $pid];
    } catch (CollaborationSeedConflictException) {
        return ['kind' => 'seed_conflict', 'pid' => $pid];
    }
}

it('serializes a store of the old room against a restore: never the old state at the new epoch', function (): void {
    $node = fenceRaceDocument();
    $first = DocumentTestHelpers::head($node);
    // The room has been editing: Yjs state exists at epoch 0.
    app(PersistCollaborationStateAction::class)->execute(new PersistCollaborationStateInput(
        node: $node, yjsState: 'room-state', content: HubTestHelpers::tiptap('room edit'), expectedEpoch: 0,
    ));

    $outcomes = ForkRace::run([
        static fn (): array => fenceRaceWrite(static fn () => app(PersistCollaborationStateAction::class)->execute(new PersistCollaborationStateInput(
            node: $node, yjsState: 'late-room-state', content: HubTestHelpers::tiptap('late edit'), expectedEpoch: 0,
        ))),
        static fn (): array => fenceRaceWrite(static fn () => app(RestoreDocumentRevisionAction::class)->execute(
            DocumentTestHelpers::context($node, actorId: 1), new RestoreDocumentRevisionInput(node: $node, revision: $first),
        )),
    ], connections: [app(VaulterDatabase::class)->connection()->getName()]);

    foreach ($outcomes as $outcome) {
        expect($outcome->ok)->toBeTrue($outcome->message ?? '');
    }
    $results = array_map(static fn (RaceOutcome $outcome): array => $outcome->value, $outcomes);
    $document = DocumentTestHelpers::document($node);

    expect(count(array_unique(array_column($results, 'pid'))))->toBe(2)
        ->and($results[1]['kind'])->toBe('committed')
        // Store first then restore, or restore first then a refused store.
        ->and($results[0]['kind'])->toBeIn(['committed', 'epoch_mismatch'])
        ->and($document->collab_epoch)->toBe(1)
        ->and($document->yjs_state)->toBeNull()
        ->and($document->tipTapContent()->toArray())->toBe(DocumentTestHelpers::content('restored')->toArray());
});

it('lets exactly one of two concurrent seeds of an empty document through', function (): void {
    $node = fenceRaceDocument();
    $seed = static fn (string $state): Closure => static fn (): array => fenceRaceWrite(static fn () => app(PersistCollaborationStateAction::class)->execute(
        new PersistCollaborationStateInput(node: $node, yjsState: $state, content: HubTestHelpers::tiptap('restored'), expectedEpoch: 0, seed: true),
    ));

    $outcomes = ForkRace::run([$seed('seed-a'), $seed('seed-b')], connections: [app(VaulterDatabase::class)->connection()->getName()]);

    foreach ($outcomes as $outcome) {
        expect($outcome->ok)->toBeTrue($outcome->message ?? '');
    }
    $results = array_map(static fn (RaceOutcome $outcome): array => $outcome->value, $outcomes);
    $kinds = array_column($results, 'kind');
    sort($kinds);
    $document = DocumentTestHelpers::document($node);

    expect($kinds)->toBe(['committed', 'seed_conflict'])
        ->and(count(array_unique(array_column($results, 'pid'))))->toBe(2)
        ->and($document->yjs_state)->toBeIn(['seed-a', 'seed-b'])
        ->and($document->state_version)->toBe(2);
});
