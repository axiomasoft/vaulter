<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Enums\DocumentEventType;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Operations\OperationsTestCase;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;

/**
 * Document and comment events go through the EventRecorder like every other event:
 * one activity row and one outbox row per occurrence, with the document's content
 * validators from the writer. Content saves are not audited, and only the saves that
 * took a checkpoint reach webhooks.
 */
uses(OperationsTestCase::class, RefreshDatabase::class, EnablesWebhooksModule::class);

/** @return array<string, int> event type => rows */
function documentEventsCount(string $model): array
{
    $column = $model === Activity::class ? 'verb' : 'event';

    return $model::query()->toBase()->where($column, 'like', 'document.%')->orWhere($column, 'like', 'comment.%')
        ->groupBy($column)->selectRaw($column.' as type, count(*) as total')->pluck('total', 'type')
        ->map(static fn (mixed $total): int => (int) $total)->sort()->all();
}

it('records the document lifecycle with the writer validators', function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 3600);
    $documents = Documents::in(DocumentTestHelpers::drive());
    $node = $documents->create('Spec', content: DocumentTestHelpers::content('one')->toArray());
    // Inside the checkpoint interval: a save without a checkpoint.
    $documents->update($node, DocumentTestHelpers::content('two')->toArray());
    $named = $documents->snapshot($node, 'Named');
    $documents->restore($node, $named);

    $document = Document::query()->where('node_id', $node->id)->sole();
    $created = Outbox::query()->where('event', DocumentEventType::DocumentCreated->value)->sole();
    $restored = Outbox::query()->where('event', DocumentEventType::DocumentRestored->value)->sole();

    expect(documentEventsCount(Activity::class))->toEqualCanonicalizing([
        'document.created' => 1, 'document.revision_created' => 2, 'document.restored' => 1,
    ])->and(documentEventsCount(Outbox::class))->toEqualCanonicalizing([
        'document.created' => 1, 'document.revision_created' => 2, 'document.restored' => 1,
    ])->and($created->payload['data']['state_version'])->toBe(1)
        ->and($created->payload['subject'])->toBe(['type' => 'node', 'id' => (string) $node->id])
        ->and($restored->payload['data']['state_version'])->toBe($document->state_version)
        ->and($restored->payload['data']['epoch'])->toBe($document->collab_epoch)
        ->and($restored->payload['sequence'])->toBeNull()
        ->and(Activity::query()->where('verb', DocumentEventType::DocumentCreated->value)->sole()->event_id)->toBe($created->event_id);
});

it('stages document.updated for a save only when it took a checkpoint', function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
    $documents = Documents::in(DocumentTestHelpers::drive());
    $node = $documents->create('Spec', content: DocumentTestHelpers::content('one')->toArray());
    $documents->update($node, DocumentTestHelpers::content('two')->toArray());

    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 3600);
    $seen = [];
    Event::listen(DocumentContentUpdated::class, static function (DocumentContentUpdated $event) use (&$seen): void {
        $seen[] = $event->created;
    });
    $documents->update($node, DocumentTestHelpers::content('three')->toArray());

    $updated = Outbox::query()->where('event', DocumentEventType::DocumentUpdated->value)->sole();

    expect($updated->payload['data']['checkpoint'])->toBeTrue()
        ->and($updated->payload['data'])->toHaveKey('revision_id')
        ->and(Activity::query()->where('verb', DocumentEventType::DocumentUpdated->value)->exists())->toBeFalse()
        // The save without a checkpoint still reaches host listeners.
        ->and($seen)->toBe([false]);
});

it('records comment events in both sinks', function (): void {
    $handle = DocumentTestHelpers::drive();
    $node = $handle->folders()->create('thread');
    AccessTestHelpers::grant($node, RoleRegistry::OWNER, subjectId: 9);
    $actor = $handle->actingAs(AccessTestHelpers::user(9));

    $comment = Documents::comments($actor)->add($node->id, new AddCommentInput('hello'));
    Documents::comments($actor)->resolve($node->id, $comment->id);
    Documents::comments($actor)->delete($node->id, $comment->id);

    $added = Outbox::query()->where('event', DocumentEventType::CommentAdded->value)->sole();

    expect(documentEventsCount(Activity::class))->toEqualCanonicalizing(['comment.added' => 1, 'comment.resolved' => 1, 'comment.deleted' => 1])
        ->and(documentEventsCount(Outbox::class))->toEqualCanonicalizing(['comment.added' => 1, 'comment.resolved' => 1, 'comment.deleted' => 1])
        ->and($added->payload['subject'])->toBe(['type' => 'comment', 'id' => (string) $comment->id])
        ->and($added->payload['data'])->toBe(['comment_id' => (string) $comment->id, 'node_id' => (string) $node->id])
        ->and($added->payload['actor']['id'])->toBe('9')
        ->and(json_encode($added->payload, JSON_THROW_ON_ERROR))->not->toContain('hello')
        ->and(Activity::query()->where('verb', DocumentEventType::CommentAdded->value)->sole()->target_id)->toEqual($node->id);
});
