<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads;

use Packman\Attributes\Internal;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A read-only view of a borrowed staged stream that re-checks the completion
 * deadline before each chunk and re-arms the transport timeout of a socket
 * stream with the budget left. The caller keeps owning (and closes) the stream.
 */
#[Internal]
final class DeadlineStream implements StreamInterface
{
    /** @param resource $stream */
    public function __construct(
        private readonly mixed $stream,
        private readonly CompletionDeadline $deadline,
        private readonly string $uploadId,
    ) {}

    public function read(int $length): string
    {
        $this->deadline->assertOpen($this->uploadId);
        $local = CompletionDeadline::local($this->stream);
        if (!$local) {
            $this->deadline->timeout($this->stream, $this->uploadId);
        }
        $bytes = fread($this->stream, max($length, 1));
        if (!$local && stream_get_meta_data($this->stream)['timed_out']) {
            $this->deadline->assertOpen($this->uploadId);

            throw new RuntimeException("Staged read for upload session [{$this->uploadId}] timed out.");
        }
        if ($bytes === false) {
            throw new RuntimeException('Upload stream made no progress.');
        }

        return $bytes;
    }

    public function eof(): bool
    {
        return feof($this->stream);
    }

    public function __toString(): string
    {
        return '';
    }

    public function close(): void {}

    public function detach(): mixed
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        $position = ftell($this->stream);

        return $position === false ? 0 : $position;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Staged completion stream is not seekable.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('Staged completion stream is not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Staged completion stream is read-only.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(65536);
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        $meta = stream_get_meta_data($this->stream);

        return $key === null ? $meta : ($meta[$key] ?? null);
    }
}
