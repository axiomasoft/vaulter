<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Cas;

use Vaulter\Attributes\Api;

/**
 * The result of staging an upload to a LOCAL, seekable temp file: the
 * sha256 is recomputed from these durable bytes, never from the live stream nor
 * a client-supplied hash nor an S3 multipart ETag. A StagedObject is the unit a
 * ContentStore hands to BlobStore — the bytes are not yet at their canonical CAS
 * key (that is promote()).
 *
 * It is read-only and self-cleaning: discard() removes the temp file once the
 * bytes have been promoted (or the commit rolled back).
 */
#[Api]
interface StagedObject
{
    /**
     * The absolute path of the local, seekable temp file holding the staged bytes.
     */
    public function path(): string;

    /**
     * The lowercase hex sha256 recomputed server-side from the staged temp file.
     */
    public function sha256(): string;

    public function sizeBytes(): int;

    /**
     * A read stream over the staged bytes (rewound), for promotion to the disk.
     *
     * @return resource
     */
    public function readStream(): mixed;

    /**
     * Remove the temp file. Idempotent — safe to call after promotion or on a
     * rolled-back commit.
     */
    public function discard(): void;
}
