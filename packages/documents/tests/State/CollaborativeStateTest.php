<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\State\CheckpointPublication;
use Vaulter\Documents\State\CollaborationStateBinding;
use Vaulter\Documents\State\CollaborativeState;
use Vaulter\Documents\State\CollaborativeStateTransition;
use Vaulter\Documents\State\LockedDocumentState;
use Vaulter\Documents\State\PendingMarker;
use Vaulter\Documents\Tests\Fixtures\GrantedDocuments;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Node;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;

/*
 * The state contract of the realtime transport (D26, D27): lock() in the T → N → D order, the pending-state marker
 * (generation, through_seq) set by markPending() and cleared only by a covering publishCheckpoint(), the CAS of a
 * publication, and the CHECK that keeps the marker whole. SQLite runs transactions one at a time, so the race of an
 * append against a publication is played here by a deterministic interleaving; PendingMarkerRaceTest races it on
 * PostgreSQL.
 */

/**
 * @template T
 *
 * @param  Closure(CollaborativeState): T $work
 * @return T
 */
function collabStateIn(Closure $work): mixed
{
    return app(VaulterDatabase::class)->transaction(static fn (): mixed => $work(app(CollaborativeState::class)));
}

function collabStateMark(Node $node, int $throughSeq, ?int $generation = null): PendingMarker
{
    return collabStateIn(static function (CollaborativeState $state) use ($node, $throughSeq, $generation): PendingMarker {
        $locked = $state->lock($node->getKey());

        return $state->markPending($locked, new CollaborationStateBinding('yjs', 'yjs-update-v1', $locked->schemaVersion, $generation ?? $locked->generation), $throughSeq);
    });
}

function collabStatePublication(LockedDocumentState $locked, int $coveredThrough, string $text = 'Merged', ?int $generation = null, ?int $stateVersion = null): CheckpointPublication
{
    return new CheckpointPublication(
        binding: new CollaborationStateBinding('yjs', 'yjs-update-v1', 1, $generation ?? $locked->generation),
        expectedStateVersion: $stateVersion ?? $locked->stateVersion,
        coveredThrough: $coveredThrough,
        collabState: 'state:'.$text,
        content: GrantedDocuments::tiptap($text),
    );
}

beforeEach(function (): void {
    $this->node = GrantedDocuments::document(level: null);
});

it('requires an open Vaulter transaction for every method', function (): void {
    $state = app(CollaborativeState::class);
    $locked = collabStateIn(fn (CollaborativeState $state): LockedDocumentState => $state->lock($this->node->getKey()));

    expect(fn () => $state->lock($this->node->getKey()))->toThrow(LogicException::class)
        ->and(fn () => $state->markPending($locked, new CollaborationStateBinding('yjs', 'yjs-update-v1', 1, 0), 1))->toThrow(LogicException::class)
        ->and(fn () => $state->publishCheckpoint($locked, collabStatePublication($locked, 1)))->toThrow(LogicException::class)
        ->and(fn () => $state->pendingDocuments())->toThrow(LogicException::class)
        ->and(GrantedDocuments::aggregate($this->node)->pending_generation)->toBeNull();
});

it('locks the document of a node and reads its state', function (): void {
    $document = GrantedDocuments::aggregate($this->node);

    $locked = collabStateIn(fn (CollaborativeState $state): LockedDocumentState => $state->lock((string) $this->node->getKey()));

    expect($locked->documentId)->toBe(Identity::stringify($document->getKey()))
        ->and($locked->nodeId)->toBe(Identity::stringify($this->node->getKey()))
        ->and($locked->driveId)->toBe(Identity::stringify($this->node->drive_id))
        ->and($locked->type)->toBe('vaulter/rich-text')
        ->and($locked->schemaVersion)->toBe(1)
        ->and($locked->generation)->toBe(0)
        ->and($locked->stateVersion)->toBe($document->state_version)
        ->and($locked->pending)->toBeNull()
        ->and($locked->trashed)->toBeFalse();
});

it('refuses a node that does not exist or is not a document', function (): void {
    $root = $this->node->parent_id;

    expect(fn () => collabStateIn(static fn (CollaborativeState $state) => $state->lock(999_999)))->toThrow(NodeNotFoundException::class)
        ->and(fn () => collabStateIn(static fn (CollaborativeState $state) => $state->lock($root)))->toThrow(NotADocumentException::class);
});

