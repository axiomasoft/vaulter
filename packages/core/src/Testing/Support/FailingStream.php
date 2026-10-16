<?php

declare(strict_types=1);

namespace Vaulter\Testing\Support;

use Vaulter\Attributes\Internal;

/**
 * A stream wrapper whose reads always fail, to prove that a scanner never reports clean
 * for bytes it could not read.
 */
#[Internal]
final class FailingStream
{
    public const string SCHEME = 'vaulter-failing';

    /** @var resource|null */
    public $context;

    /** @return resource */
    public static function open()
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }

        return fopen(self::SCHEME.'://bytes', 'rb') ?: throw new \RuntimeException('The failing stream could not be opened.');
    }

    /** Take the wrapper out again, once the stream is closed. */
    public static function release(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): false
    {
        return false;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['size' => 16];
    }
}
