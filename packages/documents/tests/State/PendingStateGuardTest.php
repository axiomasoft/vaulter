<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Exceptions\DocumentStatePendingException;
use Vaulter\Documents\Exceptions\StaleDocumentStateException;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\State\CheckpointPublication;
use Vaulter\Documents\State\CollaborationStateBinding;
use Vaulter\Documents\State\CollaborativeState;
use Vaulter\Documents\State\PendingMarker;
use Vaulter\Documents\State\PendingStateDrainer;
use Vaulter\Documents\State\PendingStateGuard;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Fixtures\GrantedDocuments;
use Vaulter\Documents\Tests\Fixtures\State\FakePendingStateDrainer;
use Vaulter\Documents\Tests\Fixtures\State\PendingDrainerModule;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Actions\Drives\PurgeDriveStepAction;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Kernel;

/*
 * Drain-or-409: a document with committed collaboration edits its working state does not hold yet is not
 * read, exported, replaced, restored or purged as it stands. The PendingStateDrainer is asked to bring the log in —
 * outside any transaction — and the path then checks the marker again under its own locks; a marker that stays, or
 * no drainer at all, answers 409 document_state_pending and leaves the generation, the content and the history alone.
 */

function guardMark(int|string $nodeId, int $throughSeq): PendingMarker
{
    return app(VaulterDatabase::class)->transaction(static function () use ($nodeId, $throughSeq): PendingMarker {
        $state = app(CollaborativeState::class);
        $locked = $state->lock($nodeId);

        return $state->markPending($locked, $locked->binding, $throughSeq);
    });
}

/** Activates the fake drainer; with $publish it publishes a checkpoint covering the marker, else it misses its deadline. */
function guardDrainer(bool $publish): void
{
    config()->set(PendingDrainerModule::CONFIG_KEY, FakePendingStateDrainer::class);
    FakePendingStateDrainer::$behaviour = !$publish ? null : static fn (PendingMarker $marker) => guardSettle($marker->nodeId);
}

/** Publishes a checkpoint covering the document's marker, as the transport's compaction does. */
function guardSettle(string $nodeId): void
{
    app(VaulterDatabase::class)->transaction(static function () use ($nodeId): void {
        $state = app(CollaborativeState::class);
        $locked = $state->lock($nodeId);
        $marker = $locked->pending ?? throw new LogicException('no marker to settle');
        $state->publishCheckpoint($locked, new CheckpointPublication(
            binding: new CollaborationStateBinding('yjs', 'yjs-update-v1', 1, $marker->generation),
            expectedStateVersion: $locked->stateVersion,
            coveredThrough: $marker->throughSeq,
            collabState: 'drained',
            content: GrantedDocuments::tiptap('Drained'),
        ));
    });
}

function guardText(Document $document): string
{
    return (string) data_get($document->tipTapContent()->toArray(), 'content.0.content.0.text');
}

beforeEach(function (): void {
    FakePendingStateDrainer::reset();
    $this->handle = DocumentTestHelpers::drive();
    $this->documents = Documents::in($this->handle);
    $this->id = (string) $this->documents->create('Spec', content: DocumentTestHelpers::content('Saved')->toArray())->id;
    $this->first = $this->documents->revisions($this->id)->items[0]->id;
    $this->aggregate = fn (): Document => Document::query()->where('node_id', $this->id)->firstOrFail();
});

afterEach(function (): void {
    FakePendingStateDrainer::reset();
});

it('answers 409 without resolving a drainer when none is active', function (): void {
    guardMark($this->id, 3);
    $before = ($this->aggregate)();

    expect(app(Kernel::class)->extensions()->handlerKeys(PendingStateDrainer::class) !== [])->toBeFalse()
        ->and(fn () => $this->documents->content($this->id))->toThrow(DocumentStatePendingException::class)
        ->and(fn () => $this->documents->export($this->id, 'html'))->toThrow(DocumentStatePendingException::class)
        ->and(fn () => $this->documents->update($this->id, DocumentTestHelpers::content('Mine')->toArray()))->toThrow(DocumentStatePendingException::class)
        ->and(fn () => $this->documents->restore($this->id, $this->first))->toThrow(DocumentStatePendingException::class);

    $after = ($this->aggregate)();
    expect(FakePendingStateDrainer::$calls)->toBe([])
        ->and($after->collab_epoch)->toBe($before->collab_epoch)
        ->and($after->state_version)->toBe($before->state_version)
        ->and($after->revision_count)->toBe($before->revision_count)
        ->and([$after->pending_generation, $after->pending_through_seq])->toBe([0, 3]);
});

