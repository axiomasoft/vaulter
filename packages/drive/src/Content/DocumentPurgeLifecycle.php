<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Contracts\Drive\NodeContentLifecycle;
use Vaulter\Data\Drive\CopyResult;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\UnsupportedNodeKindException;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;

final readonly class DocumentPurgeLifecycle implements NodeContentLifecycle
{
    public function __construct(
        private VaulterDatabase $database,
        private DatabaseConfig $databaseConfig,
    ) {}

    public function kind(): NodeKind
    {
        return NodeKind::Document;
    }

    public function copy(OperationContext $context, Node $source, Node $copy): CopyResult
    {
        throw UnsupportedNodeKindException::forKind($this->kind());
    }

    public function purge(OperationContext $context, Node $node): void
    {
        // NodeStoreRepository::purgeSubtree physically deletes the aggregate and its
        // checkpoints by FK cascade. MySQL checks the RESTRICT head FK while that
        // cascade deletes the checkpoints, so the head pointer goes first.
        $this->database->connection()->table($this->databaseConfig->table('documents'))
            ->where('node_id', $node->getKey())
            ->update(['current_revision_id' => null]);
    }
}
