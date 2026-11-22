<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
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
use Vaulter\Documents\Models\Documents\DocumentPayload;
use Vaulter\Documents\Models\Documents\DocumentSyncState;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Models\Revisions\DocumentRevisionSyncState;
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

it('reads streamed collaboration state repeatedly without consuming its stored bytes', function (): void {
    $bytes = "room\0state\xff";
    foreach ([new DocumentSyncState, new DocumentRevisionSyncState] as $model) {
        $stream = fopen('php://memory', 'r+');
        expect($stream)->toBeResource();
        try {
            fwrite($stream, $bytes);
            rewind($stream);
            $model->setRawAttributes(['collab_state' => $stream], sync: true);

            expect($model->collab_state)->toBe($bytes)
                ->and($model->collab_state)->toBe($bytes)
                ->and(ftell($stream))->toBe(0);
            fseek($stream, 4);
            expect($model->collab_state)->toBe($bytes)
                ->and(ftell($stream))->toBe(4);
        } finally {
            fclose($stream);
        }
    }
});

it('captures the working state verbatim into a checkpoint', function (): void {
    $node = writerDocument();

    $result = writeState($node, new DocumentStateChange(
        cause: StateWriteCause::Update,
        content: DocumentTestHelpers::content('captured'),
        schemaVersion: 2,
        collabState: "yjs\0bytes",
        actorId: 5,
        forceCheckpoint: true,
        label: 'labelled',
        keepForever: true,
    ));
    $checkpoint = $result->checkpoint;

    expect($checkpoint)->toBeInstanceOf(DocumentRevision::class)
        ->and($checkpoint?->number)->toBe(2)
        ->and($checkpoint?->payload?->content)->toEqual(DocumentTestHelpers::content('captured')->toArray())
        ->and($checkpoint?->payload?->content_hash)->toBe(DocumentTestHelpers::content('captured')->contentHash())
        ->and($checkpoint?->payload?->content_size)->toBe(DocumentTestHelpers::content('captured')->sizeInBytes())
        ->and($checkpoint?->syncState?->collab_state)->toBe("yjs\0bytes")
        ->and($checkpoint?->syncState?->collab_state_size)->toBe(9)
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

    $inside = writeState($node, new DocumentStateChange(cause: StateWriteCause::Update, content: DocumentTestHelpers::content('inside'), schemaVersion: 1));
    $this->travel(61)->seconds();
    $after = writeState($node, new DocumentStateChange(cause: StateWriteCause::Update, content: DocumentTestHelpers::content('after'), schemaVersion: 1));

    expect($inside->checkpoint)->toBeNull()
        ->and($after->checkpoint?->is_autosave)->toBeTrue()
        ->and($after->checkpoint?->state_version)->toBe(3)
        ->and($after->checkpoint?->payload?->content)->toEqual(DocumentTestHelpers::content('after')->toArray());
});

it('takes a named checkpoint of an unchanged state without moving its validators', function (): void {
    $node = writerDocument();

    $checkpoint = app(VaulterDatabase::class)->transaction(static function () use ($node): DocumentRevision {
        $document = Document::query()->where('node_id', $node->id)->lockForUpdate()->firstOrFail();

        return app(DocumentStateWriter::class)->checkpoint($document, label: 'again', keepForever: false, actorId: null);
    });
    $document = DocumentTestHelpers::document($node);

    expect($checkpoint->number)->toBe(2)
        ->and($checkpoint->payload?->content)->toBe(DocumentTestHelpers::revisions($node)->first()?->payload?->content)
        ->and($checkpoint->is_autosave)->toBeFalse()
        ->and($document->state_version)->toBe(1)
        ->and($document->collab_epoch)->toBe(0)
        ->and($document->current_revision_id)->toEqual($checkpoint->id);
});

it('moves the epoch for restore and for a JSON replace of Yjs state, never for collaboration', function (): void {
    $node = writerDocument();
    $source = DocumentTestHelpers::head($node);

    $collab = writeState($node, new DocumentStateChange(cause: StateWriteCause::Collaboration, content: null, schemaVersion: 1, collabState: "room\0state"));
    $restore = writeState($node, new DocumentStateChange(
        cause: StateWriteCause::Restore,
        content: DocumentTestHelpers::content('v1'),
        schemaVersion: 1,
        forceCheckpoint: true,
        restoredFrom: $source,
    ));

    expect($collab->epochChanged)->toBeFalse()
        ->and($collab->document->collab_epoch)->toBe(0)
        ->and($collab->document->payload?->content)->toEqual(DocumentTestHelpers::content('v1')->toArray())
        ->and($restore->epochChanged)->toBeTrue()
        ->and($restore->document->collab_epoch)->toBe(1)
        ->and($restore->checkpoint?->restored_from_revision_id)->toEqual($source->id);
});

