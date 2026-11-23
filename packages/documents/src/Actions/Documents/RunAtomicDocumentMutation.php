<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Documents;

use Closure;
use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Contracts\AtomicDocumentMutation;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;
use Vaulter\Support\Identity;

#[Internal]
final readonly class RunAtomicDocumentMutation implements AtomicDocumentMutation
{
    public function __construct(
        private VaulterDatabase $database,
        private OperationRunner $runner,
        private DocumentWriteLock $lock,
        private OperationGuard $guard,
        private ContentDecoder $decoder,
        private WriteLockedDocumentContent $writer,
    ) {}

    public function run(DriveContext $context, int|string $nodeId, Closure $operation): mixed
    {
        if ($this->database->connection()->transactionLevel() !== 0 || $this->runner->inOperation()) {
            throw new LogicException('[Vaulter] Atomic document mutation requires an outermost transaction.');
        }

        return $this->runner->write(
            new OperationSpec('documents.documents.atomic_mutation', new ModuleId('documents')),
            $context->operation,
            fn (): mixed => $this->withinWrite($context, $nodeId, $operation),
        );
    }

    /**
     * @template TResult
     *
     * @param  Closure(LockedDocumentMutation): TResult $operation
     * @return TResult
     */
    public function withinWrite(DriveContext $context, int|string $nodeId, Closure $operation): mixed
    {
        if ($this->database->connection()->transactionLevel() === 0 || !$this->runner->inOperation()) {
            throw new LogicException('[Vaulter] Related document mutation requires an active write operation.');
        }

        try {
            if ($nodeId === '' || !Identity::fitsKey($context->drive->nodes()->getRelated(), $nodeId)) {
                throw NodeNotFoundException::forId($nodeId);
            }
            $node = $this->lock->lockNode($context, $nodeId);
            // Resolve current grants after any transaction holding N has finished.
            $this->guard->authorize($context, Ability::Edit, $node, OperationKind::Mutation);
            $document = $this->lock->lockDocument($node);
        } catch (NodeNotFoundException|NotADocumentException|PermissionDeniedException|ProfileRestrictionException|DriveArchivedException) {
            throw NodeNotFoundException::forId('document');
        }

        $locked = new LockedMutation($this->database->connection(), $context, $node, $document, $this->decoder, $this->writer);
        try {
            return $operation($locked);
        } finally {
            $locked->close();
        }
    }
}
