<?php

declare(strict_types=1);

namespace Vaulter\Media\Encryption;

use Closure;
use Packman\Attributes\Internal;
use RuntimeException;
use SensitiveParameter;

/** A seekable resource over authenticated plaintext. One cached segment serves successive reads. */
#[Internal]
final class EncryptedReadStream
{
    private const string SCHEME = 'vaulter-secretstream';

    /** @var resource PHP populates this property when opening the wrapper. */
    public mixed $context;

    private int $position = 0;

    private int $cachedIndex = -1;

    private string $cachedBytes = '';

    private int $size;

    /** @var Closure(int):string */
    private Closure $segment;

    /** @var Closure():void */
    private Closure $close;

    /**
     * @param  Closure(int):string $segment
     * @param  Closure():void      $close
     * @return resource
     */
    public static function open(int $size, #[SensitiveParameter] Closure $segment, #[SensitiveParameter] Closure $close): mixed
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true) && !stream_wrapper_register(self::SCHEME, self::class)) {
            throw new RuntimeException('Unable to register the encrypted stream wrapper.');
        }
        $stream = fopen(self::SCHEME.'://read', 'rb', false, stream_context_create([self::SCHEME => ['size' => $size, 'segment' => $segment, 'close' => $close]]));

        return $stream === false ? throw new RuntimeException('Unable to open the encrypted stream.') : $stream;
    }

    public function stream_open(): bool
    {
        /** @var array{size:int,segment:Closure(int):string,close:Closure():void} $options */
        $options = stream_context_get_options($this->context)[self::SCHEME];
        $this->size = $options['size'];
        $this->segment = $options['segment'];
        $this->close = $options['close'];
        // Remove the callback from the resource's externally readable context.
        stream_context_set_option($this->context, self::SCHEME, 'segment', null);
        stream_context_set_option($this->context, self::SCHEME, 'close', null);
        if ($this->size === 0) {
            ($this->segment)(0);
        }

        return true;
    }

    public function stream_close(): void
    {
        ($this->close)();
    }

    public function stream_read(int $count): string
    {
        if ($this->stream_eof()) {
            return '';
        }
        $segmentBytes = SegmentedSecretStreamCipher::SEGMENT_BYTES;
        $index = intdiv($this->position, $segmentBytes);
        if ($index !== $this->cachedIndex) {
            $this->cachedBytes = ($this->segment)($index);
            $this->cachedIndex = $index;
        }
        $bytes = substr($this->cachedBytes, $this->position % $segmentBytes, $count);
        $this->position += strlen($bytes);

        return $bytes;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        $position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $this->size + $offset,
            default => -1,
        };
        if ($position < 0 || $position > $this->size) {
            return false;
        }
        $this->position = $position;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_eof(): bool
    {
        return $this->position >= $this->size;
    }

    /** @return array{size:int,7:int} */
    public function stream_stat(): array
    {
        return ['size' => $this->size, 7 => $this->size];
    }
}
