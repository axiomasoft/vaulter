<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Collaboration;

use Illuminate\Support\Carbon;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Collaboration\PersistCollaborationStateInput;
use Vaulter\Documents\Enums\Documents\DocumentType;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionReadRepository;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionStoreRepository;
use Vaulter\Documents\Support\DocumentContentHasher;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\Config;
use Vaulter\Support\LoadTier;

/**
 * Persist a Yjs snapshot (master plan §7 / ADR-0001 / M4b) — the write the Node
 * Hocuspocus hub's debounced onStoreDocument drives. The Yjs binary CRDT state is the
 * SOURCE OF TRUTH: it is stored verbatim as yjs_state (bytea) with its byte length in
 * yjs_state_size; the optional TipTap JSON is the DERIVED projection (we never
 * reconstruct Yjs from JSON — when the hub omits the projection the prior projection
 * is left intact and only the Yjs state advances).
 *
 * The persistence is append-only with debounce, the SAME policy as the autosave path
 * (the hub already debounces, so a rapid snapshot UPDATES the head autosave version in
 * place rather than flooding the history); outside the window a fresh is_autosave
 * version is appended and the head repoints. One outer transaction; prune runs inside
 * it (respecting keep_forever and the current head); a single after-commit
 * DocumentContentUpdated event integrates with the activity/outbox subscribers.
 */
final readonly class PersistCollaborationStateAction
{
    public function __construct(
        private DocumentRevisionReadRepository $read,
        private DocumentRevisionStoreRepository $store,
        private NodeStoreRepository $nodes,
        private MetricsRecorder $metrics,
        private VaulterDatabase $database,
    ) {}

    public function execute(PersistCollaborationStateInput $data): DocumentRevision
    {
        $node = $data->node;

        if (!$node->kind->isDocument()) {
            throw NotADocumentException::forNode($node->getKey());
        }

        // F-P1.11-05: the ADR-0004 size limit was defined but never enforced at save
        // time. The Yjs binary state is the source of truth and is unbounded here by
        // design (§7); only the optional TipTap JSON projection is size-checked.
        $data->content?->ensureWithin(Config::maxDocumentSizeKb());

        return $this->metrics->timing(
            name: 'vaulter_collab_snapshot_duration_seconds',
            operation: fn (): DocumentRevision => $this->database->transaction(function () use ($node, $data): DocumentRevision {
                $head = $this->read->head($node);

                if ($head !== null && $this->shouldOverwrite($head)) {
                    $version = $this->store->update($head, $this->payload($data, $head));

                    event(new DocumentContentUpdated(
                        node: $node,
                        version: $version,
                        created: false,
                        actorId: $data->actorId,
                    ));

                    return $version;
                }

                $version = $this->store->create([
                    'node_id' => $node->getKey(),
                    'version_no' => $this->store->nextVersionNo($node),
                    // A new snapshot row on an existing document CONTINUES its format —
                    // never a schema default (P0.4/L3, D4/D12). Falls back to the
                    // rich-text default only for the (practically unreachable) case of a
                    // document node with no prior version at all.
                    'format' => $head->format ?? DocumentType::RichText->value,
                    'is_autosave' => true,
                    'status' => 'committed',
                    'created_at' => now(),
                    ...$this->payload($data, null),
                ]);

                $this->nodes->update($node, ['current_version_id' => $version->getKey()]);

                $this->store->prune(
                    node: $node,
                    max: Config::maxDocumentVersions(),
                    headId: $version->getKey(),
                );

                event(new DocumentContentUpdated(
                    node: $node,
                    version: $version,
                    created: true,
                    actorId: $data->actorId,
                ));

                return $version;
            }),
            labels: ['tier' => LoadTier::label()],
        );
    }

    /**
     * Whether to overwrite the head in place: it must be an AUTOSAVE version created
     * within the debounce window (the same rule as the autosave path). A committed/
     * named head, or an autosave older than the window, forces a fresh row so the
     * history keeps the older Yjs state.
     */
    private function shouldOverwrite(DocumentRevision $head): bool
    {
        if (!$head->is_autosave) {
            return false;
        }

        $debounce = Config::autosaveDebounceSeconds();

        if ($debounce === 0) {
            return true;
        }

        $createdAt = $head->created_at;

        if (!$createdAt instanceof Carbon) {
            return true;
        }

        return $createdAt->greaterThanOrEqualTo(Carbon::now()->subSeconds($debounce));
    }

    /**
     * The snapshot payload. yjs_state is the source of truth — always written, with
     * its byte length cached in yjs_state_size. The TipTap projection is written ONLY
     * when the hub supplied it; otherwise the prior projection is preserved (when
     * appending a new row with no projection, content stays null — the Yjs state is
     * authoritative and the projection backfills on the next snapshot that carries it).
     *
     * @return array<string, mixed>
     */
    private function payload(PersistCollaborationStateInput $data, ?DocumentRevision $head): array
    {
        $payload = [
            'yjs_state' => $data->yjsState,
            'yjs_state_size' => mb_strlen($data->yjsState, '8bit'),
        ];

        if ($data->content !== null) {
            $payload['content'] = $data->content->toArray();
            $payload['content_hash'] = DocumentContentHasher::hash($data->content);

            return $payload;
        }

        // No projection in this snapshot: keep the head's existing projection when
        // overwriting in place; leave it null when appending (Yjs remains the truth).
        if ($head !== null) {
            $payload['content'] = $head->content;
            $payload['content_hash'] = $head->content_hash;
        }

        return $payload;
    }
}
