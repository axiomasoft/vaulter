<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Documents;

use Vaulter\Attributes\Internal;
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
        $this->database->lockAggregateShared('drives', $context->driveId());
        $this->lifecycle->assertWritable($context);
        $current = $this->tree->locked($this->tree->lock([$nodeId]), $nodeId);
        if (!$this->tree->sameStorage($current, $context->driveId())) {
            throw NodeNotFoundException::forId($nodeId);
        }
        $this->tree->assertLive($current);
        if (!$current->kind->isDocument()) {
            throw NotADocumentException::forNode($nodeId);
        }
        $node = $this->tree->sync($node, $current);
        $document = $this->documents->lockForNode($node) ?? throw NotADocumentException::forNode($nodeId);
        $document->setRelation('node', $node);

        return [$node, $document];
    }
}