it('still serves a checkpoint of a pending document: only the working state is stale', function (): void {
    guardMark($this->id, 3);

    expect($this->documents->content($this->id, $this->first)->stateVersion)->toBeInt()
        ->and($this->documents->export($this->id, 'html', $this->first)->bytes)->toContain('Saved');
});

it('drains outside any transaction and serves the drained state', function (): void {
    guardMark($this->id, 3);
    guardDrainer(publish: true);

    $snapshot = $this->documents->content($this->id);

    expect(FakePendingStateDrainer::$calls)->toHaveCount(1)
        ->and(FakePendingStateDrainer::$calls[0]['transactionLevel'])->toBe(0)
        ->and(FakePendingStateDrainer::$calls[0]['marker']->throughSeq)->toBe(3)
        ->and(FakePendingStateDrainer::$calls[0]['marker']->nodeId)->toBe($this->id)
        ->and(data_get($snapshot->content->toArray(), 'content.0.content.0.text'))->toBe('Drained')
        ->and(($this->aggregate)()->pending_generation)->toBeNull();
});

it('drains before an export of the working state', function (): void {
    guardMark($this->id, 3);
    guardDrainer(publish: true);

    expect($this->documents->export($this->id, 'html')->bytes)->toContain('Drained')
        ->and(FakePendingStateDrainer::$calls)->toHaveCount(1);
});

it('drains before a replace and writes on top of the drained edits', function (): void {
    guardMark($this->id, 3);
    guardDrainer(publish: true);

    $this->documents->update($this->id, DocumentTestHelpers::content('Mine')->toArray());
    $document = ($this->aggregate)();

    expect(FakePendingStateDrainer::$calls)->toHaveCount(1)
        ->and(FakePendingStateDrainer::$calls[0]['transactionLevel'])->toBe(0)
        ->and(guardText($document))->toBe('Mine')
        ->and($document->pending_generation)->toBeNull();
});

it('refuses a replace whose tag predates the drained edits instead of overwriting them', function (): void {
    $seen = $this->documents->content($this->id)->stateVersion;
    guardMark($this->id, 3);
    guardDrainer(publish: true);

    expect(fn () => $this->documents->update($this->id, DocumentTestHelpers::content('Mine')->toArray(), $seen))->toThrow(StaleDocumentStateException::class)
        ->and(guardText(($this->aggregate)()))->toBe('Drained');
});

it('drains before a restore, then closes the generation', function (): void {
    guardMark($this->id, 3);
    guardDrainer(publish: true);

    $this->documents->restore($this->id, $this->first);
    $document = ($this->aggregate)();

    expect(FakePendingStateDrainer::$calls)->toHaveCount(1)
        ->and($document->collab_epoch)->toBe(1)
        ->and(guardText($document))->toBe('Saved')
        ->and($document->pending_generation)->toBeNull();
});

it('refuses with 409 and keeps the generation when the drain misses its deadline', function (): void {
    guardMark($this->id, 3);
    guardDrainer(publish: false);
    $before = ($this->aggregate)();

    expect(fn () => $this->documents->restore($this->id, $this->first))->toThrow(DocumentStatePendingException::class)
        ->and(fn () => $this->documents->update($this->id, DocumentTestHelpers::content('Mine')->toArray()))->toThrow(DocumentStatePendingException::class)
        ->and(fn () => $this->documents->content($this->id))->toThrow(DocumentStatePendingException::class)
        ->and(fn () => $this->documents->export($this->id, 'html'))->toThrow(DocumentStatePendingException::class);

    $after = ($this->aggregate)();
    // One drain per refused call, each outside a transaction; nothing was written.
    expect(FakePendingStateDrainer::$calls)->toHaveCount(4)
        ->and(array_column(FakePendingStateDrainer::$calls, 'transactionLevel'))->toBe([0, 0, 0, 0])
        ->and($after->collab_epoch)->toBe($before->collab_epoch)
        ->and($after->state_version)->toBe($before->state_version)
        ->and($after->revision_count)->toBe($before->revision_count)
        ->and($after->content_hash)->toBe($before->content_hash)
        ->and([$after->pending_generation, $after->pending_through_seq])->toBe([0, 3]);
});

it('never waits on the drainer inside a transaction', function (): void {
    guardMark($this->id, 3);
    guardDrainer(publish: true);

    expect(fn () => app(VaulterDatabase::class)->transaction(fn () => $this->documents->content($this->id)))->toThrow(DocumentStatePendingException::class)
        ->and(FakePendingStateDrainer::$calls)->toBe([]);
});

