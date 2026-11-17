<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Support;

use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Http\Resources\PageResponse;

/** The list options of a drive request: `per_page` and `cursor` of the query string, validated like every list of the API. */
#[Internal]
final class ListQuery
{
    /** Out of range → 422 validation_failed. */
    public static function options(Request $request): ListOptions
    {
        return new ListOptions(perPage: PageResponse::perPage($request), cursor: PageResponse::cursor($request));
    }
}
