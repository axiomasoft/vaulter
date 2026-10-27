<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Data\Documents\DocumentStateResult;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\State\CollaborativeState;
use Vaulter\Documents\State\CollaborativeStateTransition;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\ValueObjects\Access\ActorRef;

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
        collabState: "yjs\0bytes",
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
        ->and($checkpoint?->collab_state)->toBe("yjs\0bytes")
        ->and($checkpoint?->collab_state_size)->toBe(9)
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

    $collab = writeState($node, new DocumentStateChange(cause: StateWriteCause::Collaboration, schemaVersion: 1, content: null, collabState: "room\0state"));
    $restore = writeState($node, new DocumentStateChange(
        cause: StateWriteCause::Restore,
        schemaVersion: 1,
        content: DocumentTestHelpers::content('v1'),
        collabState: null,
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

it('keeps the collaborative state a generation closes over in a revision before replacing it', function (StateWriteCause $cause): void {
    $node = writerDocument();
    $source = DocumentTestHelpers::head($node);
    // Collaboration wrote a state the interval did not checkpoint: no revision holds it.
    writeState($node, new DocumentStateChange(cause: StateWriteCause::Collaboration, schemaVersion: 1, content: DocumentTestHelpers::content('saved edits'), collabState: "room\0state"));

    $closing = writeState($node, new DocumentStateChange(
        cause: $cause,
        schemaVersion: 1,
        content: DocumentTestHelpers::content('replacement'),
        collabState: null,
        forceCheckpoint: $cause === StateWriteCause::Restore,
        restoredFrom: $cause === StateWriteCause::Restore ? $source : null,
    ));

    $kept = DocumentTestHelpers::revisions($node)->firstWhere('collab_state', "room\0state");
    expect($closing->epochChanged)->toBeTrue()
        ->and($kept)->not->toBeNull()
        ->and($kept?->content)->toEqual(DocumentTestHelpers::content('saved edits')->toArray())
        ->and($kept?->state_version)->toBe(2)
        ->and($kept?->is_autosave)->toBeTrue()
        ->and($closing->document->content)->toEqual(DocumentTestHelpers::content('replacement')->toArray());
})->with(['restore' => [StateWriteCause::Restore], 'JSON replace' => [StateWriteCause::Update]]);

it('adds no revision when the state a generation closes over is already one, or holds no Yjs state', function (bool $collaborative): void {
    $node = writerDocument();
    $source = DocumentTestHelpers::head($node);
    if ($collaborative) {
        writeState($node, new DocumentStateChange(cause: StateWriteCause::Collaboration, schemaVersion: 1, content: DocumentTestHelpers::content('saved'), collabState: 'checkpointed', forceCheckpoint: true));
    }
    $before = DocumentTestHelpers::document($node)->revision_count;

    writeState($node, new DocumentStateChange(cause: StateWriteCause::Restore, schemaVersion: 1, content: DocumentTestHelpers::content('v1'), collabState: null, forceCheckpoint: true, restoredFrom: $source));

    expect(DocumentTestHelpers::document($node)->revision_count)->toBe($before + 1);
})->with(['checkpointed Yjs state' => [true], 'no Yjs state' => [false]]);

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
    app(VaulterDatabase::class)->write(fn () => Document::query()->where('node_id', $node->id)->update(['tenant_id' => 'tenant-a']));

    $checkpoint = writeState($node, new DocumentStateChange(cause: StateWriteCause::Update, schemaVersion: 1, content: DocumentTestHelpers::content('t'), forceCheckpoint: true))->checkpoint;

    expect((string) $checkpoint?->tenant_id)->toBe('tenant-a');
});

it('rejects rich-text prepared payload after a locked Markdown transition without imposing a version CAS on normal updates', function (): void {
    $node = writerDocument();
    $raw = ['type' => 'doc', 'content' => [['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [['type' => 'paragraph']]]]]]]]];
    $prepared = app(ContentDecoder::class)->decode(new RichTextDocumentType, $raw);
    app(VaulterDatabase::class)->transaction(function () use ($node): void {
        $state = app(CollaborativeState::class);
        $locked = $state->lock($node->getKey());
        expect($state->transition($locked, new CollaborativeStateTransition(
            $locked->binding, $locked->stateVersion, 'vaulter/markdown', 1, 'yjs', 'yjs-update-v1',
            DocumentTestHelpers::content('converted'), null, new ActorRef('staff', '42'), 'prepared-race',
        ))->published)->toBeTrue();
    });
    $events = [];
    app('events')->listen(DocumentContentUpdated::class, function ($event) use (&$events): void {
        $events[] = $event;
    });
    $snapshot = static fn () => array_map(static fn ($t) => DB::table($t)->get()->toJson(), ['v_nodes', 'v_documents', 'v_document_revisions']);
    $before = $snapshot();
    $action = app(UpdateDocumentContentAction::class);
    $context = DocumentTestHelpers::context($node);
    expect(fn () => $action->execute($context, new UpdateDocumentContentInput($node, $prepared)))
        ->toThrow(InvalidDocumentContentException::class);
    expect($snapshot())->toBe($before)->and($events)->toBe([]);
    $normal = $action->execute($context, new UpdateDocumentContentInput($node, DocumentTestHelpers::content('valid unconditional')));
    expect($normal->changed)->toBeTrue()->and($normal->document->type)->toBe('vaulter/markdown');
});
