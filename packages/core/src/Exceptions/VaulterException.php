<?php

declare(strict_types=1);

namespace Vaulter\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Http\Problems\ProblemRenderer;

/**
 * Base for every Vaulter error — catch this to handle all package failures.
 */
class VaulterException extends RuntimeException
{
    /**
     * The `{message, code}` body of the unversioned routes; the versioned routes render
     * problem+json through {@see ProblemRenderer} instead.
     */
    public function render(Request $request): JsonResponse|false
    {
        if (!$this instanceof ProblemException) {
            return false;
        }

        // The versioned routes answer problem+json; Laravel renders an exception's own
        // render() before the registered renderables, so step aside for them here.
        $routes = app(RoutesConfig::class);
        if (ProblemRenderer::isVersioned($request, $routes)) {
            return false;
        }

        if (!$request->expectsJson() && !$request->is(trim($routes->prefix(), '/').'/*')) {
            return false;
        }

        return new JsonResponse(
            ['message' => $this->getMessage(), 'code' => $this->problemCode()] + $this->problemDetails(),
            $this->httpStatus(),
            ProblemRenderer::headersOf($this),
        );
    }

    protected static function docs(string $anchor): string
    {
        return 'https://github.com/axiomasoft/vaulter/blob/main/docs/guide/errors.md#'.$anchor;
    }
}
