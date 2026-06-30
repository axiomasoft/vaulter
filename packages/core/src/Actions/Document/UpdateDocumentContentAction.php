<?php

declare(strict_types=1);

namespace Vaulter\Actions\Document;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Vaulter\Data\UpdateDocumentContentData;
use Vaulter\Events\DocumentContentUpdated;
use Vaulter\Exceptions\NotADocumentNodeException;
use Vaulter\Models\Node;
use Vaulter\Models\NodeDocumentVersion;
use Vaulter\Repositories\NodeDocumentVersionReadRepository;
use Vaulter\Repositories\NodeDocumentVersionStoreRepository;
use Vaulter\Repositories\NodeStoreRepository;
use Vaulter\Support\Config;
use Vaulter\Support\DocumentContentHasher;

/**
 * Write a document node's content — the AUTOSAVE path (§2.5 / M4a). Within the
 * autosave-debounce window of the head AUTOSAVE version the existing row is UPDATED
 * in place (no new history entry), so a typing session does not flood the history;
 * outside the window (or when the head is a committed/named version) a fresh
 * is_autosave version is appended and the head repoints to it.
 *
 * One outer transaction; prune runs inside it (respecting keep_forever and the
 * current head); a single after-commit event carries whether a row was created.
 */
final readonly class UpdateDocumentContentAction
{
    public function __construct(
        private NodeDocumentVersionReadRepository $read,
        private NodeDocumentVersionStoreRepository $store,
        private NodeStoreRepository $nodes,
    ) {}

    public function execute(UpdateDocumentContentData $data): NodeDocumentVersion
    {
        $node = $data->node;

        if (!$node->kind->isDocument()) {
            throw NotADocumentNodeException::forNode($node->getKey());
        }

        return DB::transaction(function () use ($node, $data): NodeDocumentVersion {
            $head = $this->read->head($node);

            if ($this->shouldOverwrite($head)) {
                /** @var NodeDocumentVersion $head */
                $version = $this->store->update($head, $this->payload($data));

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
                'is_autosave' => true,
                'status' => 'committed',
                'created_at' => now(),
                ...$this->payload($data),
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
        });
    }

    /**
     * Whether to overwrite the head in place: it must be an AUTOSAVE version created
     * within the debounce window. A committed/named head, or an autosave older than
     * the window, forces a fresh row so the history keeps the older state.
     */
    private function shouldOverwrite(?NodeDocumentVersion $head): bool
    {
        if ($head === null || !$head->is_autosave) {
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
     * The content payload shared by the overwrite + append paths.
     *
     * @return array<string, mixed>
     */
    private function payload(UpdateDocumentContentData $data): array
    {
        return [
            'content' => $data->content->toArray(),
            'content_hash' => DocumentContentHasher::hash($data->content),
            'yjs_state' => $data->yjsState,
            'yjs_state_size' => $data->yjsState === null ? null : mb_strlen($data->yjsState, '8bit'),
        ];
    }
}
