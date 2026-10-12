<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Uploads;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * The staged temp of a resumable upload is GONE while the session still claims a
 * non-zero offset. This is an impossible state on the happy
 * path — the bytes for [0, offset) were accepted and acknowledged, so the object must
 * exist. It shows up when something else removed the staged object underneath an
 * in-flight PATCH: the sweeper/abort reclaimed it, the bucket was cleaned by
 * hand, the session's disk was re-pointed, or the storage backend lost the object.
 *
 * The path is FAIL-CLOSED on purpose: appending the new chunk to an absent object
 * would silently drop the already-accepted prefix, the server would answer with a
 * REGRESSED authoritative offset, and the client would finish an upload whose bytes
 * are truncated/reordered — a file corrupted without a single error anywhere. Better
 * a loud 409 that forces the client to re-read HEAD (or restart the upload) than a
 * quietly broken file.
 */
final class StagedUploadMissingException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const string PROBLEM_CODE = 'upload_conflict';

    private const int HTTP_STATUS = 409;

    public static function forSession(string $uploadId, int $offset): self
    {
        return new self(
            "[Vaulter] Upload session [{$uploadId}] claims offset {$offset} but its staged object is gone — ".
            'the already-received bytes cannot be recovered, so the chunk is refused rather than silently truncating the file. '.
            'Fix: re-read the session HEAD; if the session is no longer open, start a new upload. '.
            'Docs: '.self::docs('staged-upload-missing'),
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
