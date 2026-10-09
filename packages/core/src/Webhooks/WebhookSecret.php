<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use InvalidArgumentException;

/**
 * The Standard Webhooks signing secret: "whsec_" followed by the base64 of the HMAC key.
 * Receivers (and the official libraries) decode the part after the prefix, so the
 * key bytes — not the printable string — are what signs a delivery.
 */
final class WebhookSecret
{
    public const string PREFIX = 'whsec_';

    public const int MIN_KEY_BYTES = 24;

    public const int MAX_KEY_BYTES = 64;

    private const int GENERATED_KEY_BYTES = 32;

    public static function generate(): string
    {
        return self::fromKeyBytes(random_bytes(self::GENERATED_KEY_BYTES));
    }

    /**
     * Wrap raw key bytes as a secret. The upgrade of a pre-1.0 secret uses this so the
     * HMAC key stays byte-identical to what the endpoint already signed with.
     */
    public static function fromKeyBytes(string $bytes): string
    {
        return self::PREFIX.base64_encode($bytes);
    }

    public static function isWebhookSecret(string $secret): bool
    {
        return str_starts_with($secret, self::PREFIX);
    }

    /**
     * The HMAC key bytes behind a secret.
     *
     * @throws InvalidArgumentException when the secret is not whsec_ + base64 of 24..64 bytes
     */
    public static function keyBytes(string $secret): string
    {
        $encoded = str_starts_with($secret, self::PREFIX) ? substr($secret, strlen(self::PREFIX)) : '';
        $bytes = $encoded === '' ? false : base64_decode($encoded, true);

        if ($bytes === false || strlen($bytes) < self::MIN_KEY_BYTES || strlen($bytes) > self::MAX_KEY_BYTES) {
            // The secret is never echoed: it would end up in logs and last_error.
            throw new InvalidArgumentException('A webhook signing secret must be "whsec_" followed by the base64 of 24 to 64 key bytes.');
        }

        return $bytes;
    }
}
