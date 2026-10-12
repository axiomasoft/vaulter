<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Uploads;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Exceptions\Contracts\ProblemException;
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
final class OffsetMismatchException extends VaulterException implements ProblemException
{
    private const string PROBLEM_CODE = 'upload_offset_mismatch';

    private const int HTTP_STATUS = 409;

    public function __construct(
        private readonly int $serverOffset,
    ) {
        parent::__construct(
            "[Vaulter] Upload-Offset does not match the server offset {$this->serverOffset}. ".
            'Fix: re-read the session HEAD to learn the authoritative Upload-Offset and resume. '.
            'Docs: '.self::docs('upload-conflict'),
        );
    }

    public function problemCode(): string
    {
        return self::PROBLEM_CODE;
    }

    public function httpStatus(): int
    {
        return self::HTTP_STATUS;
    }

    public function render(Request $request): JsonResponse|false
    {
        $response = parent::render($request);

        if ($response === false) {
            return false;
        }

        // A tus client needs the authoritative offset and protocol version to resume.
        $response->headers->set('Upload-Offset', (string) $this->serverOffset);
        $response->headers->set('Tus-Resumable', '1.0.0');

        return $response;
    }
}
