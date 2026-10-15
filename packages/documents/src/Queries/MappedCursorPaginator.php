<?php

declare(strict_types=1);

namespace Vaulter\Documents\Queries;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Vaulter\Attributes\Internal;

/**
 * A cursor page whose items were mapped to snapshots: the cursors stay those of the
 * source rows, which the mapped items no longer carry the sort columns for.
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @extends CursorPaginator<TKey, TValue>
 */
#[Internal]
final class MappedCursorPaginator extends CursorPaginator
{
    /**
     * @template TSource of Model
     *
     * @param CursorPaginator<int, TSource> $source
     * @param list<TValue>                  $items
     */
    public function __construct(CursorPaginator $source, array $items, private readonly ?Cursor $next, private readonly ?Cursor $previous)
    {
        parent::__construct($items, $source->perPage(), $source->cursor(), ['path' => $source->path(), 'cursorName' => $source->getCursorName(), 'parameters' => ['id']]);
    }

    public function nextCursor(): ?Cursor
    {
        return $this->next;
    }

    public function previousCursor(): ?Cursor
    {
        return $this->previous;
    }
}
