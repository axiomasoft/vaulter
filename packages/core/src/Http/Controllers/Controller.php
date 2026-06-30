<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    protected function actorId(Request $request): int|string|null
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_int($id) || is_string($id) ? $id : null;
    }

    protected function scalarId(Request $request, string $key): int|string
    {
        $value = $request->input($key);

        if (is_int($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
