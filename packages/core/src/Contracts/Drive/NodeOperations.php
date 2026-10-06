<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Vaulter\Access\ValueObjects\NodeAbilities;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\MoveOptions;
use Vaulter\Data\Drive\NodePage;
use Vaulter\Data\Drive\SearchOptions;
use Vaulter\Data\Drive\UpdateNodeOptions;
use Vaulter\Models\Drive\Node;

interface NodeOperations
{
    public function find(Node|int|string $node): Node;

    public function children(Node|int|string|null $folder = null, ?ListOptions $options = null): NodePage;

    public function search(string $term, ?SearchOptions $options = null): NodePage;

    public function rename(Node|int|string $node, string $name, ?int $expectedSequence = null): Node;

    public function move(Node|int|string $node, Node|int|string|null $destination, ?MoveOptions $options = null): Node;

    public function copy(Node|int|string $node, Node|int|string|null $destination = null, ?CopyOptions $options = null): Node;

    public function update(Node|int|string $node, UpdateNodeOptions $options): Node;

    public function trash(Node|int|string $node, ?int $expectedSequence = null): Node;

    public function abilities(Node|int|string $node): NodeAbilities;
}
