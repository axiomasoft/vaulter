<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Http\Message\StreamInterface;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;

/**
 * The finite, monotonic budget of one upload completion request. It is
 * started ONCE before the session critical section and its DB retries, so a
 * deadlock retry spends the same budget instead of resetting it. It is checked
 * between staged-stream chunks, and every blocking staged read gets a transport
 * timeout no larger than what is left; a stream whose blocking reads cannot be
 * bounded is refused as an unsupported capability instead of trusted to return.
 *
 * Exhaustion is a safe refusal: nothing is published, the session stays
 * Assembled and its staged bytes stay for a repeat or the sweeper.
 */
#[Internal]
final readonly class CompletionDeadline
{
    /** Socket transports accept stream_set_timeout(). */
    private const array SOCKET_TYPES = ['tcp_socket', 'tcp_socket/ssl', 'udp_socket', 'unix_socket', 'generic_socket'];

    /**
     * @param Closure(): float                  $clock         monotonic seconds
     * @param Closure(resource, int, int): bool $timeoutSetter stream_set_timeout() by default
     */
    private function __construct(
        private Closure $clock,
        private float $expiresAt,
        public int $seconds,
        private Closure $timeoutSetter,
    ) {}

    /**
     * @param (Closure(): float)|null                  $clock         monotonic seconds; hrtime() by default
     * @param (Closure(resource, int, int): bool)|null $timeoutSetter test seam observing the transport timeout; native stream_set_timeout() by default
     */
    public static function start(int $seconds, ?Closure $clock = null, ?Closure $timeoutSetter = null): self
    {
        $clock ??= static fn (): float => hrtime(true) / 1_000_000_000;
        $timeoutSetter ??= stream_set_timeout(...);

        return new self($clock, $clock() + max($seconds, 1), max($seconds, 1), $timeoutSetter);
    }

    public function remaining(): float
    {
        return max(0.0, $this->expiresAt - ($this->clock)());
    }

    public function assertOpen(string $uploadId): void
    {
        if ($this->remaining() <= 0.0) {
            throw UploadConflictException::forCompletionDeadline(uploadId: $uploadId, seconds: $this->seconds);
        }
    }

    /**
     * Refuse, BEFORE any staged open, CAS prepare/receipt or S3-proxy assemble, a disk
     * whose blocking calls cannot be bounded by the remaining budget. Flysystem gives
     * remote adapters no per-call timeout, so only local adapters qualify.
     */
    public function preflight(Filesystem $disk, string $uploadId): void
    {
        $this->assertOpen($uploadId);
        $adapter = $disk instanceof FilesystemAdapter ? $disk->getAdapter() : null;
        if (!$adapter instanceof LocalFilesystemAdapter) {
            throw UploadConflictException::forUnboundedStorage(uploadId: $uploadId, stream: $adapter === null ? $disk::class : $adapter::class);
        }
    }

    /**
     * Bound the staged stream: the remaining budget becomes its transport timeout
     * and the deadline is re-checked before every chunk read.
     *
     * @param resource $stream
     */
    public function bound(mixed $stream, string $uploadId): StreamInterface
    {
        $this->assertOpen($uploadId);
        $type = stream_get_meta_data($stream)['stream_type'];
        if (in_array($type, self::SOCKET_TYPES, true)) {
            $this->timeout($stream, $uploadId);
        } elseif (!self::local($stream)) {
            throw UploadConflictException::forUnboundedStorage(uploadId: $uploadId, stream: $type);
        }

        return new DeadlineStream(stream: $stream, deadline: $this, uploadId: $uploadId);
    }

    /**
     * Local file/memory I/O, not a remote transport. A user-space wrapper may hide any
     * remote SDK, so it never counts as local.
     *
     * @param resource $stream
     */
    public static function local(mixed $stream): bool
    {
        $type = stream_get_meta_data($stream)['stream_type'];

        return !in_array($type, self::SOCKET_TYPES, true) && $type !== 'user-space' && stream_is_local($stream);
    }

    /** @param resource $stream */
    public function timeout(mixed $stream, string $uploadId): void
    {
        $left = $this->remaining();
        $seconds = (int) floor($left);
        if (!($this->timeoutSetter)($stream, $seconds, (int) (($left - $seconds) * 1_000_000))) {
            throw UploadConflictException::forUnboundedStorage(uploadId: $uploadId, stream: stream_get_meta_data($stream)['stream_type']);
        }
    }
}
