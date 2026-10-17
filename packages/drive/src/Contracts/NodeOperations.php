<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts;

use Vaulter\Attributes\Api;
use Vaulter\Data\Page;
use Vaulter\Drive\Access\ValueObjects\NodeAbilities;
use Vaulter\Drive\Data\CopyOptions;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\MoveOptions;
use Vaulter\Drive\Data\SearchOptions;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;

#[Api]
interface NodeOperations
{
    public function find(NodeSnapshot|string|int $node): NodeSnapshot;

    /** @return Page<NodeSnapshot> */
    public function children(NodeSnapshot|string|int|null $folder = null, ?ListOptions $options = null): Page;

    /** @return Page<NodeSnapshot> */
    public function search(string $term, ?SearchOptions $options = null): Page;

    public function rename(NodeSnapshot|string|int $node, string $name, ?int $expectedSequence = null): NodeSnapshot;

    public function move(NodeSnapshot|string|int $node, NodeSnapshot|string|int|null $destination, ?MoveOptions $options = null): NodeSnapshot;

    public function copy(NodeSnapshot|string|int $node, NodeSnapshot|string|int|null $destination = null, ?CopyOptions $options = null): NodeSnapshot;

    /** Star the node for the acting user (View); personal, idempotent, no sequence change. */
    public function star(NodeSnapshot|string|int $node): NodeSnapshot;

    public function unstar(NodeSnapshot|string|int $node): NodeSnapshot;

    /**
     * The acting user's starred, currently visible nodes of this drive, newest star first.
     *
     * @return Page<NodeSnapshot>
     */
    public function starred(?ListOptions $options = null): Page;

    /**
     * The node's properties (View), namespace => key => value.
     *
     * @return array<string, array<string, string>>
     */
    public function properties(NodeSnapshot|string|int $node): array;

    /**
     * Patch one namespace atomically (Edit): write $set, remove the $unset keys,
     * bump the node sequence. Namespaces vaulter and vaulter.* are system-only.
     *
     * @param array<string, string> $set
     * @param list<string>          $unset
     */
    public function setProperties(NodeSnapshot|string|int $node, string $namespace, array $set, array $unset = [], ?int $expectedSequence = null): NodeSnapshot;

    public function trash(NodeSnapshot|string|int $node, ?int $expectedSequence = null): NodeSnapshot;

    public function abilities(NodeSnapshot|string|int $node): NodeAbilities;

    /**
     * abilities() for many nodes from one batch evaluation (a listing page). Nodes the
     * actor cannot view are left out instead of failing the call.
     *
     * @param  list<NodeSnapshot|string|int> $nodes
     * @return array<string, NodeAbilities>  node id => abilities
     */
    public function abilitiesMany(array $nodes): array;
}
