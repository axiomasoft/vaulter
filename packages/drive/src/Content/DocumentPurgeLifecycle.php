<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Contracts\NodeContentLifecycle;
use Vaulter\Drive\Contracts\PurgeOnlyContentLifecycle;
use Vaulter\Drive\Data\CopyResult;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Exceptions\Nodes\UnsupportedNodeKindException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;

/**
 * The purge a document gets when the documents package is not installed; that package
 * registers the full lifecycle, which replaces this one.
 */
#[Internal]
final readonly class DocumentPurgeLifecycle implements NodeContentLifecycle, PurgeOnlyContentLifecycle
{
    public function __construct(
        private VaulterDatabase $database,
        private DatabaseConfig $databaseConfig,
    ) {}

    public function kind(): NodeKind
    {
        return NodeKind::Document;
    }

    public function copy(DriveContext $context, Node $source, Node $copy): CopyResult
    {
        throw UnsupportedNodeKindException::forKind($this->kind());
    }

    public function purge(DriveContext $context, Node $node): void
    {
        // NodeStoreRepository::purgeSubtree physically deletes the aggregate and its
        // checkpoints by FK cascade. MySQL checks the RESTRICT head FK while that
        // cascade deletes the checkpoints, so the head pointer goes first.
        $this->database->connection()->table($this->databaseConfig->table('documents'))
            ->where('node_id', $node->getKey())
            ->update(['current_revision_id' => null]);
    }
}
