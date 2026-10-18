<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Closure;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Page;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Access\ValueObjects\NodeAbilities;
use Vaulter\Drive\Actions\Nodes\CopyNodeAction;
use Vaulter\Drive\Actions\Nodes\MoveNodeAction;
use Vaulter\Drive\Actions\Nodes\RenameNodeAction;
use Vaulter\Drive\Actions\Nodes\SetNodePropertiesAction;
use Vaulter\Drive\Actions\Nodes\StarNodeAction;
use Vaulter\Drive\Actions\Trash\TrashNodeAction;
use Vaulter\Drive\Contracts\NodeOperations as Contract;
use Vaulter\Drive\Data\CopyOptions;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\MoveOptions;
use Vaulter\Drive\Data\Nodes\CopyNodeInput;
use Vaulter\Drive\Data\Nodes\MoveNodeInput;
use Vaulter\Drive\Data\Nodes\RenameNodeInput;
use Vaulter\Drive\Data\Nodes\SetNodePropertiesInput;
use Vaulter\Drive\Data\SearchOptions;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\Trash\TrashNodeInput;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Idempotency\NodeReplayAuthorizer;
use Vaulter\Drive\Idempotency\NodeSnapshotCodec;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Queries\ListChildrenQuery;
use Vaulter\Drive\Queries\NodePageQuery;
use Vaulter\Drive\Queries\SearchQuery;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Support\TreeGuard;
use Vaulter\Idempotency\IdempotentWrite;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;

