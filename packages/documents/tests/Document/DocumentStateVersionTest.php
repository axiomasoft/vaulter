<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Collaboration\PersistCollaborationStateAction;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Revisions\CreateNamedRevisionAction;
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Data\Collaboration\PersistCollaborationStateInput;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Data\Revisions\CreateNamedRevisionInput;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Events\Revisions\DocumentRevisionCreated;
use Vaulter\Documents\Exceptions\CollaborationEpochMismatchException;
use Vaulter\Documents\Exceptions\StaleDocumentStateException;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Exceptions\Nodes\SequenceOverflowException;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;

/*
 * The content validator of a document (D35): state_version moves by exactly one per
 * effective write of the working state, an identical write is a no-op, and a stale
 * expectation is refused (412 document_state_mismatch) before anything is written.
 * These scenarios replace the document cases of the P3.3 node-sequence regression.
 */
function stateVersionDocument(): Node
{
    $drive = DocumentTestHelpers::drive();

    return app(CreateDocumentAction::class)->execute(new CreateDocumentInput(
        storage: $drive->model(),
        name: 'State version '.bin2hex(random_bytes(3)),
        content: DocumentTestHelpers::content('initial'),
        parent: DriveTestHelpers::model($drive->root()),
    ));
}

it('starts at one and counts each changed write once; an identical write is a no-op', function (): void {
    $node = stateVersionDocument();
    $action = app(UpdateDocumentContentAction::class);
    expect(DocumentTestHelpers::document($node)->state_version)->toBe(1);

    $first = $action->execute(new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content('first'), expectedStateVersion: 1));
    Event::fake([DocumentContentUpdated::class]);
    $same = $action->execute(new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content('first'), expectedStateVersion: 2));

    expect($first->changed)->toBeTrue()
        ->and($first->document->state_version)->toBe(2)
        ->and($same->changed)->toBeFalse()
        ->and($same->checkpoint)->toBeNull()
        ->and(DocumentTestHelpers::document($node)->state_version)->toBe(2);
    Event::assertNotDispatched(DocumentContentUpdated::class);
});

it('checks a stale expectation before an identical write and leaves no side effects', function (): void {
    $node = stateVersionDocument();
    $action = app(UpdateDocumentContentAction::class);
    $content = DocumentTestHelpers::content('saved');
    $action->execute(new UpdateDocumentContentInput(node: $node, content: $content, expectedStateVersion: 1));
    $before = DocumentTestHelpers::document($node);
    Event::fake([DocumentContentUpdated::class]);

    try {
        $action->execute(new UpdateDocumentContentInput(node: $node, content: $content, expectedStateVersion: 1));
        $this->fail('A stale expectation must be refused.');
    } catch (StaleDocumentStateException $exception) {
        expect($exception->httpStatus())->toBe(412)
            ->and($exception->problemCode())->toBe('document_state_mismatch')
            ->and([$exception->expected, $exception->actual])->toBe([1, 2]);
    }

    $after = DocumentTestHelpers::document($node);
    expect($after->state_version)->toBe($before->state_version)
        ->and($after->content_hash)->toBe($before->content_hash)
        ->and(DocumentTestHelpers::revisions($node))->toHaveCount(1);
    Event::assertNotDispatched(DocumentContentUpdated::class);
});

it('compares the expectation with the locked row, not with a stale caller model', function (): void {
    $node = stateVersionDocument();
    $stale = $node->replicate();
    $stale->setRawAttributes($node->getAttributes(), sync: true);
    $stale->exists = true;
    $action = app(UpdateDocumentContentAction::class);
    $action->execute(new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content('new state')));

    $updated = $action->execute(new UpdateDocumentContentInput(
        node: $stale,
        content: DocumentTestHelpers::content('latest edit'),
        expectedStateVersion: 2,
    ));

    expect($updated->document->state_version)->toBe(3)
        ->and($updated->document->tipTapContent()->toArray())->toBe(DocumentTestHelpers::content('latest edit')->toArray());
});

