<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Vaulter\Configuration\DatabaseConfig;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(app(DatabaseConfig::class)->connection());

        foreach ($this->tables() as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }

            foreach (['claim_token', 'claim_until'] as $column) {
                if ($schema->hasColumn($table, $column)) {
                    continue;
                }

                $schema->table($table, static function (Blueprint $blueprint) use ($column): void {
                    if ($column === 'claim_token') {
                        $blueprint->string('claim_token', 32)->nullable();
                    } else {
                        $blueprint->timestamp('claim_until')->nullable();
                    }
                });
            }
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(app(DatabaseConfig::class)->connection());

        foreach ($this->tables() as $table) {
            foreach (['claim_token', 'claim_until'] as $column) {
                if ($schema->hasColumn($table, $column)) {
                    $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
                }
            }
        }
    }

    /** @return list<string> */
    private function tables(): array
    {
        return [app(DatabaseConfig::class)->table('webhook_deliveries')];
    }
};
