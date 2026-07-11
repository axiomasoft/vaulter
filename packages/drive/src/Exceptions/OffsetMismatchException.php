<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Exceptions\VaulterException;

/**
 * The tus PATCH `Upload-Offset` did not match the server's authoritative offset,
 * AND the mismatch is not a matching-range idempotent retransmit (§2.8 / N3 /
 * F-P1.4-02). tus 1.0.0 requires exactly 409 Conflict here — never the bare
 * 302 redirect / 422 a plain Laravel ValidationException renders to — carrying
 * the CURRENT server offset so a real tus client re-reads it and resynchronises.
 *
 * Distinct from `UploadConflictException` (P0.3, F-P1.4-01): that one fires when
 * the row lock itself cannot be acquired (a second PATCH racing the first); this
 * one fires when the lock WAS acquired but the client's declared offset disagrees
 * with the server's.
 */
final class OffsetMismatchException extends VaulterException
{
    public function __construct(
        private readonly int $serverOffset,
    ) {
        parent::__construct(
            "[Vaulter] Upload-Offset does not match the server offset {$this->serverOffset}. ".
            'Fix: re-read the session HEAD to learn the authoritative Upload-Offset and resume. '.
            'Docs: '.self::docs('upload-conflict'),
        );
    }

    /**
     * Surface the offset mismatch as tus 409 — the client must re-read HEAD and
     * resume from the authoritative offset carried in this same response.
     *
     * Tus-Resumable MUST be present on every response, error paths included
     * (C1/U1.1) — a strict tus client verifies the server's protocol version on
     * each reply, not only on success.
     */
    public function render(Request $request): JsonResponse
    {
        return new JsonResponse(
            ['message' => $this->getMessage()],
            409,
            [
                'Upload-Offset' => (string) $this->serverOffset,
                'Tus-Resumable' => '1.0.0',
            ],
        );
    }
}
