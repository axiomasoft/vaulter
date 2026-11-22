<?php

declare(strict_types=1);

namespace Vaulter\Documents\Content;

use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Documents\Configuration\DocumentsOptions;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Repositories\Documents\DocumentReadRepository;
use Vaulter\Documents\Repositories\Documents\DocumentStoreRepository;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionStoreRepository;
use Vaulter\Documents\Contracts\ManagedDocumentCodec;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Documents\State\PendingStateGuard;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Contracts\NodeContentLifecycle;
use Vaulter\Drive\Data\CopyResult;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Operations\DriveContext;

/**
 * Content of a kind=document node for copy and purge. Both run inside the Vaulter
 * transaction of CopyNodeAction / PurgeNodeAction, which already hold the tree and
 * node locks, so this class only takes the document row lock the action's order
 * leaves for it.
 */
#[Internal]
final readonly class DocumentContentLifecycle implements NodeContentLifecycle
{
    /** Revisions deleted per statement, so a long history never becomes one huge delete. */
    private const int PURGE_BATCH = 500;

    #[Internal]
    public function __construct(
        private DocumentStoreRepository $documents,
        private DocumentReadRepository $reads,
        private DocumentStateWriter $writer,
        private DocumentsOptions $documentsConfig,
        private DriveOptions $drives,
        private PendingStateGuard $pending,
        private DocumentRevisionStoreRepository $revisionStore,
        private ManagedDocumentPayloads $references,
        private DocumentTypeRegistry $types,
        private ContentDecoder $decoder,
    ) {}

    public function kind(): NodeKind
    {
        return NodeKind::Document;
    }

    /**
     * The copy is a new aggregate holding the source's working state as its only
     * revision. History, the collaboration epoch and comments stay with the source: a
     * copy is a new document, not a fork of the old one's timeline.
     */
    public function copy(DriveContext $context, NodeSnapshot $source, NodeSnapshot $copy): CopyResult
    {
        $original = Document::query()->where('node_id', $source->id)->sharedLock()->first();
        if (!$original instanceof Document) {
            throw new LogicException('[Vaulter] A document node has no document aggregate.');
        }

        $this->reads->loadLockedState($original);
        $this->pending->assertSettled($original);
        $actorId = $context->actor->ref()->id;
        $document = $this->documents->create([
            'node_id' => $copy->id,
            'type' => $original->type,
            'schema_version' => $original->schema_version,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
        // The copy's node row was written by the drive in this transaction; the document keeps it for its events.
        $nodeModel = $this->drives->model('node');
        $document->setRelation('node', $nodeModel::query()->whereKey($copy->id)->firstOrFail());

        $content = $this->decoder->storedPayload($original->type, $original->payload?->getAttributes()['content'] ?? null, $original->payload?->content);
        $codec = $this->types->find($original->type)?->codec();
        $copyContent = is_array($content) ? ($codec instanceof ManagedDocumentCodec
            ? $codec->decodeStored($content, $original->schema_version)
            : OpaqueContent::fromArray($content)) : null;

        // The payload is carried verbatim as opaque JSON: the copy must keep content of a
        // type that is not registered (any more) instead of failing or decaying it.
        $this->writer->write($document, new DocumentStateChange(
            cause: StateWriteCause::Copy,
            content: $copyContent,
            schemaVersion: $original->schema_version,
            collabState: $original->syncState?->collab_state,
            actorId: $actorId,
            forceCheckpoint: true,
            documentType: $original->type,
            protocol: $original->syncState?->collab_protocol,
            stateFormat: $original->syncState?->collab_state_format,
        ));

        // Inline content is not part of the drive quota.
        return new CopyResult(addedBytes: 0);
    }

    public function purge(DriveContext $context, NodeSnapshot $node): void
    {
        $document = Document::query()->where('node_id', $node->id)->lockForUpdate()->first();
        if (!$document instanceof Document) {
            return;
        }
        // Saved collaboration edits not in the working state yet go only through a drain, never with the purge.
        // PendingPurgeDrain drained before this transaction; nothing waits here, a marker still set refuses the purge.
        $document->setRelation('syncState', $document->syncState()->sharedLock()->first(PendingStateGuard::SYNC_COLUMNS));
        $this->pending->assertSettled($document);

        // MySQL checks the RESTRICT head foreign key while the history goes away, so the
        // head pointer is cleared first.
        $this->documents->update($document, ['current_revision_id' => null]);

        $revisions = $this->documentsConfig->model('document_revision');
        do {
            $ids = $revisions::query()
                ->where('document_id', $document->getKey())
                ->orderBy('id')
                ->limit(self::PURGE_BATCH)
                ->pluck('id')
                ->all();
            if ($ids !== []) {
                $this->revisionStore->purge($ids);
            }
        } while (count($ids) === self::PURGE_BATCH);

        $this->references->change($document->type, 'working', (string) $document->getKey(), $document->payload?->content, null);
        Document::query()->whereKey($document->getKey())->delete();
    }
}
