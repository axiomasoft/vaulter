<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Vaulter\Documents\Actions\Comments\AddCommentAction;
use Vaulter\Documents\Actions\Comments\DeleteCommentAction;
use Vaulter\Documents\Actions\Comments\ResolveCommentAction;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Data\Comments\DeleteCommentInput;
use Vaulter\Documents\Data\Comments\ResolveCommentInput;
use Vaulter\Documents\Events\Comments\CommentAdded;
use Vaulter\Documents\Events\Comments\CommentDeleted;
use Vaulter\Documents\Events\Comments\CommentResolved;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The node-comment Actions (ADR-0014 шаг 1): add (threaded + anchored + morph-alias
 * author taken from the operation's actor), resolve (tombstone + actor) and delete
 * (permanent), each dispatching its broadcast event on the node's collaboration channel.
 */
function nodeCommentData(?int $parentId = null): AddCommentInput
{
    return new AddCommentInput(
        body: 'looks good',
        parentId: $parentId,
        anchor: ['from' => 1, 'to' => 4],
    );
}

it('adds a comment and dispatches CommentAdded on the node channel', function (): void {
    Event::fake([CommentAdded::class]);

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'commentable');

    $comment = app(AddCommentAction::class)->execute(DocumentTestHelpers::context($node, 1), $node, nodeCommentData());

    expect($comment->node_id)->toBe($node->getKey())
        ->and($comment->author_type)->toBe((new TestUser)->getMorphClass())
        ->and($comment->anchor)->toBe(['from' => 1, 'to' => 4])
        ->and($comment->isResolved())->toBeFalse();

    Event::assertDispatched(CommentAdded::class, function (CommentAdded $event) use ($node): bool {
        $channels = $event->broadcastOn();

        return $channels[0] instanceof PrivateChannel
            && $channels[0]->name === 'private-'.app(DocumentsConfig::class)->channelPrefix().'.'.$node->getKey();
    });
});

it('threads a reply under its parent', function (): void {
    Event::fake();

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'threaded');

    $action = app(AddCommentAction::class);
    $root = $action->execute(DocumentTestHelpers::context($node, 1), $node, nodeCommentData());
    $reply = $action->execute(DocumentTestHelpers::context($node, 1), $node, nodeCommentData(parentId: (int) $root->getKey()));

    expect($reply->parent_id)->toBe($root->getKey())
        ->and($root->replies()->count())->toBe(1);
});

it('resolves a comment, stamping resolved_at + resolved_by and dispatching the event', function (): void {
    Event::fake([CommentResolved::class]);

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'resolvable');
    $comment = app(AddCommentAction::class)->execute(DocumentTestHelpers::context($node, 1), $node, nodeCommentData());

    $resolved = app(ResolveCommentAction::class)->execute(DocumentTestHelpers::context($node, 42), new ResolveCommentInput($comment));

    expect($resolved->isResolved())->toBeTrue()
        ->and($resolved->resolved_by)->toBe(42);

    Event::assertDispatched(CommentResolved::class, fn (CommentResolved $e): bool => $e->actor->id === 42);
});

it('permanently deletes a comment and dispatches CommentDeleted', function (): void {
    Event::fake([CommentDeleted::class]);

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'deletable');
    $comment = app(AddCommentAction::class)->execute(DocumentTestHelpers::context($node, 1), $node, nodeCommentData());
    $id = $comment->getKey();

    app(DeleteCommentAction::class)->execute(DocumentTestHelpers::context($node, 5), new DeleteCommentInput($comment));

    expect(NodeComment::query()->whereKey($id)->exists())->toBeFalse();

    Event::assertDispatched(CommentDeleted::class, function (CommentDeleted $e) use ($id): bool {
        return $e->comment->getKey() === $id && $e->actor->id === 5;
    });
});
