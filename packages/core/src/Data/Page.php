<?php

declare(strict_types=1);

namespace Vaulter\Data;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * One page of a cursor-paginated public listing. The cursor is an opaque string:
 * pass it back unchanged in the options of the next call.
 *
 * @template T
 *
 * @implements IteratorAggregate<int, T>
 */
final readonly class Page implements Countable, IteratorAggregate
{
    /**
     * @internal
     *
     * @param list<T> $items
     */
    public function __construct(public array $items, public ?string $nextCursor, public int $scanned = 0) {}

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }

    /** @return Traversable<int, T> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
