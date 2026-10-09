<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Webhooks\SigningSecretEncryptor;

/**
 * Webhook endpoints for the Standard Webhooks 1.0 delivery (PLAN2.D50, D60):
 *  - previous_signing_secret / previous_secret_expires_at hold the secret before the last
 *    rotation while receivers still switch over.
 *  - failing_since, disabled_at, disabled_reason carry the auto-disable clock and why an
 *    endpoint is off.
 *  - Every signing_secret becomes "whsec_" + base64(old secret): the HMAC key stays the
 *    exact bytes the endpoint signed with, but the string is now what Standard Webhooks
 *    libraries expect. Pre-1.0 was never published, so the signature format change itself
 *    (headers, base64) is a hard switch described in UPGRADE.md.
 */
return new class extends Migration
{
    private const array COLUMNS = ['previous_signing_secret', 'previous_secret_expires_at', 'failing_since', 'disabled_at', 'disabled_reason'];

    public function up(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $table = app(DatabaseConfig::class)->table('webhook_endpoints');
        $schema = $db->getSchemaBuilder();

        if (!$schema->hasTable($table)) {
            return;
        }

        foreach (self::COLUMNS as $column) {
            if ($schema->hasColumn($table, $column)) {
                continue;
            }

            $schema->table($table, static function (Blueprint $blueprint) use ($column): void {
                match ($column) {
                    'previous_signing_secret' => $blueprint->text($column)->nullable(),
                    'disabled_reason' => $blueprint->string($column, 32)->nullable(),
                    default => $blueprint->timestamp($column)->nullable(),
                };
            });
        }

        // One transaction: an undecryptable secret aborts the run with nothing converted.
        app(SigningSecretEncryptor::class)->convertToWebhookSecrets($table);
    }

    public function down(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $table = app(DatabaseConfig::class)->table('webhook_endpoints');

        if (!$db->getSchemaBuilder()->hasTable($table)) {
            return;
        }

        app(SigningSecretEncryptor::class)->revertFromWebhookSecrets($table);
        $this->dropColumns($db, $table, self::COLUMNS);
    }

    /** @param list<string> $columns */
    private function dropColumns(Connection $db, string $table, array $columns): void
    {
        $schema = $db->getSchemaBuilder();
        foreach ($columns as $column) {
            if ($schema->hasColumn($table, $column)) {
                $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
            }
        }
    }
};
