<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Vaulter\Access\ValueObjects\NodeAbilities;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\MoveOptions;
use Vaulter\Data\Drive\NodePage;
use Vaulter\Data\Drive\SearchOptions;
use Vaulter\Models\Drive\Node;

interface NodeOperations
{
    public function find(Node|int|string $node): Node;

    public function children(Node|int|string|null $folder = null, ?ListOptions $options = null): NodePage;

    public function search(string $term, ?SearchOptions $options = null): NodePage;

    public function rename(Node|int|string $node, string $name, ?int $expectedSequence = null): Node;

    public function move(Node|int|string $node, Node|int|string|null $destination, ?MoveOptions $options = null): Node;

    public function copy(Node|int|string $node, Node|int|string|null $destination = null, ?CopyOptions $options = null): Node;

    /** Star the node for the acting user (View); personal, idempotent, no sequence change. */
    public function star(Node|int|string $node): Node;

    public function unstar(Node|int|string $node): Node;

    /** The acting user's starred, currently visible nodes of this drive, newest star first. */
    public function starred(?ListOptions $options = null): NodePage;

    /**
     * The node's properties (View), namespace => key => value.
     *
     * @return array<string, array<string, string>>
     */
    public function properties(Node|int|string $node): array;

    /**
     * Patch one namespace atomically (Edit): write $set, remove the $unset keys,
     * bump the node sequence. Namespaces vaulter and vaulter.* are system-only.
     *
     * @param array<string, string> $set
     * @param list<string>          $unset
     */
    public function setProperties(Node|int|string $node, string $namespace, array $set, array $unset = [], ?int $expectedSequence = null): Node;

    public function trash(Node|int|string $node, ?int $expectedSequence = null): Node;

    public function abilities(Node|int|string $node): NodeAbilities;
}
