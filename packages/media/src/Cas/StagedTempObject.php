<?php

declare(strict_types=1);

namespace Vaulter\Media\Cas;

use RuntimeException;
use Vaulter\Contracts\Cas\StagedObject;

/**
 * A StagedObject backed by a local seekable temp file (H.3). The sha256 was
 * recomputed from THESE durable bytes by the ContentStore, so promotion and the
 * dedup decision never trust the live stream or a client hash. discard() removes
 * the temp file and is idempotent.
 */
final class StagedTempObject implements StagedObject
{
    private bool $discarded = false;

    public function __construct(
        private readonly string $path,
        private readonly string $sha256,
        private readonly int $sizeBytes,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function sha256(): string
    {
        return $this->sha256;
    }

    public function sizeBytes(): int
    {
        return $this->sizeBytes;
    }

    /**
     * @return resource
     */
    public function readStream(): mixed
    {
        if ($this->discarded) {
            throw new RuntimeException('Cannot read a discarded staged object.');
        }

        $stream = @fopen($this->path, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Unable to reopen staged temp file [{$this->path}].");
        }

        return $stream;
    }

    public function discard(): void
    {
        if ($this->discarded) {
            return;
        }

        $this->discarded = true;

        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
