<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Exceptions\VaulterException;

/**
 * A concurrent tus PATCH is already mutating this upload session (§2.8 / N3 /
 * F-P1.4-01). The server serialises the read-modify-write of received_bytes with a
 * row lock held for the whole "read offset → append bytes → advance" window; a
 * second PATCH that cannot take the lock is REJECTED rather than made to wait, so a
 * retry storm never piles up blocked requests. The tus contract puts the burden of
 * preventing concurrent PATCH on the server, and the client's recovery is to re-read
 * HEAD and resume from the authoritative offset.
 *
 * The precise HTTP status mapping (tus 409 Conflict) is owned by a later slice
 * (P3.4/B7); here the handler raises this typed exception and the controller is left
 * untouched beyond what the fix requires.
 */
final class UploadConflictException extends VaulterException
{
    public static function forSession(string $uploadId): self
    {
        return new self(
            "[Vaulter] Upload session [{$uploadId}] is being modified by a concurrent request. ".
            'Fix: re-read the session HEAD to learn the authoritative Upload-Offset and resume. '.
            'Docs: '.self::docs('upload-conflict'),
        );
    }

    /**
     * Surface a concurrent-modification conflict as 409 — the client must re-read
     * HEAD and resume from the authoritative offset.
     */
    public function render(Request $request): JsonResponse
    {
        return new JsonResponse(['message' => $this->getMessage()], 409);
    }
}
