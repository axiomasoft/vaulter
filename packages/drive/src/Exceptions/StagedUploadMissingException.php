<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Exceptions\VaulterException;

/**
 * The staged temp of a resumable upload is GONE while the session still claims a
 * non-zero offset (VLT-REMED P5.2 / R-03). This is an impossible state on the happy
 * path — the bytes for [0, offset) were accepted and acknowledged, so the object must
 * exist. It shows up when something else removed the staged object underneath an
 * in-flight PATCH: the sweeper/abort reclaimed it (R-02), the bucket was cleaned by
 * hand, the session's disk was re-pointed, or the storage backend lost the object.
 *
 * The path is FAIL-CLOSED on purpose: appending the new chunk to an absent object
 * would silently drop the already-accepted prefix, the server would answer with a
 * REGRESSED authoritative offset, and the client would finish an upload whose bytes
 * are truncated/reordered — a file corrupted without a single error anywhere. Better
 * a loud 409 that forces the client to re-read HEAD (or restart the upload) than a
 * quietly broken file.
 */
final class StagedUploadMissingException extends VaulterException
{
    public static function forSession(string $uploadId, int $offset): self
    {
        return new self(
            "[Vaulter] Upload session [{$uploadId}] claims offset {$offset} but its staged object is gone — ".
            'the already-received bytes cannot be recovered, so the chunk is refused rather than silently truncating the file. '.
            'Fix: re-read the session HEAD; if the session is no longer open, start a new upload. '.
            'Docs: '.self::docs('staged-upload-missing'),
        );
    }

    /**
     * 409 Conflict: the client's view of the upload no longer matches the server's.
     */
    public function render(Request $request): JsonResponse
    {
        return new JsonResponse(['message' => $this->getMessage()], 409);
    }
}
