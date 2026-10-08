<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Vaulter\Access\ValueObjects\NodeAbilities;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\MoveOptions;
use Vaulter\Data\Drive\SearchOptions;
use Vaulter\Data\Page;
use Vaulter\Data\Snapshots\NodeSnapshot;

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
}
