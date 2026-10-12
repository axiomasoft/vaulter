<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Revisions;

use Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionReadRepository;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionStoreRepository;
use Vaulter\Models\Drive\Node;

/**
 * Release a version's `keep_forever` pin (§2.5 / M4a, F-P1.12-03) — the missing
 * inverse of the pin set by {@see CreateNamedRevisionAction} (`keepForever: true`).
 * Before this action existed, no code path ever cleared the flag, so a pinned
 * version was excluded from {@see DocumentRevisionStoreRepository::prune()}
 * FOREVER — the released version becomes ordinary again and re-enters the prune
 * window on the node's next snapshot/restore. The version-to-release is resolved
 * through the repository bounded to the node, so a version id from another node
 * never releases here (mirrors {@see RestoreDocumentRevisionAction}).
 */
final readonly class UpdateRevisionRetentionAction
{
    public function __construct(
        private DocumentRevisionReadRepository $read,
        private DocumentRevisionStoreRepository $store,
    ) {}

    public function execute(Node $node, int|string $versionId): DocumentRevision
    {
        if (!$node->kind->isDocument()) {
            throw NotADocumentException::forNode($node->getKey());
        }

        $version = $this->read->forNode($node, $versionId);

        if (!$version instanceof DocumentRevision) {
            throw DocumentRevisionNotFoundException::forNode($node->getKey(), $versionId);
        }

        return $this->store->update($version, ['keep_forever' => false]);
    }
}
