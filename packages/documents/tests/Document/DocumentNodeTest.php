<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Revisions\CreateNamedRevisionAction;
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Content\TipTap\TipTapRenderer;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Data\Revisions\CreateNamedRevisionInput;
use Vaulter\Documents\Data\Revisions\RestoreDocumentRevisionInput;
use Vaulter\Documents\Events\Documents\DocumentCreated;
use Vaulter\Documents\Events\Revisions\DocumentRevisionCreated;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

/**
 * The M4a document-node lifecycle (§2.5) on the document aggregate: create a
 * kind=document node with its working state and checkpoint #1, autosave on the
 * checkpoint interval, named checkpoints, copy-forward restore, prune.
 * Authorization runs OPEN (the base suite's null driver), so these exercise the
 * Action/repository row work.
 */
it('creates a kind=document node with its aggregate and checkpoint #1', function (): void {
    Event::fake([DocumentCreated::class]);

    $drive = DocumentTestHelpers::drive();

    $node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Spec',
        content: DocumentTestHelpers::content('First draft'),
        parent: DriveTestHelpers::model($drive->root()),
    ));
    $document = DocumentTestHelpers::document($node);
    $first = DocumentTestHelpers::head($node);

    expect($node->kind)->toBe(NodeKind::Document)
        ->and($node->mime_type)->toBe(CreateDocumentInput::NODE_MIME)
        ->and($node->getAttributes())->not->toHaveKey('current_version_id')
        ->and($document->type)->toBe('vaulter/rich-text')
        ->and($document->state_version)->toBe(1)
        ->and($document->checkpoint_state_version)->toBe(1)
        ->and($document->collab_epoch)->toBe(0)
        ->and($document->revision_count)->toBe(1)
        ->and($first->number)->toBe(1)
        ->and($first->is_autosave)->toBeFalse()
        ->and($first->status)->toBe('committed')
        ->and($first->content)->toBe($document->content);

    Event::assertDispatched(DocumentCreated::class, fn (DocumentCreated $event): bool => $event->version->id === (string) $first->getKey());
});

it('round-trips the content through TipTapRenderer', function (): void {
    $drive = DocumentTestHelpers::drive();

    $node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Doc',
        content: DocumentTestHelpers::content('Round trip'),
        parent: DriveTestHelpers::model($drive->root()),
    ));

    $html = app(TipTapRenderer::class)->toHtml(DocumentTestHelpers::head($node)->tipTapContent());

    expect($html)->toContain('Round trip');
});

it('writes autosaves to the working state and leaves stored checkpoints alone inside the interval', function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 300);

    $drive = DocumentTestHelpers::drive();
    $node = createDocument($drive, 'Live');
    $first = DocumentTestHelpers::head($node);

    app(UpdateDocumentContentAction::class)->execute(DocumentTestHelpers::context($node->refresh()), new UpdateDocumentContentInput(
        node: $node->refresh(),
        content: DocumentTestHelpers::content('edit one'),
    ));
    $result = app(UpdateDocumentContentAction::class)->execute(DocumentTestHelpers::context($node->refresh()), new UpdateDocumentContentInput(
        node: $node->refresh(),
        content: DocumentTestHelpers::content('edit two'),
    ));

    expect($result->changed)->toBeTrue()
        ->and($result->checkpoint)->toBeNull()
        ->and(DocumentTestHelpers::revisions($node))->toHaveCount(1)
        ->and($first->refresh()->tipTapContent()->toArray())->toBe(DocumentTestHelpers::content('seed')->toArray())
        ->and($result->document->tipTapContent()->toArray())->toBe(DocumentTestHelpers::content('edit two')->toArray())
        ->and($result->document->state_version)->toBe(3)
        ->and($result->document->hasUncheckpointedChanges())->toBeTrue();
});

it('takes an autosave checkpoint once the interval has passed', function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 300);

    $drive = DocumentTestHelpers::drive();
    $node = createDocument($drive, 'Churny');

    $this->travel(301)->seconds();
    $result = app(UpdateDocumentContentAction::class)->execute(DocumentTestHelpers::context($node->refresh()), new UpdateDocumentContentInput(
        node: $node->refresh(),
        content: DocumentTestHelpers::content('later'),
    ));

    expect($result->checkpoint)->not->toBeNull()
        ->and($result->checkpoint?->number)->toBe(2)
        ->and($result->checkpoint?->is_autosave)->toBeTrue()
        ->and($result->checkpoint?->state_version)->toBe(2)
        ->and($result->document->current_revision_id)->toEqual($result->checkpoint?->getKey())
        ->and($result->document->hasUncheckpointedChanges())->toBeFalse();
});

