<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Vaulter\Access\OperationGuard;
use Vaulter\Contracts\Drive\FolderOperations as Contract;
use Vaulter\Data\Drive\CreateFolderOptions;
use Vaulter\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Actions\Nodes\CreateFolderAction;
use Vaulter\Drive\Data\Nodes\CreateFolderInput;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;

final readonly class FolderOperations implements Contract
{
    public function __construct(private OperationContext $context, private NodeLocator $locator, private OperationGuard $guard, private CreateFolderAction $createAction, private NodeSnapshotMapper $mapper) {}

    public function create(string $name, NodeSnapshot|string|int|null $parent = null, ?CreateFolderOptions $options = null): NodeSnapshot
    {
        $parent = $this->locator->find($this->context, $parent ?? $this->context->drive->root_node_id ?? throw NodeNotFoundException::forId('root'));
        $this->guard->authorizeCreate($this->context, $parent);

        return $this->mapper->map($this->createAction->execute($this->context, new CreateFolderInput($this->context->drive, $name, $parent, ($options ?? new CreateFolderOptions)->onConflict)), $this->context->actor->ref());
    }
}
