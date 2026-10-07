<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Vaulter\Access\OperationGuard;
use Vaulter\Contracts\Drive\FolderOperations as Contract;
use Vaulter\Data\Drive\CreateFolderOptions;
use Vaulter\Drive\Actions\Nodes\CreateFolderAction;
use Vaulter\Drive\Data\Nodes\CreateFolderInput;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;

final readonly class FolderOperations implements Contract
{
    public function __construct(private OperationContext $context, private NodeLocator $locator, private OperationGuard $guard, private CreateFolderAction $createAction) {}

    public function create(string $name, Node|int|string|null $parent = null, ?CreateFolderOptions $options = null): Node
    {
        $parent = $this->locator->find($this->context, $parent ?? $this->context->drive->root_node_id ?? throw NodeNotFoundException::forId('root'));
        $this->guard->authorizeCreate($this->context, $parent);

        return $this->createAction->execute($this->context, new CreateFolderInput($this->context->drive, $name, $parent, ($options ?? new CreateFolderOptions)->onConflict));
    }
}
