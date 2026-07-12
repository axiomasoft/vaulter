<?php

declare(strict_types=1);

use Vaulter\Documents\Actions\Documents\CreateDocumentNodeAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Versions\CreateNamedVersionAction;
use Vaulter\Documents\Data\Documents\CreateDocumentNodeData;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentData;
use Vaulter\Documents\Data\Versions\CreateNamedVersionData;
use Vaulter\Documents\Enums\Documents\DocumentType;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionReadRepository;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;

/*
 * VLT-CANON P0.4 (L3) — `v_node_document_versions.format` lost its `default('tiptap')`,
 * so the WRITER must stamp it. Two paths previously relied on that DB default when
 * APPENDING a new version row to an existing document: the autosave path
 * (UpdateDocumentContentAction) and the Yjs-snapshot path (PersistYjsSnapshotAction).
 *
 * The defect the default masked: a markdown (or univer) document's appended autosave
 * rows were stamped 'tiptap' — a format the document never had, and not even a valid
 * DocumentType. These tests pin the fix from the OUTCOME side: an appended row carries
 * the document's REAL format forward, never a hardcoded one.
 */
beforeEach(function (): void {
    $this->drive = DocumentTestHelpers::drive();

    // Debounce 0 would overwrite the head in place; force the APPEND branch (the one
    // that used to depend on the DB default) by disabling the debounce window.
    config()->set('vaulter.autosave_debounce_seconds', 0);
});

it('carries a markdown document\'s format forward onto an appended autosave row (P0.4/L3)', function (): void {
    $node = app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
        storage: $this->drive->storage(),
        name: 'Notes',
        content: DocumentTestHelpers::content(),
        parent: $this->drive->root(),
        format: DocumentType::Markdown->value,
    ));

    expect(app(NodeDocumentVersionReadRepository::class)->head($node)?->format)->toBe('markdown');

    // Force an APPEND (not an in-place overwrite of the head): the head created above
    // is a committed v1, so the autosave path appends v2.
    $version = app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentData(
        node: $node,
        content: DocumentTestHelpers::content('Updated'),
    ));

    // Pre-P0.4 this row was stamped 'tiptap' by the DB default — a format the document
    // never had. It must now inherit the document's real format.
    expect($version->format)->toBe('markdown')
        ->and($version->version_no)->toBe(2);
});

it('keeps a rich-text document on richtext across an appended autosave row (P0.4/L3)', function (): void {
    $node = app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
        storage: $this->drive->storage(),
        name: 'Doc',
        content: DocumentTestHelpers::content(),
        parent: $this->drive->root(),
        format: DocumentType::RichText->value,
    ));

    $version = app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentData(
        node: $node,
        content: DocumentTestHelpers::content('Updated'),
    ));

    expect($version->format)->toBe('richtext');
});

it('carries the format forward onto a NAMED version too (P0.4/L3)', function (): void {
    $node = app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
        storage: $this->drive->storage(),
        name: 'Notes',
        content: DocumentTestHelpers::content(),
        parent: $this->drive->root(),
        format: DocumentType::Markdown->value,
    ));

    $named = app(CreateNamedVersionAction::class)->execute(new CreateNamedVersionData(
        node: $node,
        label: 'v1.0',
    ));

    expect($named->format)->toBe('markdown')
        ->and($named->snapshot_label)->toBe('v1.0');
});