it('drains a trashed pending document before the purge transaction, then purges it', function (): void {
    guardMark($this->id, 3);
    guardDrainer(publish: true);
    // A live pending document of the same drive is not the purge's business: it is not drained.
    $live = (string) $this->documents->create('Live', content: DocumentTestHelpers::content('Live')->toArray())->id;
    guardMark($live, 1);
    $this->handle->nodes()->trash($this->id);

    $this->handle->trash()->purge($this->id);

    expect(Document::query()->where('node_id', $this->id)->exists())->toBeFalse()
        ->and(FakePendingStateDrainer::$calls)->toHaveCount(1)
        ->and(FakePendingStateDrainer::$calls[0]['marker']->nodeId)->toBe($this->id)
        ->and(FakePendingStateDrainer::$calls[0]['transactionLevel'])->toBe(0)
        ->and(Document::query()->where('node_id', $live)->value('pending_through_seq'))->toBe(1);
});

it('refuses to purge a pending document the drain did not settle, or without a drainer, and keeps it', function (bool $drainer): void {
    guardMark($this->id, 3);
    if ($drainer) {
        guardDrainer(publish: false);
    }
    $this->handle->nodes()->trash($this->id);
    $before = ($this->aggregate)();

    expect(fn () => $this->handle->trash()->purge($this->id))->toThrow(DocumentStatePendingException::class);

    $after = ($this->aggregate)();
    expect(FakePendingStateDrainer::$calls)->toHaveCount($drainer ? 1 : 0)
        ->and($after->collab_epoch)->toBe($before->collab_epoch)
        ->and($after->revision_count)->toBe($before->revision_count)
        ->and([$after->pending_generation, $after->pending_through_seq])->toBe([0, 3]);
})->with(['drain misses its deadline' => [true], 'no drainer' => [false]]);

it('does not drain before a purge that runs inside a transaction', function (): void {
    guardMark($this->id, 3);
    guardDrainer(publish: true);
    $this->handle->nodes()->trash($this->id);

    expect(fn () => app(VaulterDatabase::class)->transaction(fn () => $this->handle->trash()->purge($this->id)))->toThrow(DocumentStatePendingException::class)
        ->and(FakePendingStateDrainer::$calls)->toBe([])
        ->and(Document::query()->where('node_id', $this->id)->exists())->toBeTrue();
});

it('drains the purge target even behind more trashed pending documents than one page that do not settle', function (): void {
    // 101 trashed documents of the drive with lower ids whose drain never settles.
    $stuck = [];
    foreach (range(1, 101) as $n) {
        $stuck[] = (string) $this->documents->create('Stuck '.$n, content: DocumentTestHelpers::content('Stuck')->toArray())->id;
    }
    Document::query()->whereIn('node_id', $stuck)->update(['pending_generation' => 0, 'pending_through_seq' => 1, 'pending_protocol' => 'yjs', 'pending_state_format' => 'yjs-update-v1', 'pending_schema_version' => 1]);
    Node::query()->whereIn('id', $stuck)->update(['trashed_at' => now()]);
    $target = (string) $this->documents->create('Target', content: DocumentTestHelpers::content('Target')->toArray())->id;
    guardMark($target, 3);
    $this->handle->nodes()->trash($target);
    guardDrainer(publish: true);
    $publish = FakePendingStateDrainer::$behaviour;
    FakePendingStateDrainer::$behaviour = static function (PendingMarker $marker) use ($publish, $target): void {
        if ($marker->nodeId === $target && $publish !== null) {
            $publish($marker);
        }
    };

    $this->handle->trash()->purge($target);

    $drained = array_map(static fn (array $call): string => $call['marker']->nodeId, FakePendingStateDrainer::$calls);
    expect(Document::query()->where('node_id', $target)->exists())->toBeFalse()
        ->and($drained)->toHaveCount(102)
        ->and(array_unique($drained))->toHaveCount(102)
        ->and($drained)->toContain($target);
});

// Retention (vaulter:trash:purge) and the drive purge step purge each root as the root operation drive.trash.purge,
// so the same drain runs before their transaction: a marker the drain settles is purged at once, one that stays
// refuses only that root and the run goes on.
it('drains a pending document before a retention purge, then purges it', function (): void {
    guardMark($this->id, 3);
    guardDrainer(publish: true);
    $this->handle->nodes()->trash($this->id);
    $this->travel(1)->minutes();

    $this->artisan('vaulter:trash:purge', ['--days' => 0])->assertExitCode(0);

    expect(Document::query()->where('node_id', $this->id)->exists())->toBeFalse()
        ->and(FakePendingStateDrainer::$calls)->toHaveCount(1)
        ->and(FakePendingStateDrainer::$calls[0]['transactionLevel'])->toBe(0)
        ->and(FakePendingStateDrainer::$calls[0]['marker']->nodeId)->toBe($this->id);
});

