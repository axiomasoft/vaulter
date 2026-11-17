<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Packman\Attributes\Internal;
use Traversable;
use Vaulter\Data\Page;
use Vaulter\Drive\Models\Node;

/**
 * Queries return models; the public listing type is {@see Page}.
 *
 * @implements IteratorAggregate<int, Node>
 */
#[Internal]
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
