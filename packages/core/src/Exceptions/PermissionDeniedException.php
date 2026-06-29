<?php

declare(strict_types=1);

namespace Vaulter\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PermissionDeniedException extends VaulterException
{
    public static function forAction(string $action): self
    {
        return new self(
            sprintf('[Vaulter] You are not authorized to [%s] this document. ', $action)
            .'Why: the configured PermissionResolver returned false. '
            .'Fix: grant the required access level or adjust your resolver. '
            .'Docs: '.self::docs('permission-denied'),
        );
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse(['message' => $this->getMessage()], 403);
    }
}
