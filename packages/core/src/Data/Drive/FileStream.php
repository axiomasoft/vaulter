<?php

declare(strict_types=1);

namespace Vaulter\Data\Drive;

use InvalidArgumentException;
use Vaulter\Attributes\Api;
use Vaulter\Data\Snapshots\FileRevisionSnapshot;

#[Api]
final readonly class FileStream
{
    /** @param resource $stream The caller closes the stream after consumption. */
    public function __construct(
        public mixed $stream,
        public string $filename,
        public string $mimeType,
        public int $size,
        public string $sha256,
        public FileRevisionSnapshot $revision,
    ) {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('FileStream requires an open byte stream.');
        }
        if ($size < 0) {
            throw new InvalidArgumentException('File size cannot be negative.');
        }
    }
}
