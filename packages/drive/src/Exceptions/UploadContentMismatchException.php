<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Exceptions\VaulterException;

/**
 * The assembled resumable object's magic bytes contradict the client-declared mime
 * (§2.8 / F-P1.4-11 / U5.5): e.g. a file declared image/png whose leading bytes are
 * a ZIP archive. The server recomputes the type from the bytes it holds and refuses
 * to commit a type-confused object rather than trust the client's claim.
 */
final class UploadContentMismatchException extends VaulterException
{
    public static function forSession(string $uploadId, string $declaredMime): self
    {
        return new self(
            "[Vaulter] Upload session [{$uploadId}] declared mime [{$declaredMime}] but the ".
            'assembled object contents do not match it. '.
            'Fix: upload a file whose contents match the declared Content-Type. '.
            'Docs: '.self::docs('upload-content-mismatch'),
        );
    }

    /**
     * Surface a type mismatch as 415 Unsupported Media Type — the declared type is
     * not the type of the bytes.
     */
    public function render(Request $request): JsonResponse
    {
        return new JsonResponse(['message' => $this->getMessage()], 415);
    }
}
