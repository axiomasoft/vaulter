<?php

declare(strict_types=1);

namespace Vaulter\Documents\Repositories\Revisions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Packman\Attributes\Internal;
use Vaulter\Documents\Configuration\DocumentsOptions;
use Vaulter\Documents\Content\ManagedDocumentPayloads;
use Vaulter\Documents\Contracts\RevisionPruneProtection;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Models\Revisions\DocumentRevisionPayload;
use Vaulter\Documents\Models\Revisions\DocumentRevisionSyncState;
use Vaulter\Kernel;
use Vaulter\Support\Identity;

/**
 * Write-side row work for the immutable checkpoints in v_document_revisions:
 * insert a checkpoint, change its retention pin, prune the history and purge checkpoints
 * (releasing their managed payload references). There is no
 * general update — a checkpoint's payload never changes after INSERT. Opens NO
 * transactions and dispatches NO events.
 */
#[Internal]
final class DocumentRevisionStoreRepository
{
    public function __construct(
        private readonly DocumentsOptions $documentsConfig,
        private readonly ManagedDocumentPayloads $references,
        private readonly Kernel $kernel,
    ) {}

    /**
     * @param array<string, mixed>      $attributes        server-built by DocumentStateWriter
     * @param array<string, mixed>      $payloadAttributes
     * @param array<string, mixed>|null $syncAttributes
     */
    public function create(array $attributes, array $payloadAttributes, ?array $syncAttributes): DocumentRevision
    {
        $model = $this->documentsConfig->model('document_revision');
        $revision = new $model;
        $revision->forceFill($attributes);
        $revision->save();

        $payload = (new DocumentRevisionPayload)->forceFill($payloadAttributes);
        $this->references->change($revision->type, 'revision', Identity::stringify($revision->getKey()), null, $payload->content);
        $revision->payload()->save($payload);
        $sync = null;
        if ($syncAttributes !== null) {
            $sync = (new DocumentRevisionSyncState)->forceFill($syncAttributes);
            $revision->syncState()->save($sync);
        }

        return $revision->setRelation('payload', $payload)->setRelation('syncState', $sync);
    }

    /** The one mutation a stored checkpoint allows. */
    public function setRetention(DocumentRevision $revision, bool $keepForever): DocumentRevision
    {
        $revision->forceFill([DocumentRevision::RETENTION_COLUMN => $keepForever])->save();

        return $revision;
    }

    /**
     * Prune a document's history down to the $max most recent checkpoints,
     * excluding the head, keep_forever checkpoints and protected references. Returns the number of
     * pruned rows. $max <= 0 means unlimited. revision_count is not lowered, so a
     * number is never handed out twice.
     */
    public function prune(Document $document, int $max, int|string|null $headId): int
    {
        if ($max <= 0) {
            return 0;
        }

        $model = $this->documentsConfig->model('document_revision');
        $documentId = Identity::stringify($document->getKey());
        $protected = [];
        foreach ($this->kernel->extensions()->resolve(RevisionPruneProtection::class) as $protection) {
            $protected += array_fill_keys($protection->protectedRevisionIds($documentId), true);
        }

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

        /** @var Collection<int, DocumentRevision> $excess */
        $excess = $prunable
            ->reject(static fn (DocumentRevision $revision): bool => isset($protected[Identity::stringify($revision->getKey())]))
            ->slice($max)
            ->values();

        if ($excess->isEmpty()) {
            return 0;
        }

        $this->purge($excess->modelKeys());

        return $excess->count();
    }

    /** @param array<int|string> $ids */
    public function purge(array $ids): void
    {
        $model = $this->documentsConfig->model('document_revision');
        $query = $model::query()->whereKey($ids);
        foreach ((clone $query)->with('payload')->get() as $revision) {
            $this->references->change($revision->type, 'revision', Identity::stringify($revision->getKey()), $revision->payload?->content, null);
        }
        $query->delete();
    }
}
