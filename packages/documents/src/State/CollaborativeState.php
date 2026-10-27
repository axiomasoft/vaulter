<?php

declare(strict_types=1);

namespace Vaulter\Documents\State;

use LogicException;
use Vaulter\Attributes\Api;
use Vaulter\Documents\Exceptions\DocumentContentDegradedException;
use Vaulter\Documents\Exceptions\DocumentTypeUnavailableException;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;

/**
 * The one way a realtime transport changes the state documents own (D26, D27): the generation (`collab_epoch`), the
 * state version, the binary checkpoint with its JSON projection, and the pending-state marker. The transport keeps
 * its log in tables of its own and never writes `v_documents`.
 *
 * Every method runs inside an open Vaulter transaction (VaulterDatabase::transaction()) and throws LogicException
 * outside one. lock() is the only place the transport takes the tree, node and document locks (T → N → D); its own
 * rows are locked after them, never before, and nothing waits on the network (a hub merge, a drain) while they are
 * held. markPending() and publishCheckpoint() take the state lock() returned in the same transaction.
 */
#[Api]
interface CollaborativeState
{
    /**
     * Lock the document of node `$nodeId` in the lock order and read its state.
     *
     * @throws LogicException        outside a transaction
     * @throws NodeNotFoundException no such node
     * @throws NotADocumentException the node is not a document
     */
    public function lock(int|string $nodeId): LockedDocumentState;

    /**
     * Service addressing of an aggregate id (a head enumerated by a service): the aggregate-to-node mapping is read
     * without a row lock, then {@see self::lock()} takes the locks in their normal order and the fresh locked
     * document id must equal `$documentId`. Null — nothing written — when there is no mapping, the node is gone or
     * no document any more, or another aggregate answers the node now. Never changes {@see self::lock()}.
     *
     * @throws LogicException outside a transaction
     */
    public function lockByDocumentId(int|string $documentId): ?LockedDocumentState;

    /**
     * Record that edits of `$generation` through `$throughSeq` are committed but not checkpointed: the marker becomes
     * (generation, max(current, `$throughSeq`)). The generation must be the document's current one — the caller fences
     * it on the locked state first.
     *
     * @throws LogicException outside a transaction, for another generation than the current one, or over a marker of another generation
     */
    public function markPending(LockedDocumentState $state, CollaborationStateBinding $binding, int $throughSeq): PendingMarker;

    /**
     * Publish a checkpoint as the working state, as one CAS: only while the document is still at the publication's
     * generation and state version. The marker is cleared only when the checkpoint covers it (same generation and
     * `coveredThrough` ≥ its sequence); edits committed after the merge read the log keep it set.
     *
     * @throws LogicException                   outside a transaction
     * @throws DocumentTypeUnavailableException the document's type is not installed
     * @throws DocumentContentDegradedException the working state reads only partly under its type
     * @throws InvalidDocumentContentException  the type rejects the projection
     */
    public function transition(LockedDocumentState $state, CollaborativeStateTransition $transition): CheckpointOutcome;

    public function publishCheckpoint(LockedDocumentState $state, CheckpointPublication $publication): CheckpointOutcome;

    /**
     * The documents that carry a marker, in id order, `$limit` at most, after the document id `$after`.
     *
     * @throws LogicException outside a transaction
     *
     * @return list<PendingMarker>
     */
    public function pendingDocuments(int $limit = 100, int|string|null $after = null, ?string $protocol = null): array;
}
