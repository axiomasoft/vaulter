<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Vaulter\Collaboration\Actions\PersistCollaborationStateAction;
use Vaulter\Collaboration\Data\PersistCollaborationStateInput;
use Vaulter\Collaboration\Tests\Concurrency\RaceFixtures;
use Vaulter\Collaboration\Tests\Support\DocumentTestHelpers;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Drives\ArchiveDriveAction;
use Vaulter\Drive\Actions\Drives\DeleteDriveAction;
use Vaulter\Drive\Actions\Trash\TrashNodeAction;
use Vaulter\Drive\Data\Trash\TrashNodeInput;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Models\Node;
use Vaulter\Testing\Concurrency\ForkRace;

/**
 * PLAN2.F05/F06 on real backends, for the collaboration store: a hub store racing a trash, an archive or a deletion of
 * the node/drive either lands entirely before it or is refused after it, with state, history and events matching the
 * outcome. Two forked processes on two PostgreSQL connections, released together by the barrier.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this, app(VaulterDatabase::class)->connection()->getName());
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
});

function hubStore(Node $node, mixed $context): mixed
{
    return app(PersistCollaborationStateAction::class)->execute(new PersistCollaborationStateInput(
        node: $node, yjsState: 'raced-state', content: DocumentTestHelpers::content('raced')->toArray(),
        actorId: $context->actor->ref()->id, expectedEpoch: 0,
    ));
}

it('a hub store racing a trash lands before it or is refused after it, never after', function (): void {
    $node = RaceFixtures::document();
    $nodeId = $node->getKey();
    $before = [DocumentTestHelpers::document($node)->state_version, DocumentTestHelpers::revisions($node)->count()];

    $results = RaceFixtures::race([
        static fn (): array => RaceFixtures::attempt(static fn () => app(TrashNodeAction::class)->execute(
            DocumentTestHelpers::context(Node::query()->findOrFail($nodeId)), new TrashNodeInput(Node::query()->findOrFail($nodeId)),
        )),
        static fn (): array => RaceFixtures::attempt(static fn () => hubStore(Node::query()->findOrFail($nodeId), DocumentTestHelpers::context(Node::query()->findOrFail($nodeId)))),
    ]);

    $fresh = Node::query()->findOrFail($nodeId);
    $after = [DocumentTestHelpers::document($fresh)->state_version, DocumentTestHelpers::revisions($fresh)->count()];
    expect($results[0]['kind'])->toBe('committed')
        ->and($fresh->trashed_at)->not->toBeNull()
        ->and($results[0]['pid'])->not->toBe($results[1]['pid'])
        ->and($results[1]['kind'])->toBeIn(['committed', 'node_not_found']);
    if ($results[1]['kind'] === 'node_not_found') {
        expect($after)->toBe($before)->and($results[1]['events'])->toBe(0);
    }
});

it('a hub store racing an archive of its drive lands before it or is refused after it', function (): void {
    $node = RaceFixtures::document();
    $nodeId = $node->getKey();
    $before = [DocumentTestHelpers::document($node)->state_version, DocumentTestHelpers::revisions($node)->count()];

    $results = RaceFixtures::race([
        static fn (): array => RaceFixtures::attempt(static fn () => app(ArchiveDriveAction::class)->execute(
            DocumentTestHelpers::context(Node::query()->findOrFail($nodeId)),
        )),
        // A user actor: the system actor is not held back by an archive.
        static fn (): array => RaceFixtures::attempt(static fn () => hubStore(Node::query()->findOrFail($nodeId), DocumentTestHelpers::context(Node::query()->findOrFail($nodeId), 5))),
    ]);

    $fresh = Node::query()->findOrFail($nodeId);
    $after = [DocumentTestHelpers::document($fresh)->state_version, DocumentTestHelpers::revisions($fresh)->count()];
    expect($results[0]['kind'])->toBe('committed')
        ->and($fresh->storage()->firstOrFail()->status)->toBe(DriveStatus::Archived)
        ->and($results[1]['kind'])->toBeIn(['committed', 'permission_denied']);
    if ($results[1]['kind'] === 'permission_denied') {
        expect($after)->toBe($before)->and($results[1]['events'])->toBe(0);
    }
});

it('a collaboration store racing drive deletion either commits before deletion or changes nothing', function (): void {
    Queue::fake();
    $node = RaceFixtures::document();
    $nodeId = $node->getKey();
    $before = [DocumentTestHelpers::document($node)->state_version, DocumentTestHelpers::revisions($node)->count()];
    $results = RaceFixtures::race([
        static fn (): array => RaceFixtures::attempt(static fn () => app(DeleteDriveAction::class)->execute(DocumentTestHelpers::context(Node::query()->findOrFail($nodeId)))),
        static fn (): array => RaceFixtures::attempt(static fn () => hubStore(Node::query()->findOrFail($nodeId), DocumentTestHelpers::context(Node::query()->findOrFail($nodeId), 5))),
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
