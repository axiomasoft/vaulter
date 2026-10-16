<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Collaboration\PersistCollaborationStateAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Revisions\CreateNamedRevisionAction;
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;
use Vaulter\Documents\Actions\Revisions\UpdateRevisionRetentionAction;
use Vaulter\Documents\Data\Collaboration\PersistCollaborationStateInput;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Data\Revisions\CreateNamedRevisionInput;
use Vaulter\Documents\Data\Revisions\RestoreDocumentRevisionInput;
use Vaulter\Documents\Data\Revisions\UpdateRevisionRetentionInput;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Concurrency\RaceFixtures;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Actions\Drives\ArchiveDriveAction;
use Vaulter\Drive\Actions\Drives\DeleteDriveAction;
use Vaulter\Drive\Actions\Trash\TrashNodeAction;
use Vaulter\Drive\Data\Trash\TrashNodeInput;
use Vaulter\Enums\Drive\DriveStatus;
use Vaulter\Models\Drive\Node;
use Vaulter\Testing\Concurrency\ForkRace;

/**
 * PLAN2.F05/F06 on real backends: a document writer racing a trash or an archive of
 * the same node/drive either lands entirely before it or is refused after it, with
 * state, history and events matching the outcome. Two forked processes on two
 * PostgreSQL connections, released together by the barrier.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this, app(VaulterDatabase::class)->connection()->getName());
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
});

function livenessRaceWriters(): array
{
    return [
        'collaboration' => fn (Node $node, $context) => app(PersistCollaborationStateAction::class)->execute(new PersistCollaborationStateInput(
            node: $node, yjsState: 'raced-state', content: DocumentTestHelpers::content('raced')->toArray(),
            actorId: $context->actor->ref()->id, expectedEpoch: 0,
        )),
        'update' => fn (Node $node, $context) => app(UpdateDocumentContentAction::class)->execute($context,
            new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content('raced'))),
        'named revision' => fn (Node $node, $context) => app(CreateNamedRevisionAction::class)->execute($context,
            new CreateNamedRevisionInput(node: $node, label: 'raced')),
        'restore' => fn (Node $node, $context) => app(RestoreDocumentRevisionAction::class)->execute($context,
            new RestoreDocumentRevisionInput(node: $node, revision: DocumentTestHelpers::head($node)->getKey())),
        'retention' => fn (Node $node, $context) => app(UpdateRevisionRetentionAction::class)->execute($context,
            new UpdateRevisionRetentionInput(node: $node, revision: DocumentTestHelpers::head($node)->getKey(), keepForever: true)),
    ];
}

it('a document writer racing a trash lands before it or is refused after it, never after', function (string $writer): void {
    $node = RaceFixtures::document();
    $driveId = $node->drive_id;
    $nodeId = $node->getKey();
    $before = [DocumentTestHelpers::document($node)->state_version, DocumentTestHelpers::revisions($node)->count()];
    $write = livenessRaceWriters()[$writer];

    $results = RaceFixtures::race([
        static fn (): array => RaceFixtures::attempt(static fn () => app(TrashNodeAction::class)->execute(
            DocumentTestHelpers::context(Node::query()->findOrFail($nodeId)), new TrashNodeInput(Node::query()->findOrFail($nodeId)),
        )),
        static fn (): array => RaceFixtures::attempt(static fn () => $write(Node::query()->findOrFail($nodeId), DocumentTestHelpers::context(Node::query()->findOrFail($nodeId)))),
    ]);

    $fresh = Node::query()->findOrFail($nodeId);
    $after = [DocumentTestHelpers::document($fresh)->state_version, DocumentTestHelpers::revisions($fresh)->count()];
    expect($results[0]['kind'])->toBe('committed')
        ->and($fresh->trashed_at)->not->toBeNull()
        ->and($results[0]['pid'])->not->toBe($results[1]['pid'])
        ->and($results[1]['kind'])->toBeIn(['committed', 'node_not_found']);
    if ($results[1]['kind'] === 'node_not_found') {
        // Refused after the trash: nothing of the writer survived.
        expect($after)->toBe($before)->and($results[1]['events'])->toBe(0);
    }
    // Whatever the order, the trash is the last word on the node.
    expect((string) $fresh->drive_id)->toBe((string) $driveId);
})->with(array_keys(livenessRaceWriters()));

it('a document writer racing an archive of its drive lands before it or is refused after it', function (string $writer): void {
    $node = RaceFixtures::document();
    $driveId = $node->drive_id;
    $nodeId = $node->getKey();
    $before = [DocumentTestHelpers::document($node)->state_version, DocumentTestHelpers::revisions($node)->count()];
    $write = livenessRaceWriters()[$writer];

    $results = RaceFixtures::race([
        static fn (): array => RaceFixtures::attempt(static fn () => app(ArchiveDriveAction::class)->execute(
            DocumentTestHelpers::context(Node::query()->findOrFail($nodeId)),
        )),
        // A user actor: the system actor is not held back by an archive.
        static fn (): array => RaceFixtures::attempt(static fn () => $write(Node::query()->findOrFail($nodeId), DocumentTestHelpers::context(Node::query()->findOrFail($nodeId), 5))),
    ]);

    $fresh = Node::query()->findOrFail($nodeId);
    $after = [DocumentTestHelpers::document($fresh)->state_version, DocumentTestHelpers::revisions($fresh)->count()];
    $refused = $writer === 'collaboration' ? 'permission_denied' : 'drive_archived';
    expect($results[0]['kind'])->toBe('committed')
        ->and($fresh->storage()->firstOrFail()->status)->toBe(DriveStatus::Archived)
        ->and($results[1]['kind'])->toBeIn(['committed', $refused]);
    if ($results[1]['kind'] === $refused) {
        expect($after)->toBe($before)->and($results[1]['events'])->toBe(0);
    }
    expect(DocumentRevision::query()->count())->toBeGreaterThanOrEqual($before[1]);
})->with(array_keys(livenessRaceWriters()));

it('a collaboration store racing drive deletion either commits before deletion or changes nothing', function (): void {
    Queue::fake();
    $node = RaceFixtures::document();
    $nodeId = $node->getKey();
    $before = [DocumentTestHelpers::document($node)->state_version, DocumentTestHelpers::revisions($node)->count()];
    $write = livenessRaceWriters()['collaboration'];
    $results = RaceFixtures::race([
        static fn (): array => RaceFixtures::attempt(static fn () => app(DeleteDriveAction::class)->execute(DocumentTestHelpers::context(Node::query()->findOrFail($nodeId)))),
        static fn (): array => RaceFixtures::attempt(static fn () => $write(Node::query()->findOrFail($nodeId), DocumentTestHelpers::context(Node::query()->findOrFail($nodeId), 5))),
    ]);
    $fresh = Node::query()->findOrFail($nodeId);
    expect($results[0]['kind'])->toBe('committed')
        ->and($fresh->storage()->firstOrFail()->status)->toBe(DriveStatus::Deleting)
        ->and($results[1]['kind'])->toBeIn(['committed', 'node_not_found']);
    if ($results[1]['kind'] === 'node_not_found') {
        expect([DocumentTestHelpers::document($fresh)->state_version, DocumentTestHelpers::revisions($fresh)->count()])->toBe($before)
            ->and($results[1]['events'])->toBe(0);
    }
});
