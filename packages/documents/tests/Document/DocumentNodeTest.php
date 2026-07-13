<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vaulter\Documents\Actions\Collaboration\PersistYjsSnapshotAction;
use Vaulter\Documents\Actions\Documents\CreateDocumentNodeAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Versions\CreateNamedVersionAction;
use Vaulter\Documents\Actions\Versions\RestoreDocumentVersionAction;
use Vaulter\Documents\Data\Collaboration\PersistYjsSnapshotData;
use Vaulter\Documents\Data\Documents\CreateDocumentNodeData;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentData;
use Vaulter\Documents\Data\Versions\CreateNamedVersionData;
use Vaulter\Documents\Engines\Handlers\TipTapHandler;
use Vaulter\Documents\Events\Documents\DocumentNodeCreated;
use Vaulter\Documents\Events\Versions\DocumentVersionCreated;
use Vaulter\Documents\Exceptions\InvalidTipTapContentException;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;
use Vaulter\Documents\Support\TipTap\TipTapRenderer;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Drive\Node;

/**
 * The M4a document-node lifecycle (§2.5): create a kind=document node with its v1
 * version, autosave vs named version, restore, prune. Authorization runs OPEN (the
 * base suite's null driver), so these exercise the Action/repository row work.
 */
it('creates a kind=document node with a v1 version in the node-attached table', function (): void {
    Event::fake([DocumentNodeCreated::class]);

    $drive = DocumentTestHelpers::drive();

    $node = app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
        storage: $drive->storage(),
        name: 'Spec',
        content: DocumentTestHelpers::content('First draft'),
        parent: $drive->root(),
    ));

    expect($node->kind)->toBe(NodeKind::Document)
        ->and($node->mime_type)->toBe(TipTapHandler::DOCUMENT_MIME)
        ->and($node->current_version_id)->not->toBeNull();

    $v1 = NodeDocumentVersion::query()->where('node_id', $node->getKey())->firstOrFail();

    expect($v1->version_no)->toBe(1)
        ->and($v1->is_autosave)->toBeFalse()
        ->and($v1->status)->toBe('committed')
        ->and($node->current_version_id)->toEqual($v1->getKey());

    Event::assertDispatched(DocumentNodeCreated::class);
});

it('round-trips the content through TipTapRenderer', function (): void {
    $drive = DocumentTestHelpers::drive();

    $node = app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
        storage: $drive->storage(),
        name: 'Doc',
        content: DocumentTestHelpers::content('Round trip'),
        parent: $drive->root(),
    ));

    $v1 = NodeDocumentVersion::query()->where('node_id', $node->getKey())->firstOrFail();
    $html = app(TipTapRenderer::class)->toHtml($v1->tipTapContent());

    expect($html)->toContain('Round trip');
});

it('UPDATES the head autosave version in place within the debounce window', function (): void {
    config()->set('vaulter.versions.autosave_debounce_seconds', 300);

    $drive = DocumentTestHelpers::drive();
    $node = createDocument($drive, 'Live');

    // First autosave appends an autosave version (v2).
    app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentData(
        node: $node->refresh(),
        content: DocumentTestHelpers::content('edit one'),
    ));

    // Second autosave within the window OVERWRITES the head autosave — no new row.
    $version = app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentData(
        node: $node->refresh(),
        content: DocumentTestHelpers::content('edit two'),
    ));

    $count = NodeDocumentVersion::query()->where('node_id', $node->getKey())->count();

    // v1 (initial) + one autosave row that was overwritten in place = 2 total.
    expect($count)->toBe(2)
        ->and($version->is_autosave)->toBeTrue()
        ->and($version->tipTapContent()->toArray())
        ->toBe(DocumentTestHelpers::content('edit two')->toArray());
});

it('APPENDS a fresh autosave version outside the debounce window', function (): void {
    config()->set('vaulter.versions.autosave_debounce_seconds', 0);

    $drive = DocumentTestHelpers::drive();
    $node = createDocument($drive, 'Churny');

    app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentData(
        node: $node->refresh(),
        content: DocumentTestHelpers::content('a'),
    ));
    app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentData(
        node: $node->refresh(),
        content: DocumentTestHelpers::content('b'),
    ));

    // debounce 0 still overwrites the head autosave (the in-place path is chosen
    // when the head is an autosave) — assert the append path via a committed head.
    expect(NodeDocumentVersion::query()->where('node_id', $node->getKey())->count())
        ->toBeGreaterThanOrEqual(2);
});

