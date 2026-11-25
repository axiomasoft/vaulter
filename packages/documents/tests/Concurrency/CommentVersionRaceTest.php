<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Comments\DeleteCommentAction;
use Vaulter\Documents\Actions\Comments\ResolveCommentAction;
use Vaulter\Documents\Data\Comments\DeleteCommentInput;
use Vaulter\Documents\Data\Comments\ResolveCommentInput;
use Vaulter\Documents\Exceptions\CommentNotFoundException;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Repositories\Comments\NodeCommentStoreRepository;
use Vaulter\Documents\Tests\Concurrency\RaceFixtures;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Models\Node;
use Vaulter\Exceptions\Concurrency\StaleWriteException;
use Vaulter\Testing\Concurrency\ForkRace;
use Vaulter\Testing\Concurrency\RaceOutcome;

/**
 * On real backends (interfering pairs): a resolution and a deletion — or two
 * resolutions — of one comment that both read the same version race; the comment row lock serialises them and the
 * second one is refused: stale_write when the row is still there with a newer version, comment_not_found when the
 * deletion landed first. Exactly one lands. PostgreSQL/MySQL only (ForkRace); classified serial + database.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this, app(VaulterDatabase::class)->connection()->getName());
});

/** @param list<RaceOutcome> $outcomes */
function commentVersionOneLanded(array $outcomes): void
{
    $landed = array_values(array_filter($outcomes, static fn (RaceOutcome $outcome): bool => $outcome->ok));
    $refused = array_values(array_filter($outcomes, static fn (RaceOutcome $outcome): bool => !$outcome->ok));

    expect($landed)->toHaveCount(1)
        ->and($refused)->toHaveCount(1)
        ->and($refused[0]->failedWith(StaleWriteException::class) || $refused[0]->failedWith(CommentNotFoundException::class))->toBeTrue($refused[0]->exception.': '.$refused[0]->message);
}

it('lets one of a resolution and a deletion under the same version land and refuses the other', function (): void {
    $node = RaceFixtures::document();
    $seed = app(VaulterDatabase::class)->write(static fn (): NodeComment => app(NodeCommentStoreRepository::class)->create([
        'node_id' => $node->getKey(), 'author_type' => 'user', 'author_id' => 5, 'body' => 'seed', 'version' => 0,
    ]));
    $nodeId = $node->getKey();
    $seedId = $seed->getKey();

    $outcomes = ForkRace::run([
        static fn (): string => (string) app(ResolveCommentAction::class)->execute(
            DocumentTestHelpers::context(Node::query()->findOrFail($nodeId), 5),
            new ResolveCommentInput(NodeComment::query()->findOrFail($seedId), expectedVersion: 0),
        )->version,
        static function () use ($nodeId, $seedId): string {
            app(DeleteCommentAction::class)->execute(
                DocumentTestHelpers::context(Node::query()->findOrFail($nodeId), 5),
                new DeleteCommentInput(NodeComment::query()->findOrFail($seedId), expectedVersion: 0),
            );

            return 'deleted';
        },
    ]);

    commentVersionOneLanded($outcomes);
    $row = NodeComment::query()->find($seedId);
    expect($row === null || $row->version === 1)->toBeTrue();
});

it('lets one of two resolutions under the same version land and refuses the other with stale_write', function (): void {
    $node = RaceFixtures::document();
    $seed = app(VaulterDatabase::class)->write(static fn (): NodeComment => app(NodeCommentStoreRepository::class)->create([
        'node_id' => $node->getKey(), 'author_type' => 'user', 'author_id' => 5, 'body' => 'seed', 'version' => 0,
    ]));
    $nodeId = $node->getKey();
    $seedId = $seed->getKey();
    $resolve = static fn (): string => (string) app(ResolveCommentAction::class)->execute(
        DocumentTestHelpers::context(Node::query()->findOrFail($nodeId), 5),
        new ResolveCommentInput(NodeComment::query()->findOrFail($seedId), expectedVersion: 0),
    )->version;

    $outcomes = ForkRace::run([$resolve, $resolve]);

    commentVersionOneLanded($outcomes);
    expect(NodeComment::query()->findOrFail($seedId)->version)->toBe(1);
});
