<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Documents;

use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Repositories\Documents\DocumentReadRepository;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Support\DriveLifecycle;
use Vaulter\Drive\Support\TreeGuard;

/**
 * The T → N → document lock shared by document writes. The caller rejects an
 * unpersisted node first, because retention does not treat an empty drive id
 * the same way as a content write.
 */
#[Internal]
final readonly class DocumentWriteLock
{
    public function __construct(
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private DriveLifecycle $lifecycle,
        private DocumentReadRepository $documents,
    ) {}

    /**
     * @return array{Node, Document}
     */
    public function acquire(DriveContext $context, Node $node, int|string $nodeId): array
    {
        $node = $this->tree->sync($node, $this->lockNode($context, $nodeId));

        return [$node, $this->lockDocument($node)];
    }

    /** T → N. Callers that authorize under N do so before calling lockDocument(). */
    public function lockNode(DriveContext $context, int|string $nodeId): Node
    {
        $this->database->lockAggregateShared('drives', $context->driveId());
        $this->lifecycle->assertWritable($context);
        $current = $this->tree->lock([$nodeId])->first() ?? throw NodeNotFoundException::forId($nodeId);
        if (!$this->tree->sameStorage($current, $context->driveId())) {
            throw NodeNotFoundException::forId($nodeId);
        }
        $this->tree->assertLive($current);
        if (!$current->kind->isDocument()) {
            throw NotADocumentException::forNode($nodeId);
        }

        return $current;
    }

    /** D. The caller must already hold T and N. */
    public function lockDocument(Node $node): Document
    {
        $document = $this->documents->lockForNode($node) ?? throw NotADocumentException::forNode($node->id);

        return $document->setRelation('node', $node);
    }
}
