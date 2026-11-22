<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Documents;

use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Documents\DocumentStateResult;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Operations\DriveContext;

/**
 * Autosave replaces the working state under T → N → D locks, keeping the generation.
 * The shared writer creates a checkpoint only when due and records effective updates.
 */
#[Internal]
final readonly class UpdateDocumentContentAction
{
    public function __construct(
        private VaulterDatabase $database,
        private DocumentWriteLock $writeLock,
        private WriteLockedDocumentContent $writer,
    ) {}

    public function execute(DriveContext $context, UpdateDocumentContentInput $data): DocumentStateResult
    {
        $node = $data->node;

        return $this->database->transaction(function () use ($context, $node, $data): DocumentStateResult {
            // The node must live in the operation's drive; the locked row is checked below.
            $driveId = $context->driveId();
            $nodeId = $node->getRawOriginal($node->getKeyName());
            if (!$node->exists || (!is_int($nodeId) && !is_string($nodeId)) || $nodeId === '' || $driveId === '') {
                throw NodeNotFoundException::forId('unpersisted');
            }
            [$node, $document] = $this->writeLock->acquire($context, $node, $nodeId);

            return $this->writer->execute($context, $node, $document, $data->content, $data->expectedStateVersion, $data->matchesTag);
        });
    }
}