it('refuses a retention purge of a pending document the drain did not settle, keeps it, and purges the other roots', function (): void {
    guardMark($this->id, 3);
    guardDrainer(publish: false);
    $other = (string) $this->documents->create('Other', content: DocumentTestHelpers::content('Other')->toArray())->id;
    $this->handle->nodes()->trash($this->id);
    $this->handle->nodes()->trash($other);
    $this->travel(1)->minutes();

    $this->artisan('vaulter:trash:purge', ['--days' => 0])->expectsOutputToContain('Purged 1 trashed subtree. Skipped 1.')->assertExitCode(1);

    expect(Document::query()->where('node_id', $this->id)->exists())->toBeTrue()
        ->and(Document::query()->where('node_id', $other)->exists())->toBeFalse()
        // One drain per root operation: the stuck document is asked once for each of the two roots.
        ->and(FakePendingStateDrainer::$calls)->toHaveCount(2);

    guardSettle($this->id);
    $this->artisan('vaulter:trash:purge', ['--days' => 0])->assertExitCode(0);
    expect(Document::query()->where('node_id', $this->id)->exists())->toBeFalse();
});

it('drains a pending document of a drive being deleted before the drive purge step, then finishes the drive', function (): void {
    guardMark($this->id, 3);
    guardDrainer(publish: true);
    $drive = $this->handle->model();
    $drive->forceFill(['status' => DriveStatus::Deleting])->save();

    expect(app(PurgeDriveStepAction::class)->execute($drive->getKey()))->toBeTrue()
        ->and(Document::query()->where('node_id', $this->id)->exists())->toBeFalse()
        ->and(FakePendingStateDrainer::$calls)->toHaveCount(1)
        ->and(FakePendingStateDrainer::$calls[0]['transactionLevel'])->toBe(0);
});

it('keeps a drive being deleted while the drain does not settle one of its documents and finishes once the marker is cleared', function (): void {
    guardMark($this->id, 3);
    guardDrainer(publish: false);
    $drive = $this->handle->model();
    $drive->forceFill(['status' => DriveStatus::Deleting])->save();

    expect(fn () => app(PurgeDriveStepAction::class)->execute($drive->getKey()))->toThrow(DocumentStatePendingException::class)
        ->and(Document::query()->where('node_id', $this->id)->exists())->toBeTrue()
        ->and(FakePendingStateDrainer::$calls)->toHaveCount(1);

    guardSettle($this->id);
    expect(app(PurgeDriveStepAction::class)->execute($drive->getKey()))->toBeTrue()
        ->and(Document::query()->where('node_id', $this->id)->exists())->toBeFalse();
});

it('answers the REST content, export, replace and restore with the problem document_state_pending', function (): void {
    GrantedDocuments::configure();
    $node = GrantedDocuments::document(level: RoleRegistry::WRITER, actorId: 1);
    $id = (string) $node->getKey();
    $this->actingAs(AccessTestHelpers::user(1));
    $etag = Docs::etag($id);
    $revision = $this->getJson(Docs::url('documents/'.$id.'/revisions'))->assertOk()->json('data.0.id');
    guardMark($id, 2);

    $this->getJson(Docs::url('documents/'.$id.'/content'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'document_state_pending')
        ->assertJsonPath('retryable', 'with_backoff');
    $this->getJson(Docs::url('documents/'.$id.'/export/html'))->assertStatus(409)->assertJsonPath('code', 'document_state_pending');
    Docs::put($id, Docs::doc('Mine'), $etag)->assertStatus(409)->assertJsonPath('code', 'document_state_pending');
    $this->postJson(Docs::url('documents/'.$id.'/revisions/'.$revision.'/restore'), [], ['If-Match' => $etag])
        ->assertStatus(409)
        ->assertJsonPath('code', 'document_state_pending');

    expect(GrantedDocuments::aggregate($node)->collab_epoch)->toBe(0);
});

it('routes each mixed pending page by stored protocol and never drops unknown markers', function (): void {
    guardDrainer(true);
    $unknown = ($this->aggregate)();
    $unknown->forceFill(['pending_protocol' => 'acme-ot', 'pending_state_format' => 'acme-json-v1', 'pending_schema_version' => 1,
        'pending_generation' => 0, 'pending_through_seq' => 1])->save();
    $known = $this->documents->create('Known', content: DocumentTestHelpers::content('Known')->toArray());
    guardMark($known->id, 3);
    app(PendingStateGuard::class)->drainTrashed($this->handle->model()->getKey(), wholeDrive: true);
    expect($unknown->fresh()->pending_through_seq)->toBe(1)
        ->and(array_map(static fn ($call) => $call['marker']->nodeId, FakePendingStateDrainer::$calls))->toContain((string) $known->id);
});