it('reports a trashed document instead of refusing it, so its pending edits can still drain', function (): void {
    collabStateMark($this->node, 2);
    Node::query()->whereKey($this->node->getKey())->update(['trashed_at' => now()]);

    $locked = collabStateIn(fn (CollaborativeState $state): LockedDocumentState => $state->lock($this->node->getKey()));

    expect($locked->trashed)->toBeTrue()
        ->and($locked->pending?->throughSeq)->toBe(2);
});

it('keeps the highest sequence of the current generation in the marker', function (): void {
    $first = collabStateMark($this->node, 3);
    $lower = collabStateMark($this->node, 2);
    $higher = collabStateMark($this->node, 5);
    $document = GrantedDocuments::aggregate($this->node);

    expect([$first->generation, $first->throughSeq])->toBe([0, 3])
        ->and($lower->throughSeq)->toBe(3)
        ->and($higher->throughSeq)->toBe(5)
        ->and($higher->documentId)->toBe(Identity::stringify($document->getKey()))
        ->and($higher->nodeId)->toBe(Identity::stringify($this->node->getKey()))
        ->and([$document->pending_generation, $document->pending_through_seq])->toBe([0, 5])
        // The marker is not content: the validators stay.
        ->and($document->state_version)->toBe(GrantedDocuments::aggregate($this->node)->state_version)
        ->and($document->collab_epoch)->toBe(0);
});

it('sets a marker only for the current generation and a sequence of at least one', function (): void {
    expect(fn () => collabStateMark($this->node, 1, generation: 1))->toThrow(LogicException::class)
        ->and(fn () => collabStateMark($this->node, 0))->toThrow(InvalidArgumentException::class)
        ->and(GrantedDocuments::aggregate($this->node)->pending_generation)->toBeNull();
});

it('publishes a covering checkpoint as the working state and clears the marker', function (): void {
    collabStateMark($this->node, 3);
    $before = GrantedDocuments::aggregate($this->node);

    $outcome = collabStateIn(function (CollaborativeState $state) {
        $locked = $state->lock($this->node->getKey());

        return $state->publishCheckpoint($locked, collabStatePublication($locked, 3));
    });
    $document = GrantedDocuments::aggregate($this->node);

    expect($outcome->published)->toBeTrue()
        ->and($outcome->changed)->toBeTrue()
        ->and($outcome->pending)->toBeNull()
        ->and($outcome->generation)->toBe(0)
        ->and($outcome->stateVersion)->toBe($before->state_version + 1)
        ->and($document->state_version)->toBe($before->state_version + 1)
        ->and($document->collab_epoch)->toBe(0)
        ->and($document->collab_state)->toBe('state:Merged')
        ->and($document->tipTapContent()->toArray())->toBe(GrantedDocuments::tiptap('Merged'))
        ->and($document->pending_generation)->toBeNull()
        ->and($document->pending_through_seq)->toBeNull();
});

it('keeps the marker of an edit appended after the merge read the log', function (): void {
    // The hub reads checkpoint and head H = 3 of generation 0 …
    collabStateMark($this->node, 3);
    $read = collabStateIn(fn (CollaborativeState $state): LockedDocumentState => $state->lock($this->node->getKey()));
    // … an append commits seq 4 while it merges …
    collabStateMark($this->node, 4);
    // … and it publishes what it merged, through 3.
    $outcome = collabStateIn(function (CollaborativeState $state) use ($read) {
        return $state->publishCheckpoint($state->lock($this->node->getKey()), collabStatePublication($read, 3));
    });
    $document = GrantedDocuments::aggregate($this->node);

    expect($outcome->published)->toBeTrue()
        ->and($outcome->pending?->throughSeq)->toBe(4)
        ->and([$document->pending_generation, $document->pending_through_seq])->toBe([0, 4])
        ->and($document->collab_state)->toBe('state:Merged');

    // The append before the read and the publication in the other order end the same: the marker stays at 4.
    $other = GrantedDocuments::document(level: null);
    collabStateMark($other, 3);
    $published = collabStateIn(static function (CollaborativeState $state) use ($other) {
        $locked = $state->lock($other->getKey());

        return $state->publishCheckpoint($locked, collabStatePublication($locked, 3));
    });
    collabStateMark($other, 4);

    expect($published->pending)->toBeNull()
        ->and(GrantedDocuments::aggregate($other)->pending_through_seq)->toBe(4);
});