it('takes named checkpoints without moving the state; restore moves state and epoch and honours its expectation', function (): void {
    $node = stateVersionDocument();
    $first = DocumentTestHelpers::head($node);

    $named = app(CreateNamedRevisionAction::class)->execute(new CreateNamedRevisionInput(node: $node, label: 'checkpoint'));
    expect(DocumentTestHelpers::document($node)->state_version)->toBe(1)
        ->and($named->state_version)->toBe(1);

    Event::fake([DocumentRevisionCreated::class]);
    expect(fn () => app(RestoreDocumentRevisionAction::class)->execute(node: $node, versionId: $first->id, expectedStateVersion: 0))
        ->toThrow(StaleDocumentStateException::class);
    expect(DocumentTestHelpers::revisions($node))->toHaveCount(2);
    Event::assertNotDispatched(DocumentRevisionCreated::class);

    $restored = app(RestoreDocumentRevisionAction::class)->execute(node: $node, versionId: $first->id, expectedStateVersion: 1);
    $document = DocumentTestHelpers::document($node);
    expect($document->state_version)->toBe(2)
        ->and($document->collab_epoch)->toBe(1)
        ->and($restored->state_version)->toBe(2)
        ->and($document->current_revision_id)->toEqual($restored->id);
});

it('counts Yjs and projection changes while an identical Yjs state is a no-op', function (): void {
    $node = stateVersionDocument();
    $action = app(PersistCollaborationStateAction::class);

    $action->execute(new PersistCollaborationStateInput(node: $node, yjsState: "first\0state"));
    $action->execute(new PersistCollaborationStateInput(node: $node, yjsState: "second\0state"));
    expect(DocumentTestHelpers::document($node)->state_version)->toBe(3);

    Event::fake([DocumentContentUpdated::class]);
    $same = $action->execute(new PersistCollaborationStateInput(node: $node, yjsState: "second\0state"));
    expect($same->changed)->toBeFalse()
        ->and(DocumentTestHelpers::document($node)->state_version)->toBe(3);
    Event::assertNotDispatched(DocumentContentUpdated::class);

    $action->execute(new PersistCollaborationStateInput(
        node: $node,
        yjsState: "second\0state",
        content: DocumentTestHelpers::content('projection changed'),
    ));
    $document = DocumentTestHelpers::document($node);
    expect($document->state_version)->toBe(4)
        ->and($document->yjs_state)->toBe("second\0state")
        ->and($document->collab_epoch)->toBe(0);
    Event::assertDispatchedTimes(DocumentContentUpdated::class, 1);
});

it('replacing the JSON of a document with Yjs state drops that state and moves the epoch', function (): void {
    $node = stateVersionDocument();
    app(PersistCollaborationStateAction::class)->execute(new PersistCollaborationStateInput(node: $node, yjsState: "room\0state"));

    $result = app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentInput(
        node: $node,
        content: DocumentTestHelpers::content('replaced from REST'),
    ));

    expect($result->epochChanged)->toBeTrue()
        ->and($result->document->collab_epoch)->toBe(1)
        ->and($result->document->yjs_state)->toBeNull()
        ->and($result->document->yjs_state_size)->toBeNull();
});

it('fences a write from an old collaboration epoch before anything is written', function (): void {
    $node = stateVersionDocument();
    $database = app(VaulterDatabase::class);

    expect(fn () => $database->transaction(function () use ($node): void {
        $document = Document::query()->where('node_id', $node->id)->lockForUpdate()->firstOrFail();
        app(DocumentStateWriter::class)->write($document, new DocumentStateChange(
            cause: StateWriteCause::Collaboration,
            content: null,
            yjsState: "stale\0room",
            expectedEpoch: 5,
        ));
    }))->toThrow(CollaborationEpochMismatchException::class);

    expect(DocumentTestHelpers::document($node)->state_version)->toBe(1);
});

it('refuses a negative expectation', function (): void {
    $node = stateVersionDocument();

    expect(fn () => app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentInput(
        node: $node,
        content: DocumentTestHelpers::content('x'),
        expectedStateVersion: -1,
    )))->toThrow(InvalidArgumentException::class);
});

it('rolls back content and checkpoint writes when the state version is exhausted', function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
    $node = stateVersionDocument();
    Document::query()->where('node_id', $node->id)->update(['state_version' => PHP_INT_MAX]);
    $before = DocumentTestHelpers::document($node);
    Event::fake([DocumentContentUpdated::class]);

    expect(fn () => app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentInput(
        node: $node,
        content: DocumentTestHelpers::content('uncommitted'),
        expectedStateVersion: PHP_INT_MAX,
    )))->toThrow(SequenceOverflowException::class);

    $after = DocumentTestHelpers::document($node);
    expect($after->state_version)->toBe(PHP_INT_MAX)
        ->and($after->content_hash)->toBe($before->content_hash)
        ->and($after->current_revision_id)->toEqual($before->current_revision_id)
        ->and(DocumentTestHelpers::revisions($node))->toHaveCount(1);
    Event::assertNotDispatched(DocumentContentUpdated::class);
});
