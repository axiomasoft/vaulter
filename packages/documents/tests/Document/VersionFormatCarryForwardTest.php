<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Revisions\CreateNamedRevisionAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Data\Revisions\CreateNamedRevisionInput;
use Vaulter\Documents\Enums\Documents\DocumentType;
use Vaulter\Documents\Http\Resources\DocumentRevisionResource;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionReadRepository;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;

/*
 * VLT-CANON P0.4 (L3) stamped the 0.2 per-version `format` explicitly so appended rows
 * never fell back to a DB default. The type now lives once on the document aggregate
 * (v_documents.type) and checkpoints carry none, so a checkpoint can never disagree with
 * its document. These tests pin the outcome: every checkpoint — autosave, named, or one
 * taken because the head is missing — reads as the document's own type, and the 0.2
 * `format` JSON key is derived from it.
 */
beforeEach(function (): void {
    $this->drive = DocumentTestHelpers::drive();
    // Every effective write takes a checkpoint.
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
});

function formatOf(mixed $revision): mixed
{
    return (new DocumentRevisionResource($revision))->toArray(Request::create('/'))['format'];
}

it('keeps a markdown document markdown across an autosave checkpoint (P0.4/L3)', function (): void {
    $node = app(CreateDocumentAction::class)->execute(new CreateDocumentInput(
        storage: $this->drive->model(),
        name: 'Notes',
        content: DocumentTestHelpers::content(),
        parent: $this->drive->root(),
        format: DocumentType::Markdown->value,
    ));
    expect(DocumentTestHelpers::document($node)->type)->toBe('vaulter/markdown')
        ->and(formatOf(app(DocumentRevisionReadRepository::class)->head($node)))->toBe('markdown');

    $result = app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentInput(
        node: $node,
        content: DocumentTestHelpers::content('Updated'),
    ));

    expect($result->checkpoint?->number)->toBe(2)
        ->and(formatOf($result->checkpoint))->toBe('markdown')
        ->and($result->document->type)->toBe('vaulter/markdown');
});

it('maps every 0.2 rich-text flavour to the one rich-text type (P0.4/L3)', function (string $format): void {
    $node = app(CreateDocumentAction::class)->execute(new CreateDocumentInput(
        storage: $this->drive->model(),
        name: 'Doc '.$format,
        content: DocumentTestHelpers::content(),
        parent: $this->drive->root(),
        format: $format,
    ));
    $result = app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentInput(
        node: $node,
        content: DocumentTestHelpers::content('Updated'),
    ));

    expect($result->document->type)->toBe('vaulter/rich-text')
        ->and(formatOf($result->checkpoint))->toBe('richtext');
})->with([DocumentType::RichText->value, DocumentType::Table->value, DocumentType::Template->value]);

it('takes a checkpoint for a document whose head is missing, without dereferencing null', function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 300);
    $node = app(CreateDocumentAction::class)->execute(new CreateDocumentInput(
        storage: $this->drive->model(),
        name: 'Headless',
        content: DocumentTestHelpers::content(),
        parent: $this->drive->root(),
        format: DocumentType::Markdown->value,
    ));
    // SQLite has no head FK; a missing head must still lead to a fresh checkpoint.
    Document::query()->where('node_id', $node->getKey())->update(['current_revision_id' => null]);
    expect(app(DocumentRevisionReadRepository::class)->head($node))->toBeNull();

    $result = app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentInput(
        node: $node,
        content: DocumentTestHelpers::content('Updated'),
    ));

    expect($result->checkpoint?->number)->toBe(2)
        ->and(formatOf($result->checkpoint))->toBe('markdown')
        ->and($result->document->current_revision_id)->toEqual($result->checkpoint?->getKey());
});

it('keeps the type on a NAMED checkpoint too (P0.4/L3)', function (): void {
    $node = app(CreateDocumentAction::class)->execute(new CreateDocumentInput(
        storage: $this->drive->model(),
        name: 'Notes',
        content: DocumentTestHelpers::content(),
        parent: $this->drive->root(),
        format: DocumentType::Markdown->value,
    ));

    $named = app(CreateNamedRevisionAction::class)->execute(new CreateNamedRevisionInput(
        node: $node,
        label: 'v1.0',
    ));

    expect(formatOf($named))->toBe('markdown')
        ->and($named->label)->toBe('v1.0');
});
