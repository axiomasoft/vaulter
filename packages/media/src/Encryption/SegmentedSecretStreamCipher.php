<?php

declare(strict_types=1);

namespace Vaulter\Media\Encryption;

use Packman\Attributes\Internal;
use RuntimeException;
use SensitiveParameter;
use SodiumException;

#[Internal]
final class SegmentedSecretStreamCipher
{
    public const int SEGMENT_BYTES = 4_194_304;

    public const int OVERHEAD = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

    /**
     * @param resource $plaintext
     * @param resource $ciphertext
     */
    public function encrypt(mixed $plaintext, mixed $ciphertext, string $prefix, int $size, string $identity, #[SensitiveParameter] string $dek): void
    {
        self::write($ciphertext, $prefix);
        for ($index = 0, $offset = 0; $offset < $size || $index === 0; $index++, $offset += self::SEGMENT_BYTES) {
            $length = min(self::SEGMENT_BYTES, $size - $offset);
            $bytes = $length === 0 ? '' : stream_get_contents($plaintext, $length);
            if ($bytes === false || strlen($bytes) !== $length) {
                throw new RuntimeException('The staged plaintext was truncated.');
            }
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($dek);
            if (!is_string($state) || !is_string($header)) {
                throw new RuntimeException('Secretstream returned an invalid encryption state.');
            }
            try {
                self::write($ciphertext, $header.sodium_crypto_secretstream_xchacha20poly1305_push($state, $bytes, $identity.pack('J', $index), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL));
            } finally {
                sodium_memzero($state);
            }
        }
    }

    public function decrypt(string $record, int $length, string $identity, int $index, #[SensitiveParameter] string $dek): string
    {
        if (strlen($record) !== $length + self::OVERHEAD) {
            throw new InvalidEncryptedSegment('Encrypted segment authentication failed: incorrect record length.');
        }
        $headerBytes = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($record, 0, $headerBytes), $dek);
        try {
            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, substr($record, $headerBytes), $identity.pack('J', $index));
            if ($result === false || !is_string($result[0]) || $result[1] !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                throw new InvalidEncryptedSegment('Encrypted segment authentication failed.');
            }

            return $result[0];
        } catch (SodiumException $exception) {
            throw new InvalidEncryptedSegment('Encrypted segment authentication failed.', previous: $exception);
        } finally {
            sodium_memzero($state);
        }
    }

    /** @param resource $stream */
    private static function write(mixed $stream, string $bytes): void
    {
        for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += $written) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Writing encrypted bytes failed.');
            }
        }
    }
}
