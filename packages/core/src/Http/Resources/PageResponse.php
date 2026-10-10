<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Page;

/**
 * The one list envelope of the versioned API: `{data, meta: {next_cursor, per_page}}`.
 * Lists are cursor-paginated and never count the total, which would cost a scan of
 * everything the caller can see on every page.
 */
final class PageResponse
{
    public const int DEFAULT_PER_PAGE = 50;

    public const int MAX_PER_PAGE = 200;

    /**
     * @template T
     *
     * @param Page<T>            $page
     * @param callable(T): mixed $map  one item to its resource
     */
    public static function make(Page $page, callable $map, int $perPage): JsonResponse
    {
        return self::envelope(array_map($map, $page->items), $page->nextCursor, $perPage);
    }

    /**
     * @template T
     *
     * @param CursorPaginator<array-key, T> $paginator
     * @param callable(T): mixed            $map
     */
    public static function fromPaginator(CursorPaginator $paginator, callable $map): JsonResponse
    {
        return self::envelope(array_values(array_map($map, $paginator->items())), $paginator->nextCursor()?->encode(), $paginator->perPage());
    }

    /** `per_page` and `cursor` of the query string; out of range → 422 validation_failed. */
    public static function listOptions(Request $request): ListOptions
    {
        return new ListOptions(perPage: self::perPage($request), cursor: self::cursor($request));
    }

    public static function perPage(Request $request): int
    {
        self::validate($request);

        return $request->query->has('per_page') ? (int) $request->query('per_page') : self::DEFAULT_PER_PAGE;
    }

    public static function cursor(Request $request): ?string
    {
        self::validate($request);
        $cursor = $request->query('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    private static function validate(Request $request): void
    {
        Validator::make($request->query(), [
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ])->validate();
    }

    /** @param list<mixed> $items */
    private static function envelope(array $items, ?string $nextCursor, int $perPage): JsonResponse
    {
        return new JsonResponse([
            'data' => $items,
            'meta' => ['next_cursor' => $nextCursor, 'per_page' => $perPage],
        ]);
    }
}
