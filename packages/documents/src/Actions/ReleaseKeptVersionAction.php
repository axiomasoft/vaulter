<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions;

use Vaulter\Documents\Exceptions\NodeDocumentVersionNotFoundException;
use Vaulter\Documents\Exceptions\NotADocumentNodeException;
use Vaulter\Documents\Models\NodeDocumentVersion;
use Vaulter\Documents\Repositories\NodeDocumentVersionReadRepository;
use Vaulter\Documents\Repositories\NodeDocumentVersionStoreRepository;
use Vaulter\Models\Drive\Node;

/**
 * Release a version's `keep_forever` pin (§2.5 / M4a, F-P1.12-03) — the missing
 * inverse of the pin set by {@see CreateNamedVersionAction} (`keepForever: true`).
 * Before this action existed, no code path ever cleared the flag, so a pinned
 * version was excluded from {@see NodeDocumentVersionStoreRepository::prune()}
 * FOREVER — the released version becomes ordinary again and re-enters the prune
 * window on the node's next snapshot/restore. The version-to-release is resolved
 * through the repository bounded to the node, so a version id from another node
 * never releases here (mirrors {@see RestoreDocumentVersionAction}).
 */
final readonly class ReleaseKeptVersionAction
{
    public function __construct(
        private NodeDocumentVersionReadRepository $read,
        private NodeDocumentVersionStoreRepository $store,
    ) {}

    public function execute(Node $node, int|string $versionId): NodeDocumentVersion
    {
        if (!$node->kind->isDocument()) {
            throw NotADocumentNodeException::forNode($node->getKey());
        }

        $version = $this->read->forNode($node, $versionId);

        if (!$version instanceof NodeDocumentVersion) {
            throw NodeDocumentVersionNotFoundException::forNode($node->getKey(), $versionId);
        }

        return $this->store->update($version, ['keep_forever' => false]);
    }
}
