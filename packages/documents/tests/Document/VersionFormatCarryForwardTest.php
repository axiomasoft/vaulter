<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Revisions\CreateNamedRevisionAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Data\Revisions\CreateNamedRevisionInput;
use Vaulter\Documents\Data\Snapshots\Mapping\DocumentSnapshotMapper;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionReadRepository;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

/*
 * VLT-CANON P0.4 (L3) stamped the 0.2 per-version `format` explicitly so appended rows
 * never fell back to a DB default. The type now lives once on the document aggregate
 * (v_documents.type) and checkpoints carry none, so a checkpoint can never disagree with
 * its document. These tests pin the outcome: every checkpoint — autosave, named, or one
 * taken because the head is missing — reads as the document's own type.
 */
beforeEach(function (): void {
    $this->drive = DocumentTestHelpers::drive();
    // Every effective write takes a checkpoint.
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
});

function typeOf(mixed $revision): mixed
{
    // Snapshots carry the type; map the stored row the way the operations do.
    $mapper = app(DocumentSnapshotMapper::class);
    $snapshot = match (true) {
        $revision instanceof DocumentRevision => $mapper->revision($revision, $revision->document),
        $revision instanceof Document => $mapper->document($revision, $revision->currentRevision),
        default => $revision,
    };

    return $snapshot->type;
}

it('keeps a markdown document markdown across an autosave checkpoint (P0.4/L3)', function (): void {
    $node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($this->drive->model()), new CreateDocumentInput(
        storage: $this->drive->model(),
        name: 'Notes',
        content: DocumentTestHelpers::content(),
        parent: DriveTestHelpers::model($this->drive->root()),
        type: 'vaulter/markdown',
    ));
    expect(DocumentTestHelpers::document($node)->type)->toBe('vaulter/markdown')
        ->and(typeOf(app(DocumentRevisionReadRepository::class)->head($node)))->toBe('vaulter/markdown');

    $result = app(UpdateDocumentContentAction::class)->execute(DocumentTestHelpers::context($node), new UpdateDocumentContentInput(
        node: $node,
        content: DocumentTestHelpers::content('Updated'),
    ));

    expect($result->checkpoint?->number)->toBe(2)
        ->and(typeOf($result->checkpoint))->toBe('vaulter/markdown')
        ->and($result->document->type)->toBe('vaulter/markdown');
});

it('takes a checkpoint for a document whose head is missing, without dereferencing null', function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 300);
    $node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($this->drive->model()), new CreateDocumentInput(
        storage: $this->drive->model(),
        name: 'Headless',
        content: DocumentTestHelpers::content(),
        parent: DriveTestHelpers::model($this->drive->root()),
        type: 'vaulter/markdown',
    ));
    // SQLite has no head FK; a missing head must still lead to a fresh checkpoint.
    app(VaulterDatabase::class)->write(fn () => Document::query()->where('node_id', $node->getKey())->update(['current_revision_id' => null]));
    expect(app(DocumentRevisionReadRepository::class)->head($node))->toBeNull();

    $result = app(UpdateDocumentContentAction::class)->execute(DocumentTestHelpers::context($node), new UpdateDocumentContentInput(
        node: $node,
        content: DocumentTestHelpers::content('Updated'),
    ));

    expect($result->checkpoint?->number)->toBe(2)
        ->and(typeOf($result->checkpoint))->toBe('vaulter/markdown')
        ->and($result->document->current_revision_id)->toEqual($result->checkpoint?->getKey());
});

it('keeps the type on a NAMED checkpoint too (P0.4/L3)', function (): void {
    $node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($this->drive->model()), new CreateDocumentInput(
        storage: $this->drive->model(),
        name: 'Notes',
        content: DocumentTestHelpers::content(),
        parent: DriveTestHelpers::model($this->drive->root()),
        type: 'vaulter/markdown',
    ));

    $named = app(CreateNamedRevisionAction::class)->execute(DocumentTestHelpers::context($node), new CreateNamedRevisionInput(
        node: $node,
        label: 'v1.0',
    ));

    expect(typeOf($named))->toBe('vaulter/markdown')
        ->and($named->label)->toBe('v1.0');
});
