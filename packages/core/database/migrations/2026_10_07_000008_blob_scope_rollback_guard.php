<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Schema\UpgradePreflightException;
use Vaulter\Support\Identity;

/**
 * Rollback safety for 2026_10_01_000007_blob_scope_key (PLAN2.F09). That migration is
 * frozen, and its down() rebuilds the old unique index over (tenant_id, sha256),
 * (drive_id, sha256) or sha256 alone. With dedup scope "off" every upload is a blob of
 * its own, so equal bytes exist more than once and the old index cannot hold them.
 *
 * This migration is newer, so a rollback runs its down() first: it reads the blobs and,
 * if the old index could not hold them, stops with the list of conflicts before any
 * DDL changes. Nothing is repaired or merged — merging blobs would move CAS edges and
 * payload. up() changes nothing.
 */
return new class extends Migration
{
    private const int LISTED = 20;

    public function up(): void {}

    public function down(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $blobs = app(DatabaseConfig::class)->table('blobs');
        if (!$schema->hasTable($blobs) || !$schema->hasColumn($blobs, 'scope_key')) {
            return;
        }

        // The same key the frozen down() rebuilds.
        $key = match (app()->bound('vaulter.dedup_scope') ? app('vaulter.dedup_scope') : 'tenant') {
            'drive' => ['drive_id', 'sha256'],
            'tenant' => ['tenant_id', 'sha256'],
            default => ['sha256'],
        };

        $query = $db->table($blobs)->select($key)->selectRaw('count(*) as copies')->selectRaw('min(id) as first_id');
        foreach ($key as $column) {
            // A NULL part never collides in a unique index.
            $query->whereNotNull($column);
        }
        $conflicts = $query->groupBy($key)->havingRaw('count(*) > 1')->orderBy('first_id')->limit(self::LISTED + 1)->get();
        if ($conflicts->isEmpty()) {
            return;
        }

        $listed = [];
        foreach ($conflicts->take(self::LISTED) as $conflict) {
            $listed[] = sprintf('sha256 %s held by %d blobs (first id %s)',
                is_string($conflict->sha256) ? substr($conflict->sha256, 0, 12) : '?',
                is_numeric($conflict->copies) ? (int) $conflict->copies : 0,
                Identity::stringify($conflict->first_id));
        }
        throw new UpgradePreflightException([
            'rolling back blob_scope_key cannot keep equal-byte blobs under the old unique index ('.implode(', ', $key).')',
            ...$listed,
            ...($conflicts->count() > self::LISTED ? ['more conflicts follow'] : []),
            'no table was changed; keep the current schema or remove the duplicate blobs through the CAS reconciler first',
        ]);
    }
};
