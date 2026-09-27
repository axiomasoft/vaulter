<?php

declare(strict_types=1);

namespace Vaulter\Support;

/**
 * Bounded `size_bucket` labels for multipart assembly metrics (VLT-LOAD §9).
 */
final class AssembleSizeBucket
{
    private const int BYTES_8_MIB = 8 * 1024 * 1024;

    private const int BYTES_64_MIB = 64 * 1024 * 1024;

    private const int BYTES_512_MIB = 512 * 1024 * 1024;

    public static function forBytes(int $bytes): string
    {
        if ($bytes <= self::BYTES_8_MIB) {
            return 'le_8mib';
        }

        if ($bytes <= self::BYTES_64_MIB) {
            return 'le_64mib';
        }

        if ($bytes <= self::BYTES_512_MIB) {
            return 'le_512mib';
        }

        return 'le_5gib';
    }
}