it('loses the CAS on a moved state version or a closed generation and changes nothing', function (): void {
    collabStateMark($this->node, 3);
    $before = GrantedDocuments::aggregate($this->node);

    [$stale, $closed] = collabStateIn(function (CollaborativeState $state) {
        $locked = $state->lock($this->node->getKey());

        return [
            $state->publishCheckpoint($locked, collabStatePublication($locked, 3, stateVersion: $locked->stateVersion - 1)),
            $state->publishCheckpoint($locked, collabStatePublication($locked, 3, generation: $locked->generation + 1)),
        ];
    });
    $after = GrantedDocuments::aggregate($this->node);

    expect($stale->published)->toBeFalse()
        ->and($stale->changed)->toBeFalse()
        ->and($stale->stateVersion)->toBe($before->state_version)
        ->and($stale->pending?->throughSeq)->toBe(3)
        ->and($closed->published)->toBeFalse()
        ->and($closed->generation)->toBe(0)
        ->and($after->state_version)->toBe($before->state_version)
        ->and($after->content_hash)->toBe($before->content_hash)
        ->and($after->collab_state)->toBeNull()
        ->and([$after->pending_generation, $after->pending_through_seq])->toBe([0, 3]);
});

it('clears a covered marker even when the published state is the one already there', function (): void {
    $published = collabStateIn(function (CollaborativeState $state) {
        $locked = $state->lock($this->node->getKey());

        return $state->publishCheckpoint($locked, collabStatePublication($locked, 0));
    });
    collabStateMark($this->node, 1);

    $again = collabStateIn(function (CollaborativeState $state) {
        $locked = $state->lock($this->node->getKey());

        return $state->publishCheckpoint($locked, collabStatePublication($locked, 1));
    });

    expect($again->published)->toBeTrue()
        ->and($again->changed)->toBeFalse()
        ->and($again->stateVersion)->toBe($published->stateVersion)
        ->and($again->pending)->toBeNull()
        ->and(GrantedDocuments::aggregate($this->node)->pending_generation)->toBeNull();
});

it('lists the pending documents in id order, a page at a time', function (): void {
    $second = GrantedDocuments::document(level: null);
    GrantedDocuments::document(level: null);
    collabStateMark($second, 7);
    collabStateMark($this->node, 2);
    $ids = [Identity::stringify(GrantedDocuments::aggregate($this->node)->getKey()), Identity::stringify(GrantedDocuments::aggregate($second)->getKey())];

    [$all, $first, $rest] = collabStateIn(static fn (CollaborativeState $state): array => [
        $state->pendingDocuments(),
        $state->pendingDocuments(limit: 1),
        $state->pendingDocuments(limit: 1, after: $ids[0]),
    ]);

    expect(array_map(static fn (PendingMarker $marker): string => $marker->documentId, $all))->toBe($ids)
        ->and(array_map(static fn (PendingMarker $marker): int => $marker->throughSeq, $all))->toBe([2, 7])
        ->and(array_map(static fn (PendingMarker $marker): string => $marker->documentId, $first))->toBe([$ids[0]])
        ->and(array_map(static fn (PendingMarker $marker): string => $marker->documentId, $rest))->toBe([$ids[1]]);
});

it('refuses half a marker or a sequence below one in the database', function (array $values): void {
    $table = app(VaulterDatabase::class)->connection()->table((new Document)->getTable());

    expect(fn () => $table->where('node_id', $this->node->getKey())->update($values))->toThrow(QueryException::class);
})->with([
    'generation alone' => [['pending_generation' => 0]],
    'sequence alone' => [['pending_through_seq' => 1]],
    'sequence zero' => [['pending_generation' => 0, 'pending_through_seq' => 0]],
    'negative generation' => [['pending_generation' => -1, 'pending_through_seq' => 1]],
]);

