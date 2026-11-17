<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Packman\Attributes\Internal;
use Throwable;

/**
 * A copy of the bytes a scan actually read, with the digest of that copy.
 * Blob streams are not rewound, so the scanner is handed this copy and never the row hash.
 */
#[Internal]
final readonly class VerifiedScanStream
{
    /** @param resource $stream */
    public function __construct(public mixed $stream, public string $sha256, public int $size) {}

    /**
     * Read $source to the end. Null means the read made no progress; the caller retries.
     * A finished read that does not match the row is still returned, so the caller can reject it.
     *
     * @param resource $source
     */
    public static function copy(mixed $source): ?self
    {
        if (!is_resource($source)) {
            return null;
        }
        $copy = fopen('php://temp', 'w+b');
        if ($copy === false) {
            return null;
        }
        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (!feof($source)) {
                $chunk = fread($source, 1024 * 1024);
                if ($chunk === false || ($chunk === '' && !feof($source))) {
                    fclose($copy);

                    return null;
                }
                if ($chunk === '') {
                    break;
                }
                hash_update($hash, $chunk);
                $written = 0;
                $length = strlen($chunk);
                while ($written < $length) {
                    $out = fwrite($copy, substr($chunk, $written));
                    if ($out === false || $out === 0) {
                        fclose($copy);

                        return null;
                    }
                    $written += $out;
                }
                $size += $length;
            }
            if (!rewind($copy)) {
                fclose($copy);

                return null;
            }
        } catch (Throwable) {
            if (is_resource($copy)) {
                fclose($copy);
            }

            return null;
        }

        return new self(stream: $copy, sha256: hash_final($hash), size: $size);
    }
}
