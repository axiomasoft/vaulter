<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Collaboration\PersistCollaborationStateAction;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Revisions\CreateNamedRevisionAction;
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;
use Vaulter\Documents\Data\Collaboration\PersistCollaborationStateInput;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Data\Revisions\CreateNamedRevisionInput;
use Vaulter\Documents\Data\Revisions\RestoreDocumentRevisionInput;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Models\Drive\Node;

/*
 * I6 for documents: the head of a document is always one of its own checkpoints.
 * Every writer path keeps it so (SQLite has nothing else); on PostgreSQL and MySQL the
 * composite head FK (current_revision_id, id) → v_document_revisions(id, document_id)
 * with ON DELETE RESTRICT also refuses a foreign head and the deletion of a head.
 */
function headIntegrityDocument(string $name): Node
{
    $drive = DocumentTestHelpers::drive();

    return app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: $name,
        content: DocumentTestHelpers::content($name),
        parent: DriveTestHelpers::model($drive->root()),
    ));
}

function assertOwnHead(Node $node): void
{
    $document = DocumentTestHelpers::document($node);
    $head = DocumentRevision::query()->whereKey($document->current_revision_id)->firstOrFail();

    expect($head->document_id)->toEqual($document->id)
        ->and($document->revision_count)->toBeGreaterThanOrEqual($head->number);
}

it('keeps the head inside its own document through every writer', function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
    config()->set('vaulter-documents.revisions.limit', 2);
    $node = headIntegrityDocument('Own head');
    headIntegrityDocument('Neighbour');
    assertOwnHead($node);

    app(UpdateDocumentContentAction::class)->execute(DocumentTestHelpers::context($node), new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content('update')));
    assertOwnHead($node);
    app(PersistCollaborationStateAction::class)->execute(new PersistCollaborationStateInput(node: $node, yjsState: "yjs\0state"));
    assertOwnHead($node);
    $named = app(CreateNamedRevisionAction::class)->execute(DocumentTestHelpers::context($node), new CreateNamedRevisionInput(node: $node, label: 'named', keepForever: true));
    assertOwnHead($node);
    app(UpdateDocumentContentAction::class)->execute(DocumentTestHelpers::context($node), new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content('moved on')));
    app(RestoreDocumentRevisionAction::class)->execute(DocumentTestHelpers::context($node), new RestoreDocumentRevisionInput(node: $node, revision: $named->id));
    assertOwnHead($node);
});

it('enforces the head foreign key in the database where the driver supports it', function (): void {
    $driver = app(VaulterDatabase::class)->driver();
    $documents = (new Document)->getTable();
    $keys = array_column(app(VaulterDatabase::class)->connection()->getSchemaBuilder()->getForeignKeys($documents), null, 'name');

    if ($driver === 'sqlite') {
        // SQLite cannot add the constraint after both tables exist; the writer test above
        // is the whole guarantee there (docs/reference/compatibility.md).
        expect($keys)->not->toHaveKey($documents.'_head_fk');

        return;
    }

    expect($keys[$documents.'_head_fk']['columns'])->toBe(['current_revision_id', 'id'])
        ->and($keys[$documents.'_head_fk']['foreign_columns'])->toBe(['id', 'document_id'])
        ->and(strtolower((string) $keys[$documents.'_head_fk']['on_delete']))->toBe('restrict');

    $node = headIntegrityDocument('Guarded');
    $other = headIntegrityDocument('Other');
    $head = DocumentTestHelpers::head($node);
    $db = app(VaulterDatabase::class);

    // Each violation runs in its own savepoint so the surrounding test transaction survives.
    expect(fn () => $db->transaction(fn () => DocumentRevision::query()->whereKey($head->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => $db->transaction(fn () => Document::query()->where('node_id', $node->id)
            ->update(['current_revision_id' => DocumentTestHelpers::head($other)->id])))->toThrow(QueryException::class);

    expect(DocumentRevision::query()->whereKey($head->id)->exists())->toBeTrue()
        ->and(DocumentTestHelpers::document($node)->current_revision_id)->toEqual($head->id);
});
