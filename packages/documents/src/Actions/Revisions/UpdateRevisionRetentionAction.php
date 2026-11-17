<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Revisions;

use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\DocumentWriteLock;
use Vaulter\Documents\Data\Revisions\UpdateRevisionRetentionInput;
use Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionReadRepository;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionStoreRepository;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Operations\DriveContext;

/**
 * Pin a checkpoint out of the prune window (keep_forever) or release the pin, the one
 * column a stored checkpoint may change. A released checkpoint re-enters the prune
 * window on the document's next checkpoint. The checkpoint is resolved inside the
 * node's own aggregate, so a key of another document never changes here.
 *
 * Runs under T → N → D like every document write, so it serializes with a prune that
 * could otherwise delete the checkpoint between the lookup and the pin.
 */
#[Internal]
final readonly class UpdateRevisionRetentionAction
{
    public function __construct(
        private DocumentRevisionReadRepository $read,
        private DocumentRevisionStoreRepository $store,
        private VaulterDatabase $database,
        private DocumentWriteLock $writeLock,
    ) {}

    public function execute(DriveContext $context, UpdateRevisionRetentionInput $data): DocumentRevision
    {
        $node = $data->node;
        $versionId = $data->revision instanceof DocumentRevision ? $data->revision->getKey() : $data->revision;

        return $this->database->transaction(function () use ($context, $node, $versionId, $data): DocumentRevision {
            $nodeId = $node->getRawOriginal($node->getKeyName());
            if (!$node->exists || (!is_int($nodeId) && !is_string($nodeId)) || $nodeId === '') {
                throw NodeNotFoundException::forId('unpersisted');
            }
            [$node] = $this->writeLock->acquire($context, $node, $nodeId);

            $revision = is_int($versionId) || (is_string($versionId) && $versionId !== '') ? $this->read->forNode($node, $versionId) : null;
            if (!$revision instanceof DocumentRevision) {
                throw DocumentRevisionNotFoundException::forNode($nodeId, $versionId);
            }

            return $this->store->setRetention($revision, keepForever: $data->keepForever);
        });
    }
}
