<?php

declare(strict_types=1);

namespace Vaulter\Data\Drive;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;
use Vaulter\Models\Drive\Node;

/** @implements IteratorAggregate<int, Node> */
final readonly class NodePage implements Countable, IteratorAggregate, JsonSerializable
{
    /** @param list<Node> $items */
    public function __construct(public array $items, public ?string $nextCursor, public int $scanned) {}

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }

    /** @return Traversable<int, Node> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return array{items:list<Node>, nextCursor:?string, scanned:int} */
    public function jsonSerialize(): array
    {
        return ['items' => $this->items, 'nextCursor' => $this->nextCursor, 'scanned' => $this->scanned];
    }
}
