<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Data\Documents\DocumentStateResult;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;

/*
 * DocumentStateWriter is the only writer of the working state (P4-design#WorkingState).
 * These pin its algorithm directly: the checkpoint rule (forced, missing head, interval),
 * what a checkpoint captures, the epoch rule, prune, and that the node only gets its
 * change stamp.
 */
function writerDocument(): Node
{
    $drive = DocumentTestHelpers::drive();

    return app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Writer '.bin2hex(random_bytes(3)),
        content: DocumentTestHelpers::content('v1'),
        parent: DriveTestHelpers::model($drive->root()),
    ));
}

function writeState(Node $node, DocumentStateChange $change): DocumentStateResult
{
    return app(VaulterDatabase::class)->transaction(static function () use ($node, $change): DocumentStateResult {
        $document = Document::query()->where('node_id', $node->id)->lockForUpdate()->firstOrFail();
        $document->setRelation('node', $node);

        return app(DocumentStateWriter::class)->write($document, $change);
    });
}

it('captures the working state verbatim into a checkpoint', function (): void {
    $node = writerDocument();

    $result = writeState($node, new DocumentStateChange(
        cause: StateWriteCause::Update,
        content: DocumentTestHelpers::content('captured'),
        yjsState: "yjs\0bytes",
        schemaVersion: 2,
        actorId: 5,
        forceCheckpoint: true,
        label: 'labelled',
        keepForever: true,
    ));
    $checkpoint = $result->checkpoint;

    expect($checkpoint)->toBeInstanceOf(DocumentRevision::class)
        ->and($checkpoint?->number)->toBe(2)
        ->and($checkpoint?->content)->toEqual(DocumentTestHelpers::content('captured')->toArray())
        ->and($checkpoint?->content_hash)->toBe(DocumentTestHelpers::content('captured')->contentHash())
        ->and($checkpoint?->content_size)->toBe(DocumentTestHelpers::content('captured')->sizeInBytes())
        ->and($checkpoint?->yjs_state)->toBe("yjs\0bytes")
        ->and($checkpoint?->yjs_state_size)->toBe(9)
        ->and($checkpoint?->schema_version)->toBe(2)
        ->and($checkpoint?->state_version)->toBe(2)
        ->and($checkpoint?->label)->toBe('labelled')
        ->and($checkpoint?->keep_forever)->toBeTrue()
        ->and($checkpoint?->is_autosave)->toBeFalse()
        ->and((string) $checkpoint?->created_by)->toBe('5')
        ->and($result->document->current_revision_id)->toEqual($checkpoint?->id)
        ->and($result->document->checkpoint_state_version)->toBe(2)
        ->and($result->document->revision_count)->toBe(2)
        ->and((string) $result->document->updated_by)->toBe('5');
});

it('checkpoints by interval: none inside it, an autosave one after it', function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 60);
    $node = writerDocument();

    $inside = writeState($node, new DocumentStateChange(cause: StateWriteCause::Update, schemaVersion: 1, content: DocumentTestHelpers::content('inside')));
    $this->travel(61)->seconds();
    $after = writeState($node, new DocumentStateChange(cause: StateWriteCause::Update, schemaVersion: 1, content: DocumentTestHelpers::content('after')));

    expect($inside->checkpoint)->toBeNull()
        ->and($after->checkpoint?->is_autosave)->toBeTrue()
        ->and($after->checkpoint?->state_version)->toBe(3)
        ->and($after->checkpoint?->content)->toEqual(DocumentTestHelpers::content('after')->toArray());
});

it('takes a named checkpoint of an unchanged state without moving its validators', function (): void {
    $node = writerDocument();

    $checkpoint = app(VaulterDatabase::class)->transaction(static function () use ($node): DocumentRevision {
        $document = Document::query()->where('node_id', $node->id)->lockForUpdate()->firstOrFail();

        return app(DocumentStateWriter::class)->checkpoint($document, label: 'again', keepForever: false, actorId: null);
    });
    $document = DocumentTestHelpers::document($node);

    expect($checkpoint->number)->toBe(2)
        ->and($checkpoint->content)->toBe(DocumentTestHelpers::revisions($node)->first()?->content)
        ->and($checkpoint->is_autosave)->toBeFalse()
        ->and($document->state_version)->toBe(1)
        ->and($document->collab_epoch)->toBe(0)
        ->and($document->current_revision_id)->toEqual($checkpoint->id);
});

it('moves the epoch for restore and for a JSON replace of Yjs state, never for collaboration', function (): void {
    $node = writerDocument();
    $source = DocumentTestHelpers::head($node);

    $collab = writeState($node, new DocumentStateChange(cause: StateWriteCause::Collaboration, schemaVersion: 1, content: null, yjsState: "room\0state"));
    $restore = writeState($node, new DocumentStateChange(
        cause: StateWriteCause::Restore,
        schemaVersion: 1,
        content: DocumentTestHelpers::content('v1'),
        yjsState: null,
        forceCheckpoint: true,
        restoredFrom: $source,
    ));

    expect($collab->epochChanged)->toBeFalse()
        ->and($collab->document->collab_epoch)->toBe(0)
        ->and($collab->document->content)->toEqual(DocumentTestHelpers::content('v1')->toArray())
        ->and($restore->epochChanged)->toBeTrue()
        ->and($restore->document->collab_epoch)->toBe(1)
        ->and($restore->checkpoint?->restored_from_revision_id)->toEqual($source->id);
});

it('prunes after a checkpoint with the head and pinned checkpoints kept', function (): void {
    config()->set('vaulter-documents.revisions.limit', 1);
    $node = writerDocument();
    $pinned = writeState($node, new DocumentStateChange(cause: StateWriteCause::Update, schemaVersion: 1, content: DocumentTestHelpers::content('pin'), forceCheckpoint: true, keepForever: true))->checkpoint;

    foreach (['a', 'b', 'c'] as $text) {
        writeState($node, new DocumentStateChange(cause: StateWriteCause::Update, schemaVersion: 1, content: DocumentTestHelpers::content($text), forceCheckpoint: true));
    }

    $numbers = DocumentTestHelpers::revisions($node)->pluck('number')->all();
    expect($numbers)->toBe([$pinned?->number, 4, 5])
        ->and(DocumentTestHelpers::document($node)->revision_count)->toBe(5);
});

it('stamps the node change but never its sequence', function (): void {
    $node = writerDocument();
    $sequence = $node->refresh()->sequence;

    writeState($node, new DocumentStateChange(cause: StateWriteCause::Update, schemaVersion: 1, content: DocumentTestHelpers::content('touch'), actorId: 9));

    expect($node->refresh()->sequence)->toBe($sequence)
        ->and((string) $node->updated_by)->toBe('9');
});

it('copies the tenant of the aggregate onto its checkpoints', function (): void {
    $node = writerDocument();
    Document::query()->where('node_id', $node->id)->update(['tenant_id' => 'tenant-a']);

    $checkpoint = writeState($node, new DocumentStateChange(cause: StateWriteCause::Update, schemaVersion: 1, content: DocumentTestHelpers::content('t'), forceCheckpoint: true))->checkpoint;

    expect((string) $checkpoint?->tenant_id)->toBe('tenant-a');
});
