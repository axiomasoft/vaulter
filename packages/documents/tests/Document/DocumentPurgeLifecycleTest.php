<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Models\Revisions\DocumentRevisionPayload;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionStoreRepository;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Fixtures\Types\ManagedNoteType;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\CopyOptions;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Support\Identity;

/*
 * Purge deletes the document's revisions itself and then its row, before the node goes.
 * The SQLite test connection enforces no cascade, so these scenarios prove the explicit
 * delete rather than the foreign-key safety net.
 */
beforeEach(function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
});

/** A document node with three revisions. */
function purgeLifecycleDocument(DriveHandle $drive, string $name): Node
{
    $node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: $name,
        content: DocumentTestHelpers::content('one'),
        parent: DriveTestHelpers::model($drive->root()),
    ));
    foreach (['two', 'three'] as $text) {
        app(UpdateDocumentContentAction::class)->execute(DocumentTestHelpers::context($node), new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content($text)));
    }

    return $node;
}

function purgeLifecycleRevisions(int|string $documentId): int
{
    return DocumentRevision::query()->where('document_id', $documentId)->count();
}

it('purges the copy\'s revisions and leaves the original intact', function (): void {
    $drive = DocumentTestHelpers::drive();
    $source = purgeLifecycleDocument($drive, 'Original');
    $original = DocumentTestHelpers::document($source);
    $copyNode = DriveTestHelpers::model($drive->nodes()->copy($source->getKey(), options: new CopyOptions(name: 'Copy')));
    $copy = DocumentTestHelpers::document($copyNode);
    expect(purgeLifecycleRevisions($copy->getKey()))->toBe(1);

    $drive->nodes()->trash($copyNode->getKey());
    $drive->trash()->purge($copyNode->getKey());

    expect(Node::query()->whereKey($copyNode->getKey())->exists())->toBeFalse()
        ->and(Document::query()->whereKey($copy->getKey())->exists())->toBeFalse()
        ->and(purgeLifecycleRevisions($copy->getKey()))->toBe(0)
        ->and(Document::query()->whereKey($original->getKey())->exists())->toBeTrue()
        ->and(purgeLifecycleRevisions($original->getKey()))->toBe(3)
        ->and(DocumentTestHelpers::document($source)->current_revision_id)->toBe($original->current_revision_id);
});

it('purges a history longer than one delete batch', function (): void {
    $drive = DocumentTestHelpers::drive();
    $node = purgeLifecycleDocument($drive, 'Long history');
    $document = DocumentTestHelpers::document($node);
    $template = (array) DB::table((new DocumentRevision)->getTable())->where('document_id', $document->getKey())->orderByDesc('number')->first();
    unset($template['id']);
    foreach (array_chunk(range(4, 1203), 300) as $numbers) {
        DB::table((new DocumentRevision)->getTable())->insert(array_map(
            static fn (int $number): array => [...$template, 'number' => $number, 'keep_forever' => false],
            $numbers,
        ));
    }
    expect(purgeLifecycleRevisions($document->getKey()))->toBe(1203);

    $drive->nodes()->trash($node->getKey());
    $drive->trash()->purge($node->getKey());

    expect(Document::query()->whereKey($document->getKey())->exists())->toBeFalse()
        ->and(purgeLifecycleRevisions($document->getKey()))->toBe(0);
});

it('purges a folder together with the documents inside it', function (): void {
    $drive = DocumentTestHelpers::drive();
    $folder = $drive->folders()->create('Folder');
    $node = purgeLifecycleDocument($drive, 'Inside');
    $drive->nodes()->move($node->getKey(), $folder->id);
    $document = DocumentTestHelpers::document($node);

    $drive->nodes()->trash($folder->id);
    $drive->trash()->purge($folder->id);

    expect(Document::query()->whereKey($document->getKey())->exists())->toBeFalse()
        ->and(purgeLifecycleRevisions($document->getKey()))->toBe(0);
});

/*
 * A managed codec keeps references from stored payloads to external objects (the sheets
 * workbook blobs, for one). Documents must tell it about every payload it writes and
 * every payload it deletes, inside the same transaction, or a blob is either released
 * while a checkpoint still names it or never released at all.
 */
function useManagedNoteType(): ManagedNoteType
{
    $managed = new ManagedNoteType;
    $registry = new DocumentTypeRegistry(app());
    $registry->register(new RichTextDocumentType);
    $registry->register($managed);
    $registry->freeze();
    app()->instance(DocumentTypeRegistry::class, $registry);

    return $managed;
}

/** @return list<string> */
function managedRevisionIds(int|string $documentId): array
{
    return array_values(array_map(Identity::stringify(...), DocumentRevision::query()->where('document_id', $documentId)->orderBy('id')->pluck('id')->all()));
}

it('reports the working and checkpoint references of every write to the managed codec', function (): void {
    $managed = useManagedNoteType();
    $drive = DocumentTestHelpers::drive();
    $documents = Documents::in($drive);
    $node = $documents->create('Managed', ManagedNoteType::KEY, ['ref' => 'blob-a']);
    $documents->update($node, ['ref' => 'blob-b']);

    $document = Document::query()->where('node_id', $node->id)->firstOrFail();
    $id = Identity::stringify($document->getKey());
    [$first, $second] = managedRevisionIds($id);

    expect($managed->managedCodec->changes)->toBe([
        "working {$id} -→blob-a",
        "revision {$first} -→blob-a",
        "working {$id} blob-a→blob-b",
        "revision {$second} -→blob-b",
    ]);
});

it('releases the reference of every purged checkpoint and keeps the others', function (): void {
    $managed = useManagedNoteType();
    $drive = DocumentTestHelpers::drive();
    $documents = Documents::in($drive);
    $node = $documents->create('Managed', ManagedNoteType::KEY, ['ref' => 'blob-a']);
    $documents->update($node, ['ref' => 'blob-b']);
    $documents->update($node, ['ref' => 'blob-c']);
    $document = Document::query()->where('node_id', $node->id)->firstOrFail();
    [$first, $second, $third] = managedRevisionIds(Identity::stringify($document->getKey()));
    $managed->managedCodec->changes = [];

    // Resolved after the registry swap; the node purge path is memoized at boot.
    app(VaulterDatabase::class)->transaction(static fn () => app(DocumentRevisionStoreRepository::class)->purge([$first, $second]));

    expect($managed->managedCodec->changes)->toBe([
        "revision {$first} blob-a→-",
        "revision {$second} blob-b→-",
    ])->and(managedRevisionIds(Identity::stringify($document->getKey())))->toBe([$third]);
});

it('purges an unmanaged history without reading its checkpoint payloads', function (): void {
    $drive = DocumentTestHelpers::drive();
    $documents = Documents::in($drive);
    $node = $documents->create('Plain');
    $document = Document::query()->where('node_id', $node->id)->firstOrFail();
    foreach (['two', 'three'] as $text) {
        $documents->update($node, DocumentTestHelpers::content($text)->toArray());
    }
    $payloads = (new DocumentRevisionPayload)->getTable();
    $reads = [];
    DB::listen(static function (QueryExecuted $query) use ($payloads, &$reads): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select') && str_contains($query->sql, $payloads)) {
            $reads[] = $query->sql;
        }
    });

    $drive->nodes()->trash($node->id);
    $drive->trash()->purge($node->id);

    expect($reads)->toBe([])
        ->and(Document::query()->whereKey($document->getKey())->exists())->toBeFalse();
});
