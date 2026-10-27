<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Actions\Nodes\CreateFolderAction;
use Vaulter\Drive\Contracts\FolderOperations as Contract;
use Vaulter\Drive\Data\CreateFolderOptions;
use Vaulter\Drive\Data\Nodes\CreateFolderInput;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Idempotency\NodeReplayAuthorizer;
use Vaulter\Drive\Idempotency\NodeSnapshotCodec;
use Vaulter\Idempotency\IdempotentWrite;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;

#[Api]
final readonly class FolderOperations implements Contract
{
    #[Internal]
    public function __construct(private DriveContext $context, private NodeLocator $locator, private OperationGuard $guard, private CreateFolderAction $createAction, private NodeSnapshotMapper $mapper, private OperationRunner $runner) {}

    public function create(string $name, NodeSnapshot|string|int|null $parent = null, ?CreateFolderOptions $options = null): NodeSnapshot
    {
        $options ??= new CreateFolderOptions;
        $parentId = $parent instanceof NodeSnapshot ? $parent->id : ($parent === null ? null : (string) $parent);
        // Idempotent by key (D25): the receipt is the snapshot; a replay needs Create where the folder is now.
        $idempotency = new IdempotentWrite(
            codec: new NodeSnapshotCodec,
            authorizer: NodeReplayAuthorizer::forCreate($this->context, $this->locator, $this->guard, $parentId),
            fingerprint: ['parent' => $parentId ?? (string) $this->context->drive->root_node_id, 'name' => $name, 'on_conflict' => $options->onConflict->value],
        );

        $created = $this->runner->write(new OperationSpec('drive.folders.create', new ModuleId('drive')), $this->context->operation, function () use ($name, $parent, $options): NodeSnapshot {
            $parent = $this->locator->find($this->context, $parent ?? $this->context->drive->root_node_id ?? throw NodeNotFoundException::forId('root'));
            $this->guard->authorizeCreate($this->context, $parent);

            return $this->mapper->map($this->createAction->execute($this->context, new CreateFolderInput($this->context->drive, $name, $parent, $options->onConflict)), $this->context->actor->ref());
        }, $idempotency);

        // A replayed receipt is semantic and keyless: the authorized replay gets the reader key afresh.
        return $created->syncKey === null ? $this->mapper->withSyncKey($created, $this->context->actor->ref()) : $created;
    }
}
