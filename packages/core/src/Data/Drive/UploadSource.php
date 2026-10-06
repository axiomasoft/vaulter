<?php

declare(strict_types=1);

namespace Vaulter\Data\Drive;

use ArrayObject;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

final readonly class UploadSource
{
    /** @var ArrayObject<int, resource> */
    private ArrayObject $ownedHandles;

    /** @param string|resource|StreamInterface $source */
    private function __construct(
        private mixed $source,
        private string $kind,
        private string $filename,
        private ?string $mimeType,
        private ?int $size,
    ) {
        if (trim($filename) === '' || str_contains($filename, "\0")) {
            throw new InvalidArgumentException('An upload name is required.');
        }
        if ($size !== null && $size < 0) {
            throw new InvalidArgumentException('Declared size cannot be negative.');
        }
        // Only descriptors opened here belong to the package, even on failure paths.
        $this->ownedHandles = self::newOwnedHandles();
    }

    /** @return ArrayObject<int, resource> */
    private static function newOwnedHandles(): ArrayObject
    {
        return new ArrayObject;
    }

    public static function fromUploadedFile(UploadedFile $file): self
    {
        return new self(
            source: $file->getPathname(),
            kind: 'path',
            filename: $file->getClientOriginalName(),
            mimeType: $file->getClientMimeType(),
            size: $file->getSize(),
        );
    }

    public static function fromPath(string $path, ?string $name = null): self
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('An upload path is required.');
        }

        return new self(source: $path, kind: 'path', filename: $name ?? basename($path), mimeType: null, size: null);
    }

    /** @param resource $stream Read from the current position; never close or rewind it. */
    public static function fromStream($stream, string $name, ?int $size = null): self
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('An open readable stream is required.');
        }
        $mode = stream_get_meta_data($stream)['mode'];
        if (!str_contains($mode, 'r') && !str_contains($mode, '+')) {
            throw new InvalidArgumentException('An open readable stream is required.');
        }

        return new self(source: $stream, kind: 'stream', filename: $name, mimeType: null, size: $size);
    }

    public static function fromString(string $bytes, string $name): self
    {
        return new self(source: $bytes, kind: 'string', filename: $name, mimeType: null, size: strlen($bytes));
    }

    public static function fromPsrStream(StreamInterface $stream, string $name): self
    {
        if (!$stream->isReadable()) {
            throw new InvalidArgumentException('A readable PSR stream is required.');
        }

        return new self(source: $stream, kind: 'psr', filename: $name, mimeType: null, size: $stream->getSize());
    }

    public function name(): string
    {
        return $this->filename;
    }

    public function declaredMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function declaredSize(): ?int
    {
        return $this->size;
    }

    /** @internal @return resource|StreamInterface */
    public function openForReading(): mixed
    {
        if ($this->kind === 'stream' || $this->kind === 'psr') {
            return $this->source;
        }

        if (!is_string($this->source)) {
            throw new RuntimeException('Invalid owned upload source.');
        }
        try {
            $handle = @fopen($this->kind === 'path' ? $this->source : 'php://temp', $this->kind === 'path' ? 'rb' : 'w+b');
        } catch (Throwable) {
            // The path may contain private host information; never expose it in an error.
            throw new RuntimeException('Unable to open upload source.');
        }
        if ($handle === false) {
            throw new RuntimeException('Unable to open upload source.');
        }
        $this->ownedHandles[(int) $handle] = $handle;

        try {
            if ($this->kind === 'string') {
                $offset = 0;
                $length = strlen($this->source);
                while ($offset < $length) {
                    $written = fwrite($handle, substr($this->source, $offset, 65536));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Unable to prepare upload source.');
                    }
                    $offset += $written;
                }
                rewind($handle);
            }

            return $handle;
        } catch (Throwable $exception) {
            $this->releaseIfOwned($handle);

            throw $exception;
        }
    }

    /** @internal */
    public function releaseIfOwned(mixed $handle): void
    {
        foreach ($this->ownedHandles as $key => $owned) {
            if ($owned === $handle) {
                unset($this->ownedHandles[$key]);
                if (is_resource($owned)) {
                    fclose($owned);
                }

                return;
            }
        }
    }
}
