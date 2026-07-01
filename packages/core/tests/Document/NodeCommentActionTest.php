<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Vaulter\Actions\Documents\AddNodeCommentAction;
use Vaulter\Actions\Documents\DeleteNodeCommentAction;
use Vaulter\Actions\Documents\ResolveNodeCommentAction;
use Vaulter\Data\CreateNodeCommentData;
use Vaulter\Events\Documents\NodeCommentAdded;
use Vaulter\Events\Documents\NodeCommentDeleted;
use Vaulter\Events\Documents\NodeCommentResolved;
use Vaulter\Models\Documents\NodeComment;
use Vaulter\Support\Config;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The node-comment Actions (ADR-0014 шаг 1): add (threaded + anchored + morph-alias
 * author), resolve (tombstone + actor) and delete (permanent), each dispatching its
 * broadcast event on the node's collaboration channel.
 */
function nodeCommentData(?int $parentId = null): CreateNodeCommentData
{
    return new CreateNodeCommentData(
        body: 'looks good',
        authorType: (new TestUser)->getMorphClass(),
        authorId: 1,
        parentId: $parentId,
        anchor: ['from' => 1, 'to' => 4],
    );
}

it('adds a comment and dispatches NodeCommentAdded on the node channel', function (): void {
    Event::fake([NodeCommentAdded::class]);

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'commentable');

    $comment = app(AddNodeCommentAction::class)->execute($node, nodeCommentData());

    expect($comment->node_id)->toBe($node->getKey())
        ->and($comment->author_type)->toBe((new TestUser)->getMorphClass())
        ->and($comment->anchor)->toBe(['from' => 1, 'to' => 4])
        ->and($comment->isResolved())->toBeFalse();

    Event::assertDispatched(NodeCommentAdded::class, function (NodeCommentAdded $event) use ($node): bool {
        $channels = $event->broadcastOn();

        return $channels[0] instanceof PrivateChannel
            && $channels[0]->name === 'private-'.Config::channelPrefix().'.'.$node->getKey();
    });
});

it('threads a reply under its parent', function (): void {
    Event::fake();

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'threaded');

    $action = app(AddNodeCommentAction::class);
    $root = $action->execute($node, nodeCommentData());
    $reply = $action->execute($node, nodeCommentData(parentId: (int) $root->getKey()));

    expect($reply->parent_id)->toBe($root->getKey())
        ->and($root->replies()->count())->toBe(1);
});

it('resolves a comment, stamping resolved_at + resolved_by and dispatching the event', function (): void {
    Event::fake([NodeCommentResolved::class]);

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'resolvable');
    $comment = app(AddNodeCommentAction::class)->execute($node, nodeCommentData());

    $resolved = app(ResolveNodeCommentAction::class)->execute($comment, resolvedBy: 42);

    expect($resolved->isResolved())->toBeTrue()
        ->and($resolved->resolved_by)->toBe(42);

    Event::assertDispatched(NodeCommentResolved::class, fn (NodeCommentResolved $e): bool => $e->actorId === 42);
});

it('permanently deletes a comment and dispatches NodeCommentDeleted', function (): void {
    Event::fake([NodeCommentDeleted::class]);

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'deletable');
    $comment = app(AddNodeCommentAction::class)->execute($node, nodeCommentData());
    $id = $comment->getKey();

    app(DeleteNodeCommentAction::class)->execute($comment, actorId: 5);

    expect(NodeComment::query()->whereKey($id)->exists())->toBeFalse();

    Event::assertDispatched(NodeCommentDeleted::class, function (NodeCommentDeleted $e) use ($id): bool {
        return $e->comment->getKey() === $id && $e->actorId === 5;
    });
});