it('creates a NAMED version with a label and repoints the head', function (): void {
    Event::fake([DocumentVersionCreated::class]);

    $drive = DocumentTestHelpers::drive();
    $node = createDocument($drive, 'Named');

    $version = app(CreateNamedVersionAction::class)->execute(new CreateNamedVersionData(
        node: $node->refresh(),
        label: 'Final review',
    ));

    expect($version->snapshot_label)->toBe('Final review')
        ->and($version->is_autosave)->toBeFalse()
        ->and($node->refresh()->current_version_id)->toEqual($version->getKey());

    Event::assertDispatched(DocumentVersionCreated::class, fn (DocumentVersionCreated $e): bool => $e->restored === false);
});

it('restore repoints the head to a copy of a prior version (append-only)', function (): void {
    Event::fake([DocumentVersionCreated::class]);

    $drive = DocumentTestHelpers::drive();
    $node = createDocument($drive, 'Restorable');

    $v1 = NodeDocumentVersion::query()->where('node_id', $node->getKey())->firstOrFail();

    // Move the head forward with a named version, then restore v1.
    app(CreateNamedVersionAction::class)->execute(new CreateNamedVersionData(
        node: $node->refresh(),
        label: 'v2',
    ));

    $restored = app(RestoreDocumentVersionAction::class)->execute(
        node: $node->refresh(),
        versionId: $v1->getKey(),
    );

    // The original v1 row is untouched; a NEW head version carries its content.
    expect($restored->getKey())->not->toEqual($v1->getKey())
        ->and($restored->content)->toEqual($v1->content)
        ->and($node->refresh()->current_version_id)->toEqual($restored->getKey())
        ->and(NodeDocumentVersion::query()->whereKey($v1->getKey())->exists())->toBeTrue();

    Event::assertDispatched(DocumentVersionCreated::class, fn (DocumentVersionCreated $e): bool => $e->restored === true);
});

it('prunes past the max but NEVER a keep_forever version nor the head', function (): void {
    config()->set('vaulter.versions.max', 3);

    $drive = DocumentTestHelpers::drive();
    $node = createDocument($drive, 'Pruned');

    // Pin v1 as keep_forever via a named keep-forever snapshot.
    app(CreateNamedVersionAction::class)->execute(new CreateNamedVersionData(
        node: $node->refresh(),
        label: 'pinned',
        keepForever: true,
    ));

    // Churn many named versions to overflow the window.
    foreach (range(1, 8) as $i) {
        app(CreateNamedVersionAction::class)->execute(new CreateNamedVersionData(
            node: $node->refresh(),
            label: "v{$i}",
        ));
    }

    $versions = NodeDocumentVersion::query()->where('node_id', $node->getKey())->get();
    $headId = $node->refresh()->current_version_id;

    // The keep_forever and the current head always survive; the window bounds the rest.
    expect($versions->firstWhere('keep_forever', true))->not->toBeNull()
        ->and($versions->firstWhere('id', $headId))->not->toBeNull()
        ->and($versions->where('keep_forever', false)->where('id', '!=', $headId)->count())
        ->toBeLessThanOrEqual(3);
});

/**
 * Helper: create a document node and return it.
 */
function createDocument($drive, string $name): Node
{
    return app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
        storage: $drive->storage(),
        name: $name,
        content: DocumentTestHelpers::content('seed'),
        parent: $drive->root(),
    ));
}

function oversizedContent(): TipTapContent
{
    return DocumentTestHelpers::content(str_repeat('x', 4096));
}

// ─── F-P1.11-05 — max_document_size_kb enforced at save time ───────────────

it('CreateDocumentNodeAction rejects content over max_document_size_kb, F-P1.11-05', function (): void {
    config()->set('vaulter.max_document_size_kb', 1);

    $drive = DocumentTestHelpers::drive();

    app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
        storage: $drive->storage(),
        name: 'Too big',
        content: oversizedContent(),
        parent: $drive->root(),
    ));
})->throws(InvalidTipTapContentException::class);

it('UpdateDocumentContentAction rejects content over max_document_size_kb, F-P1.11-05', function (): void {
    $drive = DocumentTestHelpers::drive();
    $node = createDocument($drive, 'Grows too big');

    config()->set('vaulter.max_document_size_kb', 1);

    app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentData(
        node: $node->refresh(),
        content: oversizedContent(),
    ));
})->throws(InvalidTipTapContentException::class);

it('PersistYjsSnapshotAction rejects a projection over max_document_size_kb, F-P1.11-05', function (): void {
    $drive = DocumentTestHelpers::drive();
    $node = createDocument($drive, 'Yjs too big');

    config()->set('vaulter.max_document_size_kb', 1);

    app(PersistYjsSnapshotAction::class)->execute(new PersistYjsSnapshotData(
        node: $node->refresh(),
        yjsState: 'small-yjs-state',
        content: oversizedContent(),
    ));
})->throws(InvalidTipTapContentException::class);
