<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Documents;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentData;
use Vaulter\Documents\Enums\Documents\DocumentType;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Exceptions\NotADocumentNodeException;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionReadRepository;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionStoreRepository;
use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\Config;

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

        // F-P1.11-05: the ADR-0004 size limit was defined but never enforced at save
        // time — check it before the transaction, not just at the UI layer. Content
        // is handler-agnostic (DocumentContent); the limit only applies to the
        // TipTap shape, not an opaque non-rich-text snapshot (e.g. a spreadsheet).
        if ($data->content instanceof TipTapContent) {
            $data->content->ensureWithin(Config::maxDocumentSizeKb());
        }

        return DB::transaction(function () use ($node, $data): NodeDocumentVersion {
            $head = $this->read->head($node);

            if ($head !== null && $this->shouldOverwrite($head)) {
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
                // A new autosave row on an existing document CONTINUES its format —
                // never a schema default (P0.4/L3, D4/D12). Falls back to the
                // rich-text default only for the (practically unreachable) case of a
                // document node with no prior version at all.
                'format' => $head->format ?? DocumentType::RichText->value,
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
    private function shouldOverwrite(NodeDocumentVersion $head): bool
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
     * The content payload shared by the overwrite + append paths.
     *
     * @return array<string, mixed>
     */
    private function payload(UpdateDocumentContentData $data): array
    {
        return [
            'content' => $data->content->toArray(),
            'content_hash' => $data->content->contentHash(),
            'yjs_state' => $data->yjsState,
            'yjs_state_size' => $data->yjsState === null ? null : mb_strlen($data->yjsState, '8bit'),
        ];
    }
}
