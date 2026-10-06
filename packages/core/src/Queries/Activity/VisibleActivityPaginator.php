<?php

declare(strict_types=1);

namespace Vaulter\Queries\Activity;

use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\Paginator;
use Vaulter\Models\Activity\Activity;

/** @extends CursorPaginator<int, Activity> */
final class VisibleActivityPaginator extends CursorPaginator
{
    /** @param list<Activity> $items */
    public function __construct(array $items, int $perPage, private ?Cursor $continuation, bool $hasMore, ?Cursor $cursor = null)
    {
        parent::__construct($items, $perPage, $cursor, ['path' => Paginator::resolveCurrentPath()]);
        $this->hasMore = $hasMore;
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
