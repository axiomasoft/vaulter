<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Vaulter\Access\OperationGuard;
use Vaulter\Access\ValueObjects\NodeAbilities;
use Vaulter\Contracts\Drive\NodeOperations as Contract;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\MoveOptions;
use Vaulter\Data\Drive\NodePage;
use Vaulter\Data\Drive\SearchOptions;
use Vaulter\Data\Drive\UpdateNodeOptions;
use Vaulter\Drive\Actions\Nodes\CopyNodeAction;
use Vaulter\Drive\Actions\Nodes\MoveNodeAction;
use Vaulter\Drive\Actions\Nodes\RenameNodeAction;
use Vaulter\Drive\Actions\Nodes\UpdateNodeAction;
use Vaulter\Drive\Actions\Trash\TrashNodeAction;
use Vaulter\Drive\Data\Nodes\CopyNodeInput;
use Vaulter\Drive\Data\Nodes\MoveNodeInput;
use Vaulter\Drive\Data\Nodes\RenameNodeInput;
use Vaulter\Drive\Data\Nodes\UpdateNodeInput;
use Vaulter\Drive\Data\Trash\TrashNodeInput;
use Vaulter\Drive\Queries\ListChildrenQuery;
use Vaulter\Drive\Queries\SearchQuery;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Operations\OperationKind;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
use Vaulter\Support\TreeGuard;

final readonly class NodeOperations implements Contract
{
    public function __construct(private OperationContext $context, private NodeLocator $locator, private OperationGuard $guard, private TreeGuard $tree, private ListChildrenQuery $childrenQuery, private SearchQuery $searchQuery, private RenameNodeAction $renameAction, private MoveNodeAction $moveAction, private CopyNodeAction $copyAction, private UpdateNodeAction $updateAction, private TrashNodeAction $trashAction) {}

    public function find(Node|int|string $node): Node
    {
        return $this->authorized($node, Ability::View);
    }

    public function children(Node|int|string|null $folder = null, ?ListOptions $options = null): NodePage
    {
        $folder = $this->destination($folder, false);

        return $this->childrenQuery->execute($this->context, $folder, $options ?? new ListOptions);
    }

    public function search(string $term, ?SearchOptions $options = null): NodePage
    {
        $options ??= new SearchOptions;
        $within = $options->within === null ? null : $this->destination($options->within, false);

        return $this->searchQuery->execute($this->context, $term, $options, $within);
    }

    public function rename(Node|int|string $node, string $name, ?int $expectedSequence = null): Node
    {
        return $this->renameAction->execute($this->context, new RenameNodeInput($this->authorized($node, Ability::Edit), $name, $expectedSequence));
    }

    public function move(Node|int|string $node, Node|int|string|null $destination, ?MoveOptions $options = null): Node
    {
        $node = $this->authorized($node, Ability::Move);
        $parent = $this->destination($destination);
        $options ??= new MoveOptions;

        return $this->moveAction->execute($this->context, new MoveNodeInput($node, $parent, $options->expectedSequence, $options->onConflict));
    }

    public function copy(Node|int|string $node, Node|int|string|null $destination = null, ?CopyOptions $options = null): Node
    {
        $node = $this->authorized($node, Ability::Copy);
        $parent = $this->destination($destination);
        $options ??= new CopyOptions;

        return $this->copyAction->execute($this->context, new CopyNodeInput($node, $parent, $options->name, $options->onConflict));
    }

    public function update(Node|int|string $node, UpdateNodeOptions $options): Node
    {
        return $this->updateAction->execute($this->context, new UpdateNodeInput($this->authorized($node, Ability::Edit), $options->pinned, $options->position, $options->expectedSequence));
    }

    public function trash(Node|int|string $node, ?int $expectedSequence = null): Node
    {
        return $this->trashAction->execute($this->context, new TrashNodeInput($this->authorized($node, Ability::Trash), $expectedSequence));
    }

    public function abilities(Node|int|string $node): NodeAbilities
    {
        return $this->guard->abilities($this->context, $this->find($node));
    }

    private function authorized(Node|int|string $key, Ability $ability): Node
    {
        $node = $this->locator->find($this->context, $key);
        $this->guard->authorize($this->context, $ability, $node, $ability === Ability::View ? OperationKind::Metadata : OperationKind::Mutation);

        return $node;
    }

    private function destination(Node|int|string|null $key, bool $create = true): Node
    {
        $node = $this->authorized($key ?? $this->context->drive->root_node_id ?? throw NodeNotFoundException::forId('root'), Ability::View);
        $this->tree->assertActiveFolder($node);
        if ($create) {
            $this->guard->authorizeCreate($this->context, $node);
        }

        return $node;
    }
}
