<?php

declare(strict_types=1);

namespace VaulterRef\Ai\Core\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * BOUNDARY: the host-registered authenticated-encryption codec (plan/spec/database/ai.md: key_id, version, nonce,
 * tag; AAD binds table, row id and scope). XChaCha20-Poly1305 from ext-sodium.
 */
final readonly class SecretBox
{
    public function __construct(public string $keyId, private string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new InvalidArgumentException('A 32-byte key is required.');
        }
    }

    public function seal(string $plaintext, string $aad): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return "\x01".$nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $this->key);
    }

    public function open(string $sealed, string $aad): string
    {
        $nonceBytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($sealed) < 1 + $nonceBytes + 16 || $sealed[0] !== "\x01") {
            throw new RuntimeException('Unknown ciphertext version.');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($sealed, 1 + $nonceBytes), $aad, substr($sealed, 1, $nonceBytes), $this->key);
        if ($plain === false) {
            throw new RuntimeException('Ciphertext does not authenticate for this row and scope.');
        }

        return $plain;
    }
}
