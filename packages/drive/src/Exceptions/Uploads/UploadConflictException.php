<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Uploads;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A concurrent tus PATCH is already mutating this upload session (N3 /
 * ). The server serialises the read-modify-write of received_bytes with a
 * row lock held for the whole "read offset → append bytes → advance" window; a
 * second PATCH that cannot take the lock is REJECTED rather than made to wait, so a
 * retry storm never piles up blocked requests. The tus contract puts the burden of
 * preventing concurrent PATCH on the server, and the client's recovery is to re-read
 * HEAD and resume from the authoritative offset.
 *
 * The precise HTTP status mapping (tus 409 Conflict) is owned by a later slice
 * (B7); here the handler raises this typed exception and the controller is left
 * untouched beyond what the fix requires.
 */
#[Api]
final class UploadConflictException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const string PROBLEM_CODE = 'upload_conflict';

    private const int HTTP_STATUS = 409;

    public static function forSession(string $uploadId): self
    {
        return new self(
            "[Vaulter] Upload session [{$uploadId}] is being modified by a concurrent request. ".
            'Fix: re-read the session HEAD to learn the authoritative Upload-Offset and resume. '.
            'Docs: '.self::docs('upload-conflict'),
        );
    }

    /**
     * The session left its open states (Expired by the sweeper, Aborted by the client,
     * already Committed) while this PATCH was mid-flight, so the compare-and-set that
     * advances received_bytes matched zero rows. It is refused
     * rather than forced through: forcing it would resurrect a terminal session and
     * publish an offset for bytes that no longer exist.
     */
    public static function forClosedSession(string $uploadId): self
    {
        return new self(
            "[Vaulter] Upload session [{$uploadId}] is no longer open — it was expired or aborted while this chunk was in flight. ".
            'Fix: re-read the session HEAD; a terminal session is never re-opened, so start a new upload. '.
            'Docs: '.self::docs('upload-conflict'),
        );
    }

    /**
     * The completion request spent its finite budget (D19) before the commit was
     * published. Nothing was published; the session stays Assembled with its
     * staged bytes, so the same completion may be repeated.
     */
    public static function forCompletionDeadline(string $uploadId, int $seconds): self
    {
        return new self(
            "[Vaulter] Upload session [{$uploadId}] did not complete within {$seconds}s; nothing was published. ".
            'Fix: repeat the completion; the staged bytes are kept until the session expires. '.
            'Docs: '.self::docs('upload-conflict'),
        );
    }

    /** The staged storage stream cannot take a transport timeout, so its blocking reads are unbounded (D19). */
    public static function forUnboundedStorage(string $uploadId, string $stream): self
    {
        return new self(
            "[Vaulter] Upload session [{$uploadId}] cannot complete: staged storage stream [{$stream}] does not support a bounded transport timeout. ".
            'Fix: stage resumable uploads on a local or socket-backed disk with finite timeouts. '.
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
}
