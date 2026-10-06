<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;

return new class extends Migration
{
    public function up(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $name = app(DatabaseConfig::class)->table('blobs');
        if (!$schema->hasTable($name)) {
            return;
        }

        if (!$schema->hasColumn($name, 'scope_key')) {
            $schema->table($name, fn (Blueprint $table) => $table->string('scope_key', 191)->nullable());
        }

        $db->table($name)->whereNull('scope_key')->orderBy('id')->chunkById(100, function ($rows) use ($db, $name): void {
            foreach ($rows as $row) {
                $key = match ($row->dedup_scope) {
                    'global' => 'global',
                    'tenant' => $row->tenant_id === null ? 'legacy:tnull' : 'tenant:'.$this->identifier($row->tenant_id),
                    'storage' => $row->drive_id === null ? 'legacy:snull' : 'drive:'.$this->identifier($row->drive_id),
                    default => throw new RuntimeException('Unknown legacy blob dedup scope.'),
                };
                $db->table($name)->where('id', $row->id)->update(['scope_key' => $key]);
            }
        });

        $columns = array_column($schema->getColumns($name), null, 'name');
        if ($columns['scope_key']['nullable']) {
            $schema->table($name, fn (Blueprint $table) => $table->string('scope_key', 191)->nullable(false)->change());
        }

        if (!$schema->hasIndex($name, $name.'_scope_sha_uniq')) {
            // A conflict fails closed before dropping the historical safety constraint.
            $schema->table($name, fn (Blueprint $table) => $table->unique(['scope_key', 'sha256'], $name.'_scope_sha_uniq'));
        }
        if ($schema->hasIndex($name, $name.'_dedup_uniq')) {
            $schema->table($name, fn (Blueprint $table) => $table->dropUnique($name.'_dedup_uniq'));
        }
    }

    private function identifier(mixed $id): string
    {
        if (!is_string($id) && !is_int($id)) {
            throw new RuntimeException('Invalid legacy blob scope identifier.');
        }

        return (string) $id;
    }

    public function down(): void
    {
        $schema = app(VaulterDatabase::class)->connection()->getSchemaBuilder();
        $name = app(DatabaseConfig::class)->table('blobs');
        if (!$schema->hasTable($name) || !$schema->hasColumn($name, 'scope_key')) {
            return;
        }

        if (!$schema->hasIndex($name, $name.'_dedup_uniq')) {
            $columns = match (app()->bound('vaulter.dedup_scope') ? app('vaulter.dedup_scope') : 'tenant') {
                'drive' => ['drive_id', 'sha256'],
                'tenant' => ['tenant_id', 'sha256'],
                default => ['sha256'],
            };
            $schema->table($name, fn (Blueprint $table) => $table->unique($columns, $name.'_dedup_uniq'));
        }
        if ($schema->hasIndex($name, $name.'_scope_sha_uniq')) {
            $schema->table($name, fn (Blueprint $table) => $table->dropUnique($name.'_scope_sha_uniq'));
        }
        $schema->table($name, fn (Blueprint $table) => $table->dropColumn('scope_key'));
    }
};
