<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
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
}
