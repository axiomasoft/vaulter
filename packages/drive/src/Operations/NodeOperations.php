<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Vaulter\Access\OperationGuard;
use Vaulter\Access\ValueObjects\NodeAbilities;
use Vaulter\Contracts\Drive\NodeOperations as Contract;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\MoveOptions;
use Vaulter\Data\Drive\SearchOptions;
use Vaulter\Data\Page;
use Vaulter\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Actions\Nodes\CopyNodeAction;
use Vaulter\Drive\Actions\Nodes\MoveNodeAction;
use Vaulter\Drive\Actions\Nodes\RenameNodeAction;
use Vaulter\Drive\Actions\Nodes\SetNodePropertiesAction;
use Vaulter\Drive\Actions\Nodes\StarNodeAction;
use Vaulter\Drive\Actions\Trash\TrashNodeAction;
use Vaulter\Drive\Data\Nodes\CopyNodeInput;
use Vaulter\Drive\Data\Nodes\MoveNodeInput;
use Vaulter\Drive\Data\Nodes\RenameNodeInput;
use Vaulter\Drive\Data\Nodes\SetNodePropertiesInput;
use Vaulter\Drive\Data\Trash\TrashNodeInput;
use Vaulter\Drive\Queries\ListChildrenQuery;
use Vaulter\Drive\Queries\NodePageQuery;
use Vaulter\Drive\Queries\SearchQuery;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Operations\OperationKind;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Support\TreeGuard;

final readonly class NodeOperations implements Contract
{
    public function __construct(private OperationContext $context, private NodeLocator $locator, private OperationGuard $guard, private TreeGuard $tree, private ListChildrenQuery $childrenQuery, private SearchQuery $searchQuery, private RenameNodeAction $renameAction, private MoveNodeAction $moveAction, private CopyNodeAction $copyAction, private TrashNodeAction $trashAction, private NodeReadRepository $nodes, private StarNodeAction $starAction, private SetNodePropertiesAction $propertiesAction, private NodePageQuery $pages, private NodeSnapshotMapper $mapper) {}

    public function find(NodeSnapshot|string|int $node): NodeSnapshot
    {
        return $this->snapshot($this->authorized($node, Ability::View));
    }

    /** @return Page<NodeSnapshot> */
    public function children(NodeSnapshot|string|int|null $folder = null, ?ListOptions $options = null): Page
    {
        $folder = $this->destination($folder, false);

        return $this->mapper->page($this->childrenQuery->execute($this->context, $folder, $options ?? new ListOptions), $this->context->actor->ref());
    }

    /** @return Page<NodeSnapshot> */
    public function search(string $term, ?SearchOptions $options = null): Page
    {
        $options ??= new SearchOptions;
        $within = $options->within === null ? null : $this->destination($options->within, false);

        return $this->mapper->page($this->searchQuery->execute($this->context, $term, $options, $within), $this->context->actor->ref());
    }

    public function rename(NodeSnapshot|string|int $node, string $name, ?int $expectedSequence = null): NodeSnapshot
    {
        return $this->snapshot($this->renameAction->execute($this->context, new RenameNodeInput($this->authorized($node, Ability::Edit), $name, $expectedSequence)));
    }

    public function move(NodeSnapshot|string|int $node, NodeSnapshot|string|int|null $destination, ?MoveOptions $options = null): NodeSnapshot
    {
        $node = $this->authorized($node, Ability::Move);
        $parent = $this->destination($destination);
        $options ??= new MoveOptions;

        return $this->snapshot($this->moveAction->execute($this->context, new MoveNodeInput($node, $parent, $options->expectedSequence, $options->onConflict)));
    }

    public function copy(NodeSnapshot|string|int $node, NodeSnapshot|string|int|null $destination = null, ?CopyOptions $options = null): NodeSnapshot
    {
        $node = $this->authorized($node, Ability::Copy);
        $parent = $this->destination($destination);
        $options ??= new CopyOptions;
        $authorized = $this->guard->copyableSubtree($this->context, $node, $this->nodes->subtree($node));

        return $this->snapshot($this->copyAction->execute($this->context, new CopyNodeInput($node, $parent, $options->name, $options->onConflict, $authorized)));
    }

    public function star(NodeSnapshot|string|int $node): NodeSnapshot
    {
        return $this->snapshot($this->starAction->execute($this->context, $this->authorized($node, Ability::View), starred: true));
    }

    public function unstar(NodeSnapshot|string|int $node): NodeSnapshot
    {
        return $this->snapshot($this->starAction->execute($this->context, $this->authorized($node, Ability::View), starred: false));
    }

    /** @return Page<NodeSnapshot> */
    public function starred(?ListOptions $options = null): Page
    {
        return $this->mapper->page($this->pages->starred($this->context, $options ?? new ListOptions), $this->context->actor->ref());
    }

    /** @return array<string, array<string, string>> */
    public function properties(NodeSnapshot|string|int $node): array
    {
        return $this->authorized($node, Ability::View)->properties ?? [];
    }

    /**
     * @param array<string, string> $set
     * @param list<string>          $unset
     */
    public function setProperties(NodeSnapshot|string|int $node, string $namespace, array $set, array $unset = [], ?int $expectedSequence = null): NodeSnapshot
    {
        return $this->snapshot($this->propertiesAction->execute($this->context, new SetNodePropertiesInput($this->authorized($node, Ability::Edit), $namespace, $set, $unset, $expectedSequence)));
    }

    public function trash(NodeSnapshot|string|int $node, ?int $expectedSequence = null): NodeSnapshot
    {
        return $this->snapshot($this->trashAction->execute($this->context, new TrashNodeInput($this->authorized($node, Ability::Trash), $expectedSequence)));
    }

    public function abilities(NodeSnapshot|string|int $node): NodeAbilities
    {
        return $this->guard->abilities($this->context, $this->authorized($node, Ability::View));
    }

    private function snapshot(Node $node): NodeSnapshot
    {
        return $this->mapper->map($node, $this->context->actor->ref());
    }

    private function authorized(NodeSnapshot|string|int $key, Ability $ability): Node
    {
        $node = $this->locator->find($this->context, $key);
        $this->guard->authorize($this->context, $ability, $node, $ability === Ability::View ? OperationKind::Metadata : OperationKind::Mutation);

        return $node;
    }

    private function destination(NodeSnapshot|string|int|null $key, bool $create = true): Node
    {
        $node = $this->authorized($key ?? $this->context->drive->root_node_id ?? throw NodeNotFoundException::forId('root'), Ability::View);
        $this->tree->assertActiveFolder($node);
        if ($create) {
            $this->guard->authorizeCreate($this->context, $node);
        }

        return $node;
    }
}
