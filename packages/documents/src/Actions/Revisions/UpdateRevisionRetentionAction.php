<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Revisions;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Revisions\UpdateRevisionRetentionInput;
use Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Repositories\Documents\DocumentReadRepository;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionReadRepository;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionStoreRepository;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Operations\OperationContext;
use Vaulter\Support\DriveLifecycle;
use Vaulter\Support\TreeGuard;

/**
 * Pin a checkpoint out of the prune window (keep_forever) or release the pin, the one
 * column a stored checkpoint may change. A released checkpoint re-enters the prune
 * window on the document's next checkpoint. The checkpoint is resolved inside the
 * node's own aggregate, so a key of another document never changes here.
 *
 * Runs under T → N → D like every document write, so it serializes with a prune that
 * could otherwise delete the checkpoint between the lookup and the pin.
 */
final readonly class UpdateRevisionRetentionAction
{
    public function __construct(
        private DocumentReadRepository $documents,
        private DocumentRevisionReadRepository $read,
        private DocumentRevisionStoreRepository $store,
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private DriveLifecycle $lifecycle,
    ) {}

    public function execute(OperationContext $context, UpdateRevisionRetentionInput $data): DocumentRevision
    {
        $node = $data->node;
        $versionId = $data->revision instanceof DocumentRevision ? $data->revision->getKey() : $data->revision;

        return $this->database->transaction(function () use ($context, $node, $versionId, $data): DocumentRevision {
            $driveId = $context->driveId();
            $nodeId = $node->getRawOriginal($node->getKeyName());
            if (!$node->exists || (!is_int($nodeId) && !is_string($nodeId)) || $nodeId === '') {
                throw NodeNotFoundException::forId('unpersisted');
            }
            $this->database->lockTreeShared($driveId);
            $this->lifecycle->assertWritable($context);
            $current = $this->tree->locked($this->tree->lock([$nodeId]), $nodeId);
            if (!$this->tree->sameStorage($current, $driveId)) {
                throw NodeNotFoundException::forId($nodeId);
            }
            // A trash that committed before this lock leaves nothing to write to (PLAN2.F05).
            $this->tree->assertLive($current);
            if (!$current->kind->isDocument()) {
                throw NotADocumentException::forNode($nodeId);
            }
            $node = $this->tree->sync($node, $current);
            $this->documents->lockForNode($node) ?? throw NotADocumentException::forNode($nodeId);

            $revision = is_int($versionId) || (is_string($versionId) && $versionId !== '') ? $this->read->forNode($node, $versionId) : null;
            if (!$revision instanceof DocumentRevision) {
                throw DocumentRevisionNotFoundException::forNode($nodeId, $versionId);
            }

            return $this->store->setRetention($revision, keepForever: $data->keepForever);
        });
    }
}
