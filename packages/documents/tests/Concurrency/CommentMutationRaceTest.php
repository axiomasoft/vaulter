<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Comments\AddCommentAction;
use Vaulter\Documents\Actions\Comments\DeleteCommentAction;
use Vaulter\Documents\Actions\Comments\ResolveCommentAction;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Data\Comments\DeleteCommentInput;
use Vaulter\Documents\Data\Comments\ResolveCommentInput;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Repositories\Comments\NodeCommentStoreRepository;
use Vaulter\Documents\Tests\Concurrency\RaceFixtures;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Actions\Drives\ArchiveDriveAction;
use Vaulter\Drive\Actions\Trash\TrashNodeAction;
use Vaulter\Drive\Data\Trash\TrashNodeInput;
use Vaulter\Drive\Models\Node;
use Vaulter\Testing\Concurrency\ForkRace;

/**
 * Comment writers take T (shared) → N → the comment row, so a trash or
 * an archive that wins the race leaves no comment, resolve or delete behind, and one
 * that loses is applied in full. Real PostgreSQL backends, one per contender.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this, app(VaulterDatabase::class)->connection()->getName());
});

function commentRaceNode(): array
{
    $node = RaceFixtures::document();
    $seed = app(VaulterDatabase::class)->write(static fn (): NodeComment => app(NodeCommentStoreRepository::class)->create([
        'node_id' => $node->getKey(), 'author_type' => 'user', 'author_id' => 5, 'body' => 'seed',
    ]));

    return [$node, $seed];
}

function commentRaceWriters(): array
{
    return [
        'add' => fn (Node $node, NodeComment $seed, $context) => app(AddCommentAction::class)->execute($context, $node, new AddCommentInput('raced')),
        'resolve' => fn (Node $node, NodeComment $seed, $context) => app(ResolveCommentAction::class)->execute($context, new ResolveCommentInput($seed)),
        'delete' => fn (Node $node, NodeComment $seed, $context) => app(DeleteCommentAction::class)->execute($context, new DeleteCommentInput($seed)),
    ];
}

/** @return array{int, int, int} comments, resolved, total rows touched by the writer */
function commentRaceState(int|string $nodeId): array
{
    return [NodeComment::query()->where('node_id', $nodeId)->count(), NodeComment::query()->where('node_id', $nodeId)->whereNotNull('resolved_at')->count()];
}

it('a comment writer racing a trash is applied in full before it or refused entirely after it', function (string $writer): void {
    [$node, $seed] = commentRaceNode();
    $nodeId = $node->getKey();
    $seedId = $seed->getKey();
    $before = commentRaceState($nodeId);
    $write = commentRaceWriters()[$writer];

    $results = RaceFixtures::race([
        static fn (): array => RaceFixtures::attempt(static fn () => app(TrashNodeAction::class)->execute(
            DocumentTestHelpers::context(Node::query()->findOrFail($nodeId)), new TrashNodeInput(Node::query()->findOrFail($nodeId)),
        )),
        static fn (): array => RaceFixtures::attempt(static fn () => $write(Node::query()->findOrFail($nodeId), NodeComment::query()->findOrFail($seedId), DocumentTestHelpers::context(Node::query()->findOrFail($nodeId), 5))),
    ]);

    expect($results[0]['kind'])->toBe('committed')
        ->and(Node::query()->findOrFail($nodeId)->trashed_at)->not->toBeNull()
        ->and($results[1]['kind'])->toBeIn(['committed', 'node_not_found']);
    if ($results[1]['kind'] === 'node_not_found') {
        expect(commentRaceState($nodeId))->toBe($before);
    } else {
        expect(commentRaceState($nodeId))->not->toBe($before);
    }
})->with(array_keys(commentRaceWriters()));

it('a comment writer racing an archive is applied in full before it or refused entirely after it', function (string $writer): void {
    [$node, $seed] = commentRaceNode();
    $nodeId = $node->getKey();
    $seedId = $seed->getKey();
    $before = commentRaceState($nodeId);
    $write = commentRaceWriters()[$writer];

    $results = RaceFixtures::race([
        static fn (): array => RaceFixtures::attempt(static fn () => app(ArchiveDriveAction::class)->execute(DocumentTestHelpers::context(Node::query()->findOrFail($nodeId)))),
        static fn (): array => RaceFixtures::attempt(static fn () => $write(Node::query()->findOrFail($nodeId), NodeComment::query()->findOrFail($seedId), DocumentTestHelpers::context(Node::query()->findOrFail($nodeId), 5))),
    ]);

    expect($results[0]['kind'])->toBe('committed')
        ->and($results[1]['kind'])->toBeIn(['committed', 'drive_archived']);
    if ($results[1]['kind'] === 'drive_archived') {
        expect(commentRaceState($nodeId))->toBe($before);
    } else {
        expect(commentRaceState($nodeId))->not->toBe($before);
    }
})->with(array_keys(commentRaceWriters()));
