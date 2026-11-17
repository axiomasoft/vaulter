<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Enums\DocumentEventType;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Operations\OperationsTestCase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Testing\Support\CanonicalJson;

/**
 * Document and comment events go through the EventRecorder like every other event:
 * one activity row and one outbox row per occurrence, with the document's content
 * validators from the writer. Content saves are not audited, and only the saves that
 * took a checkpoint reach webhooks.
 */
uses(OperationsTestCase::class, RefreshDatabase::class);

/** @return array<string, int> event type => rows */
function documentEventsCount(string $model): array
{
    $column = $model === Activity::class ? 'verb' : 'type';

    // The outbox holds every occurrence; the ones that leave the process are the external ones.
    $query = $model::query()->toBase()->where(static fn ($q) => $q->where($column, 'like', 'document.%')->orWhere($column, 'like', 'comment.%'));
    if ($model === OutboxEvent::class) {
        $query->where('external', true);
    }

    return $query
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
    $created = OutboxEvent::query()->where('type', DocumentEventType::DocumentCreated->value)->sole();
    $restored = OutboxEvent::query()->where('type', DocumentEventType::DocumentRestored->value)->sole();

    expect(documentEventsCount(Activity::class))->toEqualCanonicalizing([
        'document.created' => 1, 'document.revision_created' => 2, 'document.restored' => 1,
    ])->and(documentEventsCount(OutboxEvent::class))->toEqualCanonicalizing([
        'document.created' => 1, 'document.revision_created' => 2, 'document.restored' => 1,
    ])->and($created->payload['data']['state_version'])->toBe(1)
        ->and(CanonicalJson::of($created->payload['subject']))->toBe(CanonicalJson::of(['type' => 'node', 'id' => (string) $node->id]))
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

    $updated = OutboxEvent::query()->where('type', DocumentEventType::DocumentUpdated->value)->where('external', true)->sole();

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

    $added = OutboxEvent::query()->where('type', DocumentEventType::CommentAdded->value)->sole();

    expect(documentEventsCount(Activity::class))->toEqualCanonicalizing(['comment.added' => 1, 'comment.resolved' => 1, 'comment.deleted' => 1])
        ->and(documentEventsCount(OutboxEvent::class))->toEqualCanonicalizing(['comment.added' => 1, 'comment.resolved' => 1, 'comment.deleted' => 1])
        ->and(CanonicalJson::of($added->payload['subject']))->toBe(CanonicalJson::of(['type' => 'comment', 'id' => (string) $comment->id]))
        ->and(CanonicalJson::of($added->payload['data']))->toBe(CanonicalJson::of(['comment_id' => (string) $comment->id, 'node_id' => $node->id]))
        ->and($added->payload['actor']['id'])->toBe('9')
        ->and(json_encode($added->payload, JSON_THROW_ON_ERROR))->not->toContain('hello')
        ->and(Activity::query()->where('verb', DocumentEventType::CommentAdded->value)->sole()->target_id)->toEqual($node->id);
});
