<?php

declare(strict_types=1);

namespace Vaulter\Documents\Content;

use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Repositories\Documents\DocumentStoreRepository;
use Vaulter\Drive\Contracts\NodeContentLifecycle;
use Vaulter\Drive\Data\CopyResult;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Models\Node;
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
        private DocumentStateWriter $writer,
        private DocumentsConfig $documentsConfig,
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
    public function copy(DriveContext $context, Node $source, Node $copy): CopyResult
    {
        $original = Document::query()->where('node_id', $source->getKey())->sharedLock()->first();
        if (!$original instanceof Document) {
            throw new LogicException('[Vaulter] A document node has no document aggregate.');
        }

        $actorId = $context->actor->ref()->id;
        $document = $this->documents->create([
            'node_id' => $copy->getKey(),
            'type' => $original->type,
            'schema_version' => $original->schema_version,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
        $document->setRelation('node', $copy);

        // The payload is carried verbatim as opaque JSON: the copy must keep content of a
        // type that is not registered (any more) instead of failing or decaying it.
        $this->writer->write($document, new DocumentStateChange(
            cause: StateWriteCause::Copy,
            content: is_array($original->content) ? OpaqueContent::fromArray($original->content) : null,
            yjsState: $original->yjs_state,
            schemaVersion: $original->schema_version,
            actorId: $actorId,
            forceCheckpoint: true,
        ));

        // Inline content is not part of the drive quota.
        return new CopyResult(addedBytes: 0);
    }

    public function purge(DriveContext $context, Node $node): void
    {
        $document = Document::query()->where('node_id', $node->getKey())->lockForUpdate()->first();
        if (!$document instanceof Document) {
            return;
        }

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
                $revisions::query()->whereKey($ids)->delete();
            }
        } while (count($ids) === self::PURGE_BATCH);

        Document::query()->whereKey($document->getKey())->delete();
    }
}
