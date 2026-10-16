<?php

declare(strict_types=1);

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Events\Comments\CommentAdded;
use Vaulter\Documents\Events\Comments\CommentDeleted;
use Vaulter\Documents\Events\Comments\CommentResolved;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Models\Activity\Activity;

uses(EnablesWebhooksModule::class);

/**
 * PLAN2.F15: comment events leave an Action as part of its unit of work — their
 * activity and outbox rows commit with it — and are delivered after the OUTERMOST
 * commit. Runs without the fixture's wrapping
 * transaction (DatabaseMigrations), so "committed" is a real commit.
 */
afterEach(fn () => Relation::morphMap([], false));

function afterCommitFixture(): array
{
    $handle = DocumentTestHelpers::drive();
    $node = $handle->folders()->create('thread');
    AccessTestHelpers::grant($node, RoleRegistry::OWNER, subjectId: 9);

    return [$handle->actingAs(AccessTestHelpers::user(9)), $node];
}

/** @return array<int, object> filled by the listeners */
function afterCommitListen(): ArrayObject
{
    $seen = new ArrayObject;
    foreach ([CommentAdded::class, CommentResolved::class, CommentDeleted::class] as $event) {
        Event::listen($event, static function (object $e) use ($seen): void {
            $seen[] = $e;
        });
    }

    return $seen;
}

it('delivers nothing before the outer commit and exactly one event after it, with a stable eventId', function (): void {
    [$actor, $node] = afterCommitFixture();
    $seen = afterCommitListen();
    $database = app(VaulterDatabase::class);

    $database->transaction(function () use ($actor, $node, $seen): void {
        $comment = Documents::comments($actor)->add($node->id, new AddCommentInput('inside'));
        Documents::comments($actor)->resolve($node->id, $comment->id);
        Documents::comments($actor)->delete($node->id, $comment->id);
        expect(count($seen))->toBe(0);
    });

    $eventIds = array_map(static fn (object $e): string => $e->eventId, $seen->getArrayCopy());

    expect(count($seen))->toBe(3)
        ->and(array_map('get_class', $seen->getArrayCopy()))->toBe([CommentAdded::class, CommentResolved::class, CommentDeleted::class])
        ->and(count(array_unique($eventIds)))->toBe(3)
        // The delivered occurrences are the ones the activity and outbox rows committed.
        ->and(Activity::query()->whereIn('event_id', $eventIds)->count())->toBe(3)
        ->and(OutboxEvent::query()->whereIn('event_id', $eventIds)->count())->toBe(3);
});

it('a rolled back outer transaction broadcasts nothing and leaves no comment', function (): void {
    [$actor, $node] = afterCommitFixture();
    $seen = afterCommitListen();
    $database = app(VaulterDatabase::class);

    try {
        $database->transaction(function () use ($actor, $node): void {
            Documents::comments($actor)->add($node->id, new AddCommentInput('doomed'));

            throw new RuntimeException('outer failure');
        }, attempts: 1);
    } catch (RuntimeException) {
    }

    expect(count($seen))->toBe(0)
        ->and(NodeComment::query()->where('node_id', $node->id)->count())->toBe(0)
        ->and(Activity::query()->where('verb', 'comment.added')->exists())->toBeFalse()
        ->and(OutboxEvent::query()->where('type', 'comment.added')->exists())->toBeFalse();
});

it('outside any outer transaction one add raises exactly one event', function (): void {
    [$actor, $node] = afterCommitFixture();
    $seen = afterCommitListen();

    $comment = Documents::comments($actor)->add($node->id, new AddCommentInput('plain'));

    expect(count($seen))->toBe(1)
        ->and($seen[0])->toBeInstanceOf(CommentAdded::class)
        ->and((string) $seen[0]->comment->getKey())->toBe($comment->id)
        ->and($seen[0]->eventId)->not->toBe('');
});

it('queues the broadcast only after the outer commit and never after a rollback (queue path)', function (): void {
    [$actor, $node] = afterCommitFixture();
    Queue::fake();
    $database = app(VaulterDatabase::class);

    $database->transaction(function () use ($actor, $node): void {
        Documents::comments($actor)->add($node->id, new AddCommentInput('inside'));
        Queue::assertNothingPushed();
    });
    Queue::assertPushed(BroadcastEvent::class, 1);

    Queue::fake();
    try {
        $database->transaction(function () use ($actor, $node): void {
            Documents::comments($actor)->add($node->id, new AddCommentInput('doomed'));

            throw new RuntimeException('outer failure');
        }, attempts: 1);
    } catch (RuntimeException) {
    }
    Queue::assertNothingPushed();
});

it('raises exactly one event per mutation through a faked dispatcher', function (): void {
    [$actor, $node] = afterCommitFixture();
    Event::fake([CommentAdded::class, CommentResolved::class, CommentDeleted::class]);

    $comment = Documents::comments($actor)->add($node->id, new AddCommentInput('faked'));
    Documents::comments($actor)->resolve($node->id, $comment->id);
    Documents::comments($actor)->delete($node->id, $comment->id);

    Event::assertDispatchedTimes(CommentAdded::class, 1);
    Event::assertDispatchedTimes(CommentResolved::class, 1);
    Event::assertDispatchedTimes(CommentDeleted::class, 1);
});