#[Api]
final readonly class NodeOperations implements Contract
{
    #[Internal]
    public function __construct(private DriveContext $context, private NodeLocator $locator, private OperationGuard $guard, private TreeGuard $tree, private ListChildrenQuery $childrenQuery, private SearchQuery $searchQuery, private RenameNodeAction $renameAction, private MoveNodeAction $moveAction, private CopyNodeAction $copyAction, private TrashNodeAction $trashAction, private NodeReadRepository $nodes, private StarNodeAction $starAction, private SetNodePropertiesAction $propertiesAction, private NodePageQuery $pages, private NodeSnapshotMapper $mapper, private OperationRunner $runner) {}

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
        $nodeId = self::idOf($node);
        // Idempotent by key (D25): the receipt is the snapshot; a replay needs Edit on the node where it is now.
        $idempotency = new IdempotentWrite(
            codec: new NodeSnapshotCodec,
            authorizer: NodeReplayAuthorizer::forNode($this->context, $this->locator, $this->guard, Ability::Edit, $nodeId),
            fingerprint: ['node' => $nodeId, 'name' => $name, 'expected_sequence' => $expectedSequence],
        );

        return $this->write('rename', fn (): NodeSnapshot => $this->snapshot($this->renameAction->execute($this->context, new RenameNodeInput($this->authorized($node, Ability::Edit), $name, $expectedSequence))), $idempotency);
    }

    public function move(NodeSnapshot|string|int $node, NodeSnapshot|string|int|null $destination, ?MoveOptions $options = null): NodeSnapshot
    {
        $options ??= new MoveOptions;
        $nodeId = self::idOf($node);
        // Idempotent by key (D25): a replay needs Move on the node where it is now and Create in the folder it is in now
        // (architecture#operation-chains), so a node moved on into a folder the actor cannot see is refused, not replayed.
        $idempotency = new IdempotentWrite(
            codec: new NodeSnapshotCodec,
            authorizer: NodeReplayAuthorizer::forNode($this->context, $this->locator, $this->guard, Ability::Move, $nodeId, alsoCreate: true),
            fingerprint: ['node' => $nodeId, 'destination' => $destination === null ? null : self::idOf($destination), 'on_conflict' => $options->onConflict->value, 'expected_sequence' => $options->expectedSequence, 'expected_destination_sequence' => $options->expectedDestinationSequence],
        );

        return $this->write('move', function () use ($node, $destination, $options): NodeSnapshot {
            $node = $this->authorized($node, Ability::Move);
            $parent = $this->destination($destination);

            return $this->snapshot($this->moveAction->execute($this->context, new MoveNodeInput($node, $parent, $options->expectedSequence, $options->onConflict, $options->expectedDestinationSequence)));
        }, $idempotency);
    }

    public function copy(NodeSnapshot|string|int $node, NodeSnapshot|string|int|null $destination = null, ?CopyOptions $options = null): NodeSnapshot
    {
        $options ??= new CopyOptions;
        $destinationId = $destination === null ? null : self::idOf($destination);
        // Idempotent by key (D25): the receipt is the copy's snapshot; a replay needs Create in the folder the copy is in now.
        $idempotency = new IdempotentWrite(
            codec: new NodeSnapshotCodec,
            authorizer: NodeReplayAuthorizer::forCreate($this->context, $this->locator, $this->guard, $destinationId),
            fingerprint: ['node' => self::idOf($node), 'destination' => $destinationId, 'name' => $options->name, 'on_conflict' => $options->onConflict->value],
        );

        return $this->write('copy', function () use ($node, $destination, $options): NodeSnapshot {
            $node = $this->authorized($node, Ability::Copy);
            $parent = $this->destination($destination);
            $authorized = $this->guard->copyableSubtree($this->context, $node, $this->nodes->subtree($node));

            return $this->snapshot($this->copyAction->execute($this->context, new CopyNodeInput($node, $parent, $options->name, $options->onConflict, $authorized)));
        }, $idempotency);
    }

    public function star(NodeSnapshot|string|int $node): NodeSnapshot
    {
        return $this->write('star', fn (): NodeSnapshot => $this->snapshot($this->starAction->execute($this->context, $this->authorized($node, Ability::View), starred: true)));
    }

    public function unstar(NodeSnapshot|string|int $node): NodeSnapshot
    {
        return $this->write('unstar', fn (): NodeSnapshot => $this->snapshot($this->starAction->execute($this->context, $this->authorized($node, Ability::View), starred: false)));
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
        $nodeId = self::idOf($node);
        $idempotency = new IdempotentWrite(
            codec: new NodeSnapshotCodec,
            authorizer: NodeReplayAuthorizer::forNode($this->context, $this->locator, $this->guard, Ability::Edit, $nodeId),
            fingerprint: ['node' => $nodeId, 'namespace' => $namespace, 'set' => $set, 'unset' => $unset, 'expected_sequence' => $expectedSequence],
        );

        return $this->write('set_properties', fn (): NodeSnapshot => $this->snapshot($this->propertiesAction->execute($this->context, new SetNodePropertiesInput($this->authorized($node, Ability::Edit), $namespace, $set, $unset, $expectedSequence))), $idempotency);
    }

    public function trash(NodeSnapshot|string|int $node, ?int $expectedSequence = null): NodeSnapshot
    {
        $nodeId = self::idOf($node);
        // The node is in the trash once the first attempt committed: a replay looks it up there.
        $idempotency = new IdempotentWrite(
            codec: new NodeSnapshotCodec,
            authorizer: NodeReplayAuthorizer::forNode($this->context, $this->locator, $this->guard, Ability::Trash, $nodeId, includeTrashed: true),
            fingerprint: ['node' => $nodeId, 'expected_sequence' => $expectedSequence],
        );

        return $this->write('trash', fn (): NodeSnapshot => $this->snapshot($this->trashAction->execute($this->context, new TrashNodeInput($this->authorized($node, Ability::Trash), $expectedSequence))), $idempotency);
    }

    public function abilities(NodeSnapshot|string|int $node): NodeAbilities
    {
        return $this->guard->abilities($this->context, $this->authorized($node, Ability::View));
    }

    /**
     * @param  list<NodeSnapshot|string|int> $nodes
     * @return array<string, NodeAbilities>
     */
    public function abilitiesMany(array $nodes): array
    {
        return $this->guard->abilitiesMany($this->context, $nodes);
    }

    /**
     * @template T
     *
     * @param  Closure(): T $attempt
     * @return T
     */
    private function write(string $verb, Closure $attempt, ?IdempotentWrite $idempotency = null): mixed
    {
        return $this->runner->write(new OperationSpec('drive.nodes.'.$verb, new ModuleId('drive')), $this->context->operation, $attempt, $idempotency);
    }

    private static function idOf(NodeSnapshot|string|int $node): string
    {
        return $node instanceof NodeSnapshot ? $node->id : (string) $node;
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