it('validates a publication before it reaches the document', function (): void {
    expect(fn () => new CheckpointPublication(binding: new CollaborationStateBinding('yjs', 'yjs-update-v1', 1, -1), expectedStateVersion: 0, coveredThrough: 0, collabState: 's', content: []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CheckpointPublication(binding: new CollaborationStateBinding('yjs', 'yjs-update-v1', 1, 0), expectedStateVersion: 0, coveredThrough: -1, collabState: 's', content: []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CheckpointPublication(binding: new CollaborationStateBinding('yjs', 'yjs-update-v1', 1, 0), expectedStateVersion: 0, coveredThrough: 0, collabState: '', content: []))->toThrow(InvalidArgumentException::class);
});

it('accepts only the issued object of the current transaction attempt for every write', function (): void {
    collabStateIn(function (CollaborativeState $state): void {
        $locked = $state->lock($this->node->getKey());
        $forged = new LockedDocumentState(...get_object_vars($locked));
        $before = GrantedDocuments::aggregate($this->node)->getAttributes();
        foreach ([$forged, clone $locked] as $token) {
            expect(fn () => $state->markPending($token, $locked->binding, 1))->toThrow(LogicException::class)
                ->and(fn () => $state->publishCheckpoint($token, collabStatePublication($locked, 1)))->toThrow(LogicException::class)
                ->and(fn () => $state->transition($token, new CollaborativeStateTransition(
                    $locked->binding, $locked->stateVersion, $locked->type, $locked->schemaVersion,
                    $locked->binding?->protocol, $locked->binding?->stateFormat,
                    OpaqueContent::fromArray($locked->content), null,
                    new ActorRef('staff', '42'), 'forged-token',
                )))->toThrow(LogicException::class);
        }
        expect(GrantedDocuments::aggregate($this->node)->getAttributes())->toBe($before);
        expect($state->markPending($locked, $locked->binding, 1)->throughSeq)->toBe(1);
    });
});

it('expires tokens after commit or rollback and never revives them at the same transaction depth', function (bool $rollback): void {
    $old = null;
    try {
        collabStateIn(function (CollaborativeState $state) use (&$old, $rollback): void {
            $old = $state->lock($this->node->getKey());
            if ($rollback) {
                throw new RuntimeException('abandoned preparation');
            }
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('abandoned preparation');
    }
    collabStateIn(function (CollaborativeState $state) use ($old): void {
        expect(fn () => $state->markPending($old, $old->binding, 1))->toThrow(LogicException::class)
            ->and(fn () => $state->publishCheckpoint($old, collabStatePublication($old, 1)))->toThrow(LogicException::class);
        $fresh = $state->lock($this->node->getKey());
        expect($state->markPending($fresh, $fresh->binding, 2)->throughSeq)->toBe(2);
    });
})->with(['committed' => false, 'rolled back' => true]);

it('expires a rolled-back savepoint token while the outer transaction remains live', function (): void {
    collabStateIn(function (CollaborativeState $state): void {
        $old = null;
        try {
            collabStateIn(function (CollaborativeState $state) use (&$old): void {
                $old = $state->lock($this->node->getKey());
                throw new RuntimeException('rollback savepoint');
            });
        } catch (RuntimeException) {
        }
        expect(fn () => $state->markPending($old, $old->binding, 1))->toThrow(LogicException::class);
        $fresh = $state->lock($this->node->getKey());
        expect($state->markPending($fresh, $fresh->binding, 1)->throughSeq)->toBe(1);
    });
});

it('does not accept an abandoned retry token in the succeeding attempt', function (): void {
    $old = null;
    $attempt = 0;
    app(VaulterDatabase::class)->transaction(function () use (&$old, &$attempt): void {
        $state = app(CollaborativeState::class);
        if (++$attempt === 1) {
            $old = $state->lock($this->node->getKey());
            throw new PDOException('SQLSTATE[40001]: Serialization failure: deadlock detected', 40001);
        }
        expect(fn () => $state->markPending($old, $old->binding, 1))->toThrow(LogicException::class);
        $fresh = $state->lock($this->node->getKey());
        expect($state->markPending($fresh, $fresh->binding, 1)->throughSeq)->toBe(1);
    }, attempts: 2);
    expect($attempt)->toBe(2);
});

it('refuses a token on another live connection before querying the aggregate', function (): void {
    collabStateIn(function (CollaborativeState $state): void {
        $locked = $state->lock($this->node->getKey());
        $database = app(VaulterDatabase::class);
        $original = config('vaulter.database.connection');
        config()->set('database.connections.token_other', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $other = app('db')->connection('token_other');
        $other->beginTransaction();
        try {
            config()->set('vaulter.database.connection', 'token_other');
            expect($database->connection())->toBe($other);
            expect(fn () => $state->markPending($locked, $locked->binding, 1))->toThrow(LogicException::class);
        } finally {
            config()->set('vaulter.database.connection', $original);
            $other->rollBack();
        }
    });
});