it('keeps the collaborative state a generation closes over in a revision before replacing it', function (StateWriteCause $cause): void {
    $node = writerDocument();
    $source = DocumentTestHelpers::head($node);
    // Collaboration wrote a state the interval did not checkpoint: no revision holds it.
    writeState($node, new DocumentStateChange(cause: StateWriteCause::Collaboration, content: DocumentTestHelpers::content('saved edits'), schemaVersion: 1, collabState: "room\0state"));

    $closing = writeState($node, new DocumentStateChange(
        cause: $cause,
        content: DocumentTestHelpers::content('replacement'),
        schemaVersion: 1,
        forceCheckpoint: $cause === StateWriteCause::Restore,
        restoredFrom: $cause === StateWriteCause::Restore ? $source : null,
    ));

    $kept = DocumentTestHelpers::revisions($node)->firstWhere('syncState.collab_state', "room\0state");
    expect($closing->epochChanged)->toBeTrue()
        ->and($kept)->not->toBeNull()
        ->and($kept?->payload?->content)->toEqual(DocumentTestHelpers::content('saved edits')->toArray())
        ->and($kept?->state_version)->toBe(2)
        ->and($kept?->is_autosave)->toBeTrue()
        ->and($closing->document->payload?->content)->toEqual(DocumentTestHelpers::content('replacement')->toArray());
})->with(['restore' => [StateWriteCause::Restore], 'transition' => [StateWriteCause::Transition]]);

it('adds no revision when the state a generation closes over is already one, or holds no Yjs state', function (bool $collaborative): void {
    $node = writerDocument();
    $source = DocumentTestHelpers::head($node);
    if ($collaborative) {
        writeState($node, new DocumentStateChange(cause: StateWriteCause::Collaboration, content: DocumentTestHelpers::content('saved'), schemaVersion: 1, collabState: 'checkpointed', forceCheckpoint: true));
    }
    $before = DocumentTestHelpers::document($node)->revision_count;

    writeState($node, new DocumentStateChange(cause: StateWriteCause::Restore, content: DocumentTestHelpers::content('v1'), schemaVersion: 1, forceCheckpoint: true, restoredFrom: $source));

    expect(DocumentTestHelpers::document($node)->revision_count)->toBe($before + 1);
})->with(['checkpointed Yjs state' => [true], 'no Yjs state' => [false]]);

it('prunes after a checkpoint with the head and pinned checkpoints kept', function (): void {
    config()->set('vaulter-documents.revisions.limit', 1);
    $node = writerDocument();
    $pinned = writeState($node, new DocumentStateChange(cause: StateWriteCause::Update, content: DocumentTestHelpers::content('pin'), schemaVersion: 1, forceCheckpoint: true, keepForever: true))->checkpoint;

    foreach (['a', 'b', 'c'] as $text) {
        writeState($node, new DocumentStateChange(cause: StateWriteCause::Update, content: DocumentTestHelpers::content($text), schemaVersion: 1, forceCheckpoint: true));
    }

    $numbers = DocumentTestHelpers::revisions($node)->pluck('number')->all();
    expect($numbers)->toBe([$pinned?->number, 4, 5])
        ->and(DocumentTestHelpers::document($node)->revision_count)->toBe(5);
});

it('stamps the node change but never its sequence', function (): void {
    $node = writerDocument();
    $sequence = $node->refresh()->sequence;

    writeState($node, new DocumentStateChange(cause: StateWriteCause::Update, content: DocumentTestHelpers::content('touch'), schemaVersion: 1, actorId: 9));

    expect($node->refresh()->sequence)->toBe($sequence)
        ->and((string) $node->updated_by)->toBe('9');
});

it('copies the tenant of the aggregate onto its checkpoints', function (): void {
    $node = writerDocument();
    app(VaulterDatabase::class)->write(fn () => Document::query()->where('node_id', $node->id)->update(['tenant_id' => 'tenant-a']));

    $checkpoint = writeState($node, new DocumentStateChange(cause: StateWriteCause::Update, content: DocumentTestHelpers::content('t'), schemaVersion: 1, forceCheckpoint: true))->checkpoint;

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
    $snapshot = static fn (): array => array_map(static fn (string $t) => DB::table($t)->get()->toJson(), ['v_nodes', 'v_documents', 'v_document_revisions']);
    $before = $snapshot();
    $action = app(UpdateDocumentContentAction::class);
    $context = DocumentTestHelpers::context($node);
    expect(fn () => $action->execute($context, new UpdateDocumentContentInput($node, $prepared)))
        ->toThrow(InvalidDocumentContentException::class);
    expect($snapshot())->toBe($before)->and($events)->toBe([]);
    $normal = $action->execute($context, new UpdateDocumentContentInput($node, DocumentTestHelpers::content('valid unconditional')));
    expect($normal->changed)->toBeTrue()->and($normal->document->type)->toBe('vaulter/markdown');
});

it('rolls back head metadata and the checkpoint when the payload write fails its constraint', function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
    $node = writerDocument();
    $before = DocumentTestHelpers::document($node);
    $hash = $before->payload?->content_hash;
    $outbox = DB::table('v_outbox')->count();

    app('events')->listen('eloquent.updating: '.DocumentPayload::class, static function (DocumentPayload $payload) use ($before): void {
        if ($payload->document_id === $before->id) {
            $payload->forceFill(['content_size' => -1]);
        }
    });
    expect(fn () => app(UpdateDocumentContentAction::class)->execute(
        DocumentTestHelpers::context($node), new UpdateDocumentContentInput($node, DocumentTestHelpers::content('cannot persist')),
    ))->toThrow(QueryException::class);

    $after = DocumentTestHelpers::document($node);
    expect($after->state_version)->toBe($before->state_version)
        ->and($after->collab_epoch)->toBe($before->collab_epoch)
        ->and($after->current_revision_id)->toBe($before->current_revision_id)
        ->and($after->revision_count)->toBe($before->revision_count)
        ->and($after->payload?->content_hash)->toBe($hash)
        ->and(DB::table('v_outbox')->count())->toBe($outbox)
        ->and(DocumentTestHelpers::revisions($node))->toHaveCount(1);
});
