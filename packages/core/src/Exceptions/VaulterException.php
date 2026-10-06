<?php

declare(strict_types=1);

namespace Vaulter\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Exceptions\Contracts\ProblemException;

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

        if (!$request->expectsJson() && !$request->is(trim(app(RoutesConfig::class)->prefix(), '/').'/*')) {
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
        return 'https://github.com/axiomasoft/vaulter/blob/main/docs/guide/errors.md#'.$anchor;
    }
}
