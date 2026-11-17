<?php

declare(strict_types=1);

namespace Vaulter\Documents\State;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Exceptions\CollaborationProtocolContractException;
use Vaulter\Documents\Exceptions\DocumentStatePendingException;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Kernel;
use Vaulter\Support\Identity;

/**
 * Drain-or-409 for the paths that read or replace the working state: REST content read and replace, restore,
 * export and purge. The path checks the marker on the row it reads — under its locks when it writes — with
 * assertSettled(); settled() runs the whole path, and on a pending refusal asks the PendingStateDrainer to bring the
 * log in, outside any transaction, then runs the path once more, so the second check is made under fresh locks.
 *
 * A purge runs inside the transaction drive's PurgeNodeAction opens, so it cannot be repeated from here: before that
 * transaction PendingPurgeDrain calls drainTrashed() for the operation's drive, and DocumentContentLifecycle::purge()
 * then checks the marker under the purge's locks.
 *
 * Inside a transaction (a write composed into another) nothing waits: the refusal stands. Without an active drainer
 * the refusal stands too, and nothing of the transport is resolved or queried.
 */
#[Internal]
final readonly class PendingStateGuard
{
    /** The trashed pending documents read per query while a purge drains them all. */
    private const int PURGE_DRAIN_PAGE = 100;

    public function __construct(
        private Kernel $kernel,
        private VaulterDatabase $database,
    ) {}

    /** The marker of a document row, null when its working state covers every committed edit. */
    public static function markerOf(Document $document): ?PendingMarker
    {
        if ($document->pending_generation === null || $document->pending_through_seq === null) {
            return null;
        }

        return new PendingMarker(
            documentId: Identity::stringify($document->getKey()),
            nodeId: Identity::stringify($document->node_id),
            binding: new CollaborationStateBinding($document->pending_protocol ?? throw new LogicException('Incomplete pending binding.'), $document->pending_state_format ?? throw new LogicException('Incomplete pending binding.'), $document->pending_schema_version ?? 0, $document->pending_generation),
            throughSeq: $document->pending_through_seq,
        );
    }

    /**
     * Drain the trashed documents of drive `$driveId` that carry a marker — the documents a purge in that drive may
     * remove — outside any transaction; the purge checks each marker again under its own locks. Does nothing inside a
     * transaction or without an active drainer. With `$wholeDrive` every marked document of the drive is drained, live
     * ones too: the drive purge step removes a drive being deleted, whose nodes are not in the trash.
     */
    public function drainTrashed(int|string $driveId, bool $wholeDrive = false): void
    {
        if ($this->database->connection()->transactionLevel() > 0) {
            return;
        }

        // Keyset pages over every marked trashed document: each is drained once, so documents a drain cannot settle
        // never starve the ones after them.
        $after = null;
        do {
            $query = Document::query()
                ->whereNotNull('pending_generation')
                ->whereHas('node', static fn (Builder $node): Builder => $wholeDrive
                    ? $node->where('drive_id', $driveId)
                    : $node->where('drive_id', $driveId)->whereNotNull('trashed_at'))
                ->orderBy('id')
                ->limit(self::PURGE_DRAIN_PAGE);
            if ($after !== null) {
                $query->where('id', '>', $after);
            }
            $documents = $query->get(['id', 'node_id', 'pending_generation', 'pending_through_seq', 'pending_protocol', 'pending_state_format', 'pending_schema_version']);
            foreach ($documents as $document) {
                $after = $document->getKey();
                $marker = self::markerOf($document);
                if ($marker !== null) {
                    $this->drain($marker);
                }
            }
        } while ($documents->count() === self::PURGE_DRAIN_PAGE);
    }

    private function drain(PendingMarker $marker): void
    {
        $extensions = $this->kernel->extensions();
        $protocol = $marker->binding->protocol;
        if (!in_array($protocol, $extensions->handlerKeys(PendingStateDrainer::class), true)) {
            return;
        }
        $drainer = $extensions->handler(PendingStateDrainer::class, $protocol);
        if ($drainer->protocol() !== $protocol) {
            throw new CollaborationProtocolContractException;
        }
        $drainer->drain($marker);
    }

    /** @throws DocumentStatePendingException when the row carries a marker */
    public function assertSettled(Document $document): void
    {
        $marker = self::markerOf($document);
        if ($marker !== null) {
            throw new DocumentStatePendingException($marker);
        }
    }

    /**
     * Whatever $attempt throws passes through; a DocumentStatePendingException only once the marker is still set after
     * the drain, or nothing can drain it.
     *
     * @template T
     *
     * @param  Closure(): T $attempt
     * @return T
     */
    public function settled(Closure $attempt): mixed
    {
        try {
            return $attempt();
        } catch (DocumentStatePendingException $pending) {
            if ($this->database->connection()->transactionLevel() > 0) {
                throw $pending;
            }
            $this->drain($pending->marker);
        }

        return $attempt();
    }
}
