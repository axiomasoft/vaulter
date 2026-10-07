<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Revisions;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Data\Revisions\CreateNamedRevisionInput;
use Vaulter\Documents\Enums\Documents\DocumentType;
use Vaulter\Documents\Events\Revisions\DocumentRevisionCreated;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionReadRepository;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionStoreRepository;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Operations\NodeSequence;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\TreeGuard;

/**
 * Snapshot a document node's current head content as a NAMED (non-autosave) version
 * with a label (§2.5 / M4a). The named version becomes the new head; keepForever
 * pins it out of the prune window. One outer transaction; prune runs inside it
 * (named keep_forever rows never count against the window); a single after-commit
 * event fires.
 *
 * A named snapshot CONVERTS the working state into a durable checkpoint — distinct
 * from autosave churn (a named version is never overwritten by the debounce path).
 */
final readonly class CreateNamedRevisionAction
{
    public function __construct(
        private DocumentRevisionReadRepository $read,
        private DocumentRevisionStoreRepository $store,
        private NodeStoreRepository $nodes,
        private VaulterDatabase $database,
        private DocumentsConfig $documentsConfig,
        private TreeGuard $tree,
        private NodeSequence $sequence,
    ) {}

    public function execute(CreateNamedRevisionInput $data): DocumentRevision
    {
        $node = $data->node;

        return $this->database->transaction(function () use ($node, $data): DocumentRevision {
            $driveId = $node->getRawOriginal('drive_id');
            $nodeId = $node->getRawOriginal($node->getKeyName());
            if (!$node->exists || (!is_int($nodeId) && !is_string($nodeId)) || $nodeId === ''
                || (!is_int($driveId) && !is_string($driveId)) || $driveId === '') {
                throw NodeNotFoundException::forId('unpersisted');
            }
            $this->database->lockTreeShared($driveId);
            $current = $this->tree->locked($this->tree->lock([$nodeId]), $nodeId);
            if (!$this->tree->sameStorage($current, $driveId)) {
                throw NodeNotFoundException::forId($nodeId);
            }
            if (!$current->kind->isDocument()) {
                throw NotADocumentException::forNode($nodeId);
            }
            $node = $this->tree->sync($node, $current);
            $this->sequence->assertExpected(locked: $node, expected: $data->expectedSequence);
            $head = $this->read->head($node) ?? $this->read->latest($node);

            $version = $this->store->create([
                'node_id' => $node->getKey(),
                'version_no' => $this->store->nextVersionNo($node),
                'content' => $head instanceof DocumentRevision ? $head->content : null,
                'yjs_state' => $head?->yjs_state,
                'yjs_state_size' => $head?->yjs_state_size,
                'format' => $head instanceof DocumentRevision ? $head->format : DocumentType::RichText->value,
                'content_hash' => $head?->content_hash,
                'snapshot_label' => $data->label,
                'is_autosave' => false,
                'keep_forever' => $data->keepForever,
                'status' => 'committed',
                'created_by' => $data->actorId,
                'created_at' => now(),
            ]);

            $this->nodes->update($node, ['current_version_id' => $version->getKey()]);

            $this->store->prune(
                node: $node,
                max: $this->documentsConfig->revisionLimit(),
                headId: $version->getKey(),
            );
            $this->sequence->bump(locked: $node);

            event(new DocumentRevisionCreated(
                node: $node,
                version: $version,
                restored: false,
                actorId: $data->actorId,
            ));

            return $version;
        });
    }
}
