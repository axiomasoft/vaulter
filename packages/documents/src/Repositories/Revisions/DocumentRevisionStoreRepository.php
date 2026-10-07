<?php

declare(strict_types=1);

namespace Vaulter\Documents\Repositories\Revisions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;

/**
 * Write-side row work for the immutable checkpoints in v_document_revisions:
 * insert a checkpoint, change its retention pin, and prune the history. There is no
 * general update — a checkpoint's payload never changes after INSERT. Opens NO
 * transactions and dispatches NO events.
 */
final class DocumentRevisionStoreRepository
{
    public function __construct(
        private readonly DocumentsConfig $documentsConfig,
    ) {}

    /**
     * @param array<string, mixed> $attributes server-built by DocumentStateWriter
     */
    public function create(array $attributes): DocumentRevision
    {
        $model = $this->documentsConfig->model('document_revision');
        $revision = new $model;
        $revision->forceFill($attributes);
        $revision->save();

        return $revision->refresh();
    }

    /** The one mutation a stored checkpoint allows. */
    public function setRetention(DocumentRevision $revision, bool $keepForever): DocumentRevision
    {
        $revision->forceFill([DocumentRevision::RETENTION_COLUMN => $keepForever])->save();

        return $revision;
    }

    /**
     * Prune a document's history down to the $max most recent checkpoints (§2.5),
     * NEVER deleting a keep_forever checkpoint nor the head. Returns the number of
     * pruned rows. $max <= 0 means unlimited. revision_count is not lowered, so a
     * number is never handed out twice.
     */
    public function prune(Document $document, int $max, int|string|null $headId): int
    {
        if ($max <= 0) {
            return 0;
        }

        $model = $this->documentsConfig->model('document_revision');

        /** @var Collection<int, DocumentRevision> $prunable */
        $prunable = $model::query()
            ->where('document_id', $document->getKey())
            ->where('keep_forever', false)
            ->when(
                $headId !== null,
                static fn (Builder $query): Builder => $query->whereKeyNot($headId),
            )
            ->orderByDesc('number')
            ->get();

        // keep_forever rows and the head were excluded above, so they never count
        // against the window and are never deleted.
        /** @var Collection<int, DocumentRevision> $excess */
        $excess = $prunable->slice($max)->values();

        if ($excess->isEmpty()) {
            return 0;
        }

        $model::query()->whereKey($excess->modelKeys())->delete();

        return $excess->count();
    }
}
