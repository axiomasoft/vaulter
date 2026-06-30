<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Documents;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Vaulter\Models\Documents\NodeDocumentVersion;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;

/**
 * Write-side row work for the append-only v_node_document_versions (§2.5 / M4a):
 * create a version, update the head autosave in place (the debounce path), and the
 * prune that keeps the history bounded. Opens NO transactions and dispatches NO
 * events — the document-node Actions wrap every call in DB::transaction and emit the
 * domain event.
 *
 * version_no is monotonic per node (the unique (node_id, version_no) index is the
 * backbone); nextVersionNo reads the current max under the caller's transaction so
 * two concurrent commits serialise on the index.
 */
final class NodeDocumentVersionStoreRepository
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): NodeDocumentVersion
    {
        $model = Config::nodeDocumentVersionModel();
        $version = new $model;
        $version->fill($attributes);
        $version->save();

        return $version->refresh();
    }

    /**
     * Overwrite an existing version's content/yjs in place — the autosave-debounce
     * path (§2.5): a rapid autosave UPDATES the head autosave row rather than
     * appending a new one, so a typing session does not flood the history.
     *
     * @param array<string, mixed> $attributes
     */
    public function update(NodeDocumentVersion $version, array $attributes): NodeDocumentVersion
    {
        $version->forceFill($attributes)->save();

        return $version;
    }

    /**
     * The next monotonic version_no for a node (§2.5) — current max + 1, or 1 for the
     * first version. Read inside the caller's transaction so two concurrent commits
     * serialise on the unique (node_id, version_no) index.
     */
    public function nextVersionNo(Node $node): int
    {
        $model = Config::nodeDocumentVersionModel();

        $max = $model::query()
            ->where('node_id', $node->getKey())
            ->max('version_no');

        return is_numeric($max) ? ((int) $max) + 1 : 1;
    }

    /**
     * Prune a node's document history down to the $max most recent versions (§2.5),
     * NEVER deleting a keep_forever version nor the current head. Returns the number
     * of pruned rows. $max <= 0 means unlimited (no pruning).
     */
    public function prune(Node $node, int $max, mixed $headId): int
    {
        if ($max <= 0) {
            return 0;
        }

        $model = Config::nodeDocumentVersionModel();

        // The prunable rows: NOT keep_forever, NOT the current head, newest first.
        /** @var Collection<int, NodeDocumentVersion> $prunable */
        $prunable = $model::query()
            ->where('node_id', $node->getKey())
            ->where('keep_forever', false)
            ->when(
                $headId !== null,
                static fn (Builder $query): Builder => $query->whereKeyNot($headId),
            )
            ->orderByDesc('version_no')
            ->get();

        // Keep the newest $max; everything past the window is pruned. keep_forever
        // rows and the head were already excluded, so they never count against the
        // window and are never deleted.
        /** @var Collection<int, NodeDocumentVersion> $excess */
        $excess = $prunable->slice($max)->values();

        if ($excess->isEmpty()) {
            return 0;
        }

        $model::query()->whereKey($excess->modelKeys())->delete();

        return $excess->count();
    }
}
