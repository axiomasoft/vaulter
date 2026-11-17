<?php

declare(strict_types=1);

namespace Vaulter\Drive\Queries\Activity;

use Closure;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\Paginator;
use Packman\Attributes\Internal;

/**
 * @template TItem of object
 *
 * @extends CursorPaginator<int, TItem>
 */
#[Internal]
final class VisibleActivityPaginator extends CursorPaginator
{
    /** @param list<TItem> $items */
    public function __construct(array $items, int $perPage, private ?Cursor $continuation, bool $hasMore, ?Cursor $cursor = null)
    {
        parent::__construct($items, $perPage, $cursor, ['path' => Paginator::resolveCurrentPath()]);
        $this->hasMore = $hasMore;
    }

    /**
     * The same page with every item converted; the continuation stays.
     *
     * @template TOut of object
     *
     * @param  Closure(TItem): TOut           $map
     * @return VisibleActivityPaginator<TOut>
     */
    public function convert(Closure $map): self
    {
        return new self(array_values(array_map($map, $this->items())), $this->perPage(), $this->continuation, $this->hasMore, $this->cursor);
    }

    public function nextCursor(): ?Cursor
    {
        return $this->hasMore ? $this->continuation : null;
    }

    public function previousCursor(): ?Cursor
    {
        return null;
    }
}
