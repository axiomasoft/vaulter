<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;

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

it('V33 purges the copy\'s revisions and leaves the original intact', function (): void {
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
