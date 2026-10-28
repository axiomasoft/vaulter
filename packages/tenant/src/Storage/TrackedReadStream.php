<?php

declare(strict_types=1);

namespace Vaulter\Tenant\Storage;

use Tenant\Contracts\TrackedStream;

/** PHP stream adapter over the public lease. No raw path/descriptor/resource escapes. */
final class TrackedReadStream
{
    public mixed $context;

    private TrackedStream $stream;

    private bool $eof = false;

    private int $offset = 0;

    private static bool $registered = false;

    public static function open(TrackedStream $stream): mixed
    {
        try {
            if (!self::$registered) {
                if (!stream_wrapper_register('vaulter-tenant-read', self::class)) {
                    throw new \RuntimeException('Stream scheme is already owned.');
                }
                self::$registered = true;
            }
            $context = stream_context_create(['vaulter-tenant-read' => ['stream' => $stream]]);
            $resource = fopen('vaulter-tenant-read://read', 'rb', false, $context);
            if (!is_resource($resource)) {
                throw new \RuntimeException('Cannot open tracked read stream.');
            }

            return $resource;
        } catch (\Throwable $error) {
            $stream->close();
            throw $error;
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $stream = stream_context_get_options($this->context)['vaulter-tenant-read']['stream'] ?? null;
        if ($path !== 'vaulter-tenant-read://read' || $mode !== 'rb' || !$stream instanceof TrackedStream) {
            return false;
        }
        $this->stream = $stream;

        return true;
    }

    public function stream_read(int $length): string
    {
        $bytes = $this->stream->read($length);
        $this->offset += strlen($bytes);
        $this->eof = $bytes === '';

        return $bytes;
    }

    public function stream_eof(): bool
    {
        return $this->eof;
    }

    public function stream_tell(): int
    {
        return $this->offset;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return $offset === 0 && $whence === SEEK_SET && $this->offset === 0;
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        $this->stream->close();
    }
}