it('creates a NAMED checkpoint with a label and makes it the head', function (): void {
    Event::fake([DocumentRevisionCreated::class]);

    $drive = DocumentTestHelpers::drive();
    $node = createDocument($drive, 'Named');
    $before = DocumentTestHelpers::document($node);

    $version = app(CreateNamedRevisionAction::class)->execute(DocumentTestHelpers::context($node->refresh()), new CreateNamedRevisionInput(
        node: $node->refresh(),
        label: 'Final review',
    ));
    $after = DocumentTestHelpers::document($node);

    expect($version->label)->toBe('Final review')
        ->and($version->is_autosave)->toBeFalse()
        ->and($version->number)->toBe(2)
        ->and($after->current_revision_id)->toEqual($version->getKey())
        ->and($after->state_version)->toBe($before->state_version)
        ->and($after->collab_epoch)->toBe($before->collab_epoch);

    Event::assertDispatched(DocumentRevisionCreated::class, fn (DocumentRevisionCreated $e): bool => $e->restored === false);
});

it('restore copies a prior checkpoint forward and records where it came from', function (): void {
    Event::fake([DocumentRevisionCreated::class]);

    $drive = DocumentTestHelpers::drive();
    $node = createDocument($drive, 'Restorable');
    $first = DocumentTestHelpers::head($node);

    app(UpdateDocumentContentAction::class)->execute(DocumentTestHelpers::context($node->refresh()), new UpdateDocumentContentInput(
        node: $node->refresh(),
        content: DocumentTestHelpers::content('moved on'),
    ));
    $before = DocumentTestHelpers::document($node);

    $restored = app(RestoreDocumentRevisionAction::class)->execute(DocumentTestHelpers::context($node), new RestoreDocumentRevisionInput(
        node: $node->refresh(),
        revision: $first->getKey(),
    ));
    $after = DocumentTestHelpers::document($node);

    expect($restored->getKey())->not->toEqual($first->getKey())
        ->and($restored->content)->toEqual($first->content)
        ->and($restored->restored_from_revision_id)->toEqual($first->getKey())
        ->and($restored->label)->toBe('Restored from #1')
        ->and($after->current_revision_id)->toEqual($restored->getKey())
        ->and($after->content)->toEqual($first->content)
        ->and($after->state_version)->toBe($before->state_version + 1)
        ->and($after->collab_epoch)->toBe($before->collab_epoch + 1)
        ->and($first->refresh()->content)->toEqual($restored->content);

    Event::assertDispatched(DocumentRevisionCreated::class, fn (DocumentRevisionCreated $e): bool => $e->restored === true);
});

it('prunes past the max but NEVER a keep_forever checkpoint nor the head', function (): void {
    config()->set('vaulter-documents.revisions.limit', 3);

    $drive = DocumentTestHelpers::drive();
    $node = createDocument($drive, 'Pruned');

    app(CreateNamedRevisionAction::class)->execute(DocumentTestHelpers::context($node->refresh()), new CreateNamedRevisionInput(
        node: $node->refresh(),
        label: 'pinned',
        keepForever: true,
    ));

    foreach (range(1, 8) as $i) {
        app(CreateNamedRevisionAction::class)->execute(DocumentTestHelpers::context($node->refresh()), new CreateNamedRevisionInput(
            node: $node->refresh(),
            label: "v{$i}",
        ));
    }

    $versions = DocumentTestHelpers::revisions($node);
    $document = DocumentTestHelpers::document($node);
    $headId = $document->current_revision_id;

    expect($versions->firstWhere('keep_forever', true))->not->toBeNull()
        ->and($versions->firstWhere('id', $headId))->not->toBeNull()
        ->and($versions->where('keep_forever', false)->where('id', '!=', $headId)->count())->toBeLessThanOrEqual(3)
        // Pruning never lowers the counter, so a number is never handed out twice.
        ->and($document->revision_count)->toBe(10);
});

/**
 * Helper: create a document node and return it.
 */
function createDocument($drive, string $name): Node
{
    return app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: $name,
        content: DocumentTestHelpers::content('seed'),
        parent: DriveTestHelpers::model($drive->root()),
    ));
}

function oversizedContent(): TipTapContent
{
    return DocumentTestHelpers::content(str_repeat('x', 4096));
}

// ─── F-P1.11-05 — max_document_size_kb enforced at save time ───────────────

it('CreateDocumentAction rejects content over max_document_size_kb, F-P1.11-05', function (): void {
    config()->set('vaulter-documents.content.max_size_kb', 1);

    $drive = DocumentTestHelpers::drive();

    app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Too big',
        content: oversizedContent(),
        parent: DriveTestHelpers::model($drive->root()),
    ));
})->throws(InvalidDocumentContentException::class);

it('UpdateDocumentContentAction rejects content over max_document_size_kb, F-P1.11-05', function (): void {
    $drive = DocumentTestHelpers::drive();
    $node = createDocument($drive, 'Grows too big');

    config()->set('vaulter-documents.content.max_size_kb', 1);

    app(UpdateDocumentContentAction::class)->execute(DocumentTestHelpers::context($node->refresh()), new UpdateDocumentContentInput(
        node: $node->refresh(),
        content: oversizedContent(),
    ));
})->throws(InvalidDocumentContentException::class);
