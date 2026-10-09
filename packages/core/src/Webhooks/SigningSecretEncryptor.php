<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Vaulter\Database\VaulterDatabase;

/**
 * One-shot, IDEMPOTENT migration of webhook signing secrets between plaintext and
 * encrypted-at-rest (F-P1.7-02). A value that already decrypts is left untouched, so
 * re-running encryptPlaintext() is a no-op — the migration (and any repair path) can
 * call it more than once safely.
 *
 * It reads/writes the raw column through the query builder, NOT the Eloquent model,
 * because the model now carries the `encrypted` cast: reading a still-plaintext row
 * through the model would throw a DecryptException on hydration.
 */
final class SigningSecretEncryptor
{
    public function __construct(private readonly VaulterDatabase $database) {}

    /**
     * Encrypt every plaintext signing_secret in $table in place. Returns how many rows
     * were changed — 0 on a table that is already fully encrypted (idempotent).
     */
    public function encryptPlaintext(string $table): int
    {
        $changed = 0;

        $this->database->connection()->table($table)->orderBy('id')->each(function (object $row) use ($table, &$changed): void {
            /** @var array<string, mixed> $data */
            $data = (array) $row;

            $secret = $data['signing_secret'] ?? null;

            if (!is_string($secret) || $secret === '') {
                return;
            }

            try {
                Crypt::decryptString($secret);

                return; // already encrypted — leave it
            } catch (DecryptException) {
                // plaintext — encrypt it below
            }

            $this->database->connection()->table($table)
                ->where('id', $data['id'] ?? null)
                ->update(['signing_secret' => Crypt::encryptString($secret)]);

            $changed++;
        });

        return $changed;
    }

    /**
     * The inverse (migration rollback): decrypt every encrypted signing_secret back to
     * plaintext in place. Idempotent — a value that does not decrypt is already
     * plaintext and is left untouched. Returns how many rows were changed.
     */
    public function decryptToPlaintext(string $table): int
    {
        $changed = 0;

        $this->database->connection()->table($table)->orderBy('id')->each(function (object $row) use ($table, &$changed): void {
            /** @var array<string, mixed> $data */
            $data = (array) $row;

            $secret = $data['signing_secret'] ?? null;

            if (!is_string($secret) || $secret === '') {
                return;
            }

            try {
                $plain = Crypt::decryptString($secret);
            } catch (DecryptException) {
                return; // already plaintext
            }

            $this->database->connection()->table($table)
                ->where('id', $data['id'] ?? null)
                ->update(['signing_secret' => $plain]);

            $changed++;
        });

        return $changed;
    }

    /**
     * Convert every signing_secret that is not yet a whsec_ secret into one whose HMAC
     * key is the old secret's bytes, so receivers that already verify with the old
     * string keep working once they decode the new one. Idempotent; all rows or none —
     * a secret that cannot be decrypted stops the whole conversion (a half-converted
     * table would sign some endpoints with the wrong key).
     *
     * @throws RuntimeException when a stored secret cannot be decrypted with the current APP_KEY
     */
    public function convertToWebhookSecrets(string $table): int
    {
        return $this->database->connection()->transaction(fn (): int => $this->rewriteSecrets(
            table: $table,
            convert: static function (string $plain): ?string {
                if (WebhookSecret::isWebhookSecret($plain)) {
                    return null;
                }

                // Registration only ever produced 64-byte secrets; anything else cannot be
                // used as a 1.0 key, and a silently undeliverable endpoint is worse than a stop.
                $length = strlen($plain);
                if ($length < WebhookSecret::MIN_KEY_BYTES || $length > WebhookSecret::MAX_KEY_BYTES) {
                    throw new RuntimeException('[Vaulter] A stored webhook signing secret is outside the 24 to 64 bytes a Standard Webhooks key may have. Delete or re-register that endpoint, then run the migration again; nothing was changed.');
                }

                return WebhookSecret::fromKeyBytes($plain);
            },
        ));
    }

    /**
     * The inverse of convertToWebhookSecrets() for a rollback: back to the key bytes as
     * the secret. A secret generated natively by 1.0 yields its random bytes, not text.
     *
     * @throws RuntimeException when a stored secret cannot be decrypted with the current APP_KEY
     */
    public function revertFromWebhookSecrets(string $table): int
    {
        return $this->database->connection()->transaction(fn (): int => $this->rewriteSecrets(
            table: $table,
            convert: static fn (string $plain): ?string => WebhookSecret::isWebhookSecret($plain) ? WebhookSecret::keyBytes($plain) : null,
        ));
    }

    /**
     * @param \Closure(string): ?string $convert the new plaintext, or null to leave the row alone
     */
    private function rewriteSecrets(string $table, \Closure $convert): int
    {
        $changed = 0;

        $this->database->connection()->table($table)->orderBy('id')->each(function (object $row) use ($table, $convert, &$changed): void {
            /** @var array<string, mixed> $data */
            $data = (array) $row;

            $secret = $data['signing_secret'] ?? null;

            if (!is_string($secret) || $secret === '') {
                return;
            }

            try {
                $plain = Crypt::decryptString($secret);
            } catch (DecryptException $exception) {
                throw new RuntimeException(
                    sprintf('[Vaulter] Cannot convert the webhook signing secret of endpoint [%s]: it does not decrypt with the current APP_KEY. Restore the APP_KEY the secret was written with, then run the migration again; nothing was changed.', is_scalar($data['id'] ?? null) ? (string) $data['id'] : '?'),
                    previous: $exception,
                );
            }

            $converted = $convert($plain);

            if ($converted === null) {
                return;
            }

            $this->database->connection()->table($table)
                ->where('id', $data['id'] ?? null)
                ->update(['signing_secret' => Crypt::encryptString($converted)]);

            $changed++;
        });

        return $changed;
    }
}
