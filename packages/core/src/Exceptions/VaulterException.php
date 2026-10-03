<?php

declare(strict_types=1);

namespace Vaulter\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Support\Config;

/**
 * Base for every Vaulter error — catch this to handle all package failures.
 */
class VaulterException extends RuntimeException
{
    public function render(Request $request): JsonResponse|false
    {
        if (!$this instanceof ProblemException) {
            return false;
        }

        if (!$request->expectsJson() && !$request->is(trim(Config::routePrefix(), '/').'/*')) {
            return false;
        }

        return new JsonResponse(
            ['message' => $this->getMessage(), 'code' => $this->problemCode()] + $this->renderExtras(),
            $this->httpStatus(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function renderExtras(): array
    {
        return [];
    }

    protected static function docs(string $anchor): string
    {
        return 'https://github.com/axioma-studio/vaulter/blob/main/docs/guide/errors.md#'.$anchor;
    }
}
