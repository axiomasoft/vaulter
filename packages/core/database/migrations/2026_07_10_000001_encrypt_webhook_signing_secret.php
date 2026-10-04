<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Vaulter\Support\Config;
use Vaulter\Webhooks\SigningSecretEncryptor;

/**
 * Encrypt the webhook signing_secret at rest (F-P1.7-02). Two idempotent steps:
 *   1. widen the column to text (the ciphertext is longer than the 64-char plaintext);
 *   2. encrypt any existing plaintext secret in place — a value that already decrypts
 *      is left untouched, so re-running is a no-op.
 *
 * Fresh installs already get a text column (SchemaBuilder) and have no plaintext
 * rows, so both steps are no-ops there; the migration exists for a deployment that ran
 * the original string version. Gated on the 'reliability' feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Config::featureEnabled('reliability')) {
            return;
        }

        $table = Config::table('webhook_endpoints');

        if (!Schema::hasTable($table)) {
            return;
        }

        if (Schema::getColumnType($table, 'signing_secret') !== 'text') {
            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->text('signing_secret')->change();
            });
        }

        app(SigningSecretEncryptor::class)->encryptPlaintext($table);
    }

    public function down(): void
    {
        if (!Config::featureEnabled('reliability')) {
            return;
        }

        $table = Config::table('webhook_endpoints');

        if (!Schema::hasTable($table)) {
            return;
        }

        app(SigningSecretEncryptor::class)->decryptToPlaintext($table);

        Schema::table($table, static function (Blueprint $blueprint): void {
            $blueprint->string('signing_secret')->change();
        });
    }
};
