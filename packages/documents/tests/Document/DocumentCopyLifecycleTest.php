<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\CopyOptions;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

/*
 * A document copy is a new aggregate holding the source's working state as its
 * only revision. History, the collaboration epoch and the validators stay with the source.
 */
beforeEach(function (): void {
    // Every write becomes a checkpoint, so three writes leave three revisions.
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
});

/** A document with 3 revisions, state version 5 and collaboration epoch 2. */
function copyLifecycleSource(DriveHandle $drive, string $name = 'Source'): Node
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
    app(VaulterDatabase::class)->write(function () use ($node): void {
        $document = DocumentTestHelpers::document($node);
        $document->forceFill(['state_version' => 5, 'collab_epoch' => 2])->save();
        $document->syncState?->forceFill(['collab_state' => 'yjs-bytes', 'collab_state_size' => 9])->save();
    });

    return $node;
}

function copyLifecycleRevisionCount(Document $document): int
{
    return DocumentRevision::query()->where('document_id', $document->getKey())->count();
}

it('V33 copies a document into a new aggregate with one revision and its own working state', function (): void {
    $drive = DocumentTestHelpers::drive();
    $source = copyLifecycleSource($drive);
    $original = DocumentTestHelpers::document($source);
    expect($original->revision_count)->toBe(3);
    $used = $drive->model()->used_bytes;

    $copyNode = DriveTestHelpers::model($drive->nodes()->copy($source->getKey(), options: new CopyOptions(name: 'Copy')));
    $copy = DocumentTestHelpers::document($copyNode);

    expect($copy->getKey())->not->toBe($original->getKey())
        ->and($copy->type)->toBe($original->type)
        ->and($copy->schema_version)->toBe($original->schema_version)
        ->and($copy->payload?->content)->toBe($original->payload?->content)
        ->and($copy->payload?->content_hash)->toBe($original->payload?->content_hash)
        ->and($copy->syncState?->collab_state)->toBe('yjs-bytes')
        ->and($copy->revision_count)->toBe(1)
        ->and(copyLifecycleRevisionCount($copy))->toBe(1)
        ->and($copy->collab_epoch)->toBe(0)
        ->and($copy->state_version)->toBe(1)
        ->and($copy->checkpoint_state_version)->toBe(1)
        ->and($copy->current_revision_id)->toBe(DocumentRevision::query()->where('document_id', $copy->getKey())->value('id'))
        ->and(DocumentTestHelpers::head($copyNode)->is_autosave)->toBeFalse();

    $after = DocumentTestHelpers::document($source);
    expect($after->state_version)->toBe(5)
        ->and($after->collab_epoch)->toBe(2)
        ->and($after->revision_count)->toBe(3)
        ->and(copyLifecycleRevisionCount($after))->toBe(3)
        ->and($drive->model()->refresh()->used_bytes)->toBe($used);
});

it('V33 copies a folder with a document inside', function (): void {
    $drive = DocumentTestHelpers::drive();
    $source = copyLifecycleSource($drive);
    $folder = $drive->folders()->create('Folder');
    $drive->nodes()->move($source->getKey(), $folder->id);

    $copyFolder = $drive->nodes()->copy($folder->id, options: new CopyOptions(name: 'Folder copy'));

    $copyNode = Node::query()->where('parent_id', $copyFolder->id)->firstOrFail();
    $copy = DocumentTestHelpers::document($copyNode);
    expect($copyNode->kind->value)->toBe('document')
        ->and($copy->revision_count)->toBe(1)
        ->and($copy->collab_epoch)->toBe(0)
        ->and($copy->state_version)->toBe(1)
        ->and(DocumentTestHelpers::document($source)->revision_count)->toBe(3);
});

it('copies the content of a document whose type is not registered, as it is', function (): void {
    $drive = DocumentTestHelpers::drive();
    $source = copyLifecycleSource($drive);
    app(VaulterDatabase::class)->write(fn () => Document::query()->where('node_id', $source->getKey())->update(['type' => 'vendor/unknown']));

    $copyNode = DriveTestHelpers::model($drive->nodes()->copy($source->getKey(), options: new CopyOptions(name: 'Opaque copy')));
    $copy = DocumentTestHelpers::document($copyNode);

    expect($copy->type)->toBe('vendor/unknown')
        ->and($copy->payload?->content)->toBe(DocumentTestHelpers::document($source)->payload?->content);
});
