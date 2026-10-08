<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Schema\UpgradePreflightException;
use Vaulter\Schema\HostKeyColumns;
use Vaulter\Support\Identity;

/**
 * The 1.0 access model (PLAN2.D45, D46, D53):
 *  - v_node_grants stores a role (role_key, role_version) instead of
 *    level/permissions/effect; each level becomes the role of the same name, v1;
 *  - deny grants, unknown levels and permission bits beyond the level's own are
 *    refused before anything changes: such a grant has no positive equivalent and
 *    converting it would widen or silently alter access;
 *  - one grant per (node, subject, scope): the effect column leaves the key;
 *  - v_nodes.owner_id goes. No built-in permission driver granted anything from it,
 *    so it becomes no grant.
 *
 * Every step checks the state it leaves behind, so a re-run after a failure (MySQL
 * cannot roll DDL back) finishes the job; a column in a shape this migration never
 * produces stops it with UpgradePreflightException. The backfill runs in batches by
 * id. Definitions are literal: later SchemaBuilder edits never change what this
 * migration did.
 */
return new class extends Migration
{
    private const int BATCH = 500;

    /**
     * The 0.x level bitmasks (READ=1 UPDATE=2 CREATE=4 DELETE=8 SHARE=16 COMMENT=32).
     * A grant converts only when its extra permissions add nothing to its level.
     */
    private const array LEVEL_BITS = [
        'reader' => 1,
        'commenter' => 33,
        'writer' => 39,
        'organizer' => 47,
        'owner' => 63,
    ];

    private const array LEGACY_COLUMNS = ['level', 'permissions', 'effect'];

    /** New ability bit => the legacy bits required to grant it (literal 0.x semantics). */
    private const array SHARE_REQUIREMENTS = [
        1 => 1, 2 => 2, 4 => 4, 8 => 8, 16 => 16, 32 => 32,
        64 => 1, 128 => 6, 256 => 4, 512 => 8, 1024 => 8, 2048 => 63,
    ];

    public function up(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $grants = $this->table('node_grants');
        $nodes = $this->table('nodes');
        $shares = $this->table('node_shares');
        $schema = $db->getSchemaBuilder();

        $upgradeGrants = $schema->hasTable($grants) && !$this->complete($db, $grants, $nodes);
        if ($upgradeGrants) {
            $this->refuseUnknownShapes($db, $grants);
            $this->refuseUnconvertibleGrants($db, $grants);
        }
        $this->refuseShareMasks($db, $shares);

        if ($upgradeGrants) {
            $this->addRoleColumns($db, $grants);
            $this->backfillRoles($db, $grants);
            $this->dropLegacyGrantColumns($db, $grants);
            $this->createGrantUnique($db, $grants);
        }
        $this->upgradeShareMasks($db, $shares);
        $this->dropNodeOwner($db, $nodes);
    }

    public function down(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $grants = $this->table('node_grants');
        $nodes = $this->table('nodes');
        $shares = $this->table('node_shares');

        // A fresh install created the 1.0 shape in the base shells, which drop it.
        if ($this->freshInstall()) {
            return;
        }

        $downgradeGrants = $schema->hasTable($grants) && $schema->hasColumn($grants, 'role_key');
        if ($downgradeGrants) {
            $previewers = $db->table($grants)->whereNotIn('role_key', array_keys(self::LEVEL_BITS))->orderBy('id')->limit(50)->pluck('id')
                ->map(fn (mixed $id): string => Identity::stringify($this->key($id)))->all();
            if ($previewers !== []) {
                throw new UpgradePreflightException(['grants ['.implode(', ', $previewers).'] hold roles without a 0.x level; revoke them before rolling back']);
            }
        }
        $this->refuseShareMasks($db, $shares, rollback: true);

        if ($downgradeGrants) {
            if (!$schema->hasColumn($grants, 'level')) {
                $schema->table($grants, static function (Blueprint $table): void {
                    $table->string('level', 16)->default('');
                    $table->unsignedInteger('permissions')->default(0);
                    $table->string('effect', 5)->default('allow');
                });
            }
            $db->table($grants)->update(['level' => $db->raw('role_key')]);

            if ($schema->hasIndex($grants, $grants.'_grant_uniq')) {
                $schema->table($grants, static fn (Blueprint $table) => $table->dropUnique($grants.'_grant_uniq'));
            }
            $schema->table($grants, static function (Blueprint $table) use ($grants): void {
                $table->dropColumn(['role_key', 'role_version']);
                $table->index('level', $grants.'_level_index');
                $table->index('effect', $grants.'_effect_index');
                $table->index(['node_id', 'effect'], $grants.'_node_effect_idx');
                $table->unique(['node_id', 'subject_type', 'subject_id', 'scope', 'effect'], $grants.'_grant_uniq');
            });
        }
        $this->downgradeShareMasks($db, $shares);

        if (!$schema->hasColumn($nodes, 'owner_id')) {
            $schema->table($nodes, static fn (Blueprint $table) => HostKeyColumns::add(table: $table, column: 'owner_id', nullable: true, index: false));
        }
    }

    /** The 1.0 shape is already in place (a fresh install, or a finished run). */
    private function complete(Connection $db, string $grants, string $nodes): bool
    {
        $schema = $db->getSchemaBuilder();
        $columns = $schema->getColumnListing($grants);

        return in_array('role_key', $columns, true)
            && in_array('role_version', $columns, true)
            && array_intersect(self::LEGACY_COLUMNS, $columns) === []
            && $this->hasGrantUnique($db, $grants)
            && !$schema->hasColumn($nodes, 'owner_id');
    }

    /**
     * Columns this migration adds must either be absent or exactly what it creates;
     * anything else was not made by Vaulter and is not overwritten.
     */
    private function refuseUnknownShapes(Connection $db, string $grants): void
    {
        $columns = array_column($db->getSchemaBuilder()->getColumns($grants), null, 'name');
        $problems = [];
        if (isset($columns['role_key']) && !in_array(strtolower($columns['role_key']['type_name']), ['varchar', 'character varying', 'text'], true)) {
            $problems[] = $grants.'.role_key has type '.$columns['role_key']['type_name'];
        }
        if (isset($columns['role_version']) && !in_array(strtolower($columns['role_version']['type_name']), ['smallint', 'int2', 'integer', 'int'], true)) {
            $problems[] = $grants.'.role_version has type '.$columns['role_version']['type_name'];
        }
        // The first ALTER may have committed before role_version was added on MySQL.
        $partialRoleKey = isset($columns['role_key']) && !isset($columns['role_version'])
            && !$columns['role_key']['nullable']
            && in_array($columns['role_key']['default'], ['', "''", "''::character varying"], true)
            && array_diff(self::LEGACY_COLUMNS, array_keys($columns)) === [];
        if (isset($columns['role_version']) !== isset($columns['role_key']) && !$partialRoleKey) {
            $problems[] = $grants.' has only one of role_key/role_version';
        }
        if (!isset($columns['role_key']) && array_diff(self::LEGACY_COLUMNS, array_keys($columns)) !== []) {
            $problems[] = $grants.' lacks ['.implode(', ', array_diff(self::LEGACY_COLUMNS, array_keys($columns))).'] and has no role_key';
        }

        if ($problems !== []) {
            throw new UpgradePreflightException($problems);
        }
    }

    /**
     * Deny grants, unknown levels and extra permission bits have no positive role
     * that keeps access exactly as it is; list them (at most 50) and change nothing.
     */
    private function refuseUnconvertibleGrants(Connection $db, string $grants): void
    {
        if (!$db->getSchemaBuilder()->hasColumn($grants, 'level')) {
            return;
        }

        $refused = [];
        $last = null;
        do {
            $rows = $db->table($grants)
                ->when($last !== null, fn ($query) => $query->where('id', '>', $last))
                ->orderBy('id')->limit(self::BATCH)
                ->get(['id', 'node_id', 'subject_type', 'subject_id', 'level', 'permissions', 'effect']);
            foreach ($rows as $row) {
                $last = $row->id;
                $reason = $this->unconvertible($row);
                if ($reason !== null) {
                    $refused[] = sprintf('grant %s (node %s, subject %s:%s) %s', Identity::stringify($this->key($row->id)),
                        Identity::stringify($this->key($row->node_id)), Identity::stringify($this->key($row->subject_type)), Identity::stringify($this->key($row->subject_id)), $reason);
                }
            }
        } while ($rows->count() === self::BATCH && count($refused) < 50);

        if ($refused !== []) {
            throw new UpgradePreflightException(['the 1.0 access model has no deny grants and no custom permission bits; these grants have no exact role: '
                .implode('; ', $refused).'. Replace a deny with an inheritance boundary (access()->restrictInheritance()) or remove the extra bits, then retry']);
        }
    }

    private function unconvertible(stdClass $row): ?string
    {
        $effect = is_string($row->effect) ? $row->effect : '';
        if ($effect !== 'allow') {
            return 'is a '.($effect === '' ? 'non-allow' : $effect).' grant';
        }
        $level = is_string($row->level) ? $row->level : '';
        if (!isset(self::LEVEL_BITS[$level])) {
            return 'has the unknown level '.$level;
        }
        $permissions = is_numeric($row->permissions) ? (int) $row->permissions : 0;
        if (($permissions & ~self::LEVEL_BITS[$level]) !== 0) {
            return 'adds permission bits '.($permissions & ~self::LEVEL_BITS[$level]).' to '.$level;
        }

        return null;
    }

    private function addRoleColumns(Connection $db, string $grants): void
    {
        $schema = $db->getSchemaBuilder();
        if (!$schema->hasColumn($grants, 'role_key')) {
            // NOT NULL from the start; the empty default marks rows the backfill has
            // not reached and is dropped afterwards.
            $grammar = $db->getQueryGrammar();
            $db->statement('ALTER TABLE '.$grammar->wrapTable($grants).' ADD COLUMN '.$grammar->wrap('role_key')." VARCHAR(32) NOT NULL DEFAULT ''");
        }
        if (!$schema->hasColumn($grants, 'role_version')) {
            $schema->table($grants, static fn (Blueprint $table) => $table->smallInteger('role_version')->default(1));
        }
    }

    private function backfillRoles(Connection $db, string $grants): void
    {
        if ($db->getSchemaBuilder()->hasColumn($grants, 'level')) {
            do {
                $ids = $db->table($grants)->where('role_key', '')->orderBy('id')->limit(self::BATCH)->pluck('id')->all();
                if ($ids !== []) {
                    $db->table($grants)->whereIn('id', $ids)->update([
                        'role_key' => $db->raw('level'),
                        'role_version' => 1,
                    ]);
                }
            } while (count($ids) === self::BATCH);
        }

        if ($db->table($grants)->where('role_key', '')->exists()) {
            throw new UpgradePreflightException([$grants.' has grants without a role and without a level to convert']);
        }

        if ($db->getDriverName() !== 'sqlite') {
            $grammar = $db->getQueryGrammar();
            $db->statement('ALTER TABLE '.$grammar->wrapTable($grants).' ALTER COLUMN '.$grammar->wrap('role_key').' DROP DEFAULT');
        }
    }

    private function dropLegacyGrantColumns(Connection $db, string $grants): void
    {
        $schema = $db->getSchemaBuilder();
        $present = array_values(array_intersect(self::LEGACY_COLUMNS, $schema->getColumnListing($grants)));
        if ($present === []) {
            return;
        }
        $this->dropIndexesOn($db, $grants, $present);
        $schema->table($grants, static fn (Blueprint $table) => $table->dropColumn($present));
    }

    private function createGrantUnique(Connection $db, string $grants): void
    {
        if ($this->hasGrantUnique($db, $grants)) {
            return;
        }
        $schema = $db->getSchemaBuilder();
        if ($schema->hasIndex($grants, $grants.'_grant_uniq')) {
            $schema->table($grants, static fn (Blueprint $table) => $table->dropUnique($grants.'_grant_uniq'));
        }
        $schema->table($grants, static fn (Blueprint $table) => $table->unique(['node_id', 'subject_type', 'subject_id', 'scope'], $grants.'_grant_uniq'));
    }

    private function hasGrantUnique(Connection $db, string $grants): bool
    {
        foreach ($db->getSchemaBuilder()->getIndexes($grants) as $index) {
            if ($index['name'] === $grants.'_grant_uniq') {
                return $index['unique'] && $index['columns'] === ['node_id', 'subject_type', 'subject_id', 'scope'];
            }
        }

        return false;
    }

    private function dropNodeOwner(Connection $db, string $nodes): void
    {
        $schema = $db->getSchemaBuilder();
        if (!$schema->hasColumn($nodes, 'owner_id')) {
            return;
        }
        $this->dropIndexesOn($db, $nodes, ['owner_id']);
        $schema->table($nodes, static fn (Blueprint $table) => $table->dropColumn('owner_id'));
    }

    /** Refuse unsupported masks before any grant, share or node DDL changes. */
    private function refuseShareMasks(Connection $db, string $shares, bool $rollback = false): void
    {
        $schema = $db->getSchemaBuilder();
        // Share links 1.0 (2026_10_07_000006) store a role, not a mask: nothing to check.
        if (!$schema->hasTable($shares) || !$schema->hasColumn($shares, 'permissions')) {
            return;
        }
        $columns = array_column($schema->getColumns($shares), null, 'name');
        $versioned = isset($columns['permissions_version']);
        if ($versioned && (!in_array(strtolower($columns['permissions_version']['type_name']), ['smallint', 'int2', 'integer', 'int'], true)
            || $columns['permissions_version']['nullable'])) {
            throw new UpgradePreflightException([$shares.'.permissions_version has an unknown shape']);
        }
        $last = null;
        $refused = [];
        do {
            $rows = $db->table($shares)->when($last !== null, fn ($query) => $query->where('id', '>', $last))
                ->orderBy('id')->limit(self::BATCH)->get($versioned ? ['id', 'permissions', 'permissions_version'] : ['id', 'permissions']);
            foreach ($rows as $row) {
                $last = $row->id;
                $storedVersion = $versioned ? $row->permissions_version : 0;
                $storedBits = $row->permissions;
                if (!is_numeric($storedVersion) || !is_numeric($storedBits)) {
                    $refused[] = Identity::stringify($this->key($row->id));

                    continue;
                }
                $version = (int) $storedVersion;
                $bits = (int) $storedBits;
                if (!in_array($version, [0, 1], true) || $bits < 0 || ($bits & ~($version === 0 ? 63 : 4095)) !== 0
                    || ($rollback && $version === 1 && $this->shareBits($bits & 63) !== $bits)) {
                    $refused[] = Identity::stringify($this->key($row->id));
                }
            }
        } while ($rows->count() === self::BATCH && count($refused) < 50);
        if ($refused !== []) {
            throw new UpgradePreflightException(['shares ['.implode(', ', $refused).'] hold permission masks without an exact '.($rollback ? '0.x' : '1.0').' equivalent']);
        }
    }

    private function upgradeShareMasks(Connection $db, string $shares): void
    {
        $schema = $db->getSchemaBuilder();
        if (!$schema->hasTable($shares) || !$schema->hasColumn($shares, 'permissions')) {
            return;
        }
        if (!$schema->hasColumn($shares, 'permissions_version')) {
            $schema->table($shares, static fn (Blueprint $table) => $table->smallInteger('permissions_version')->default(0));
        }
        // The six legacy bits keep their meaning; expand their implied abilities.
        $permissions = $db->raw('permissions
            + CASE WHEN (permissions & 1) = 1 THEN 64 ELSE 0 END
            + CASE WHEN (permissions & 6) = 6 THEN 128 ELSE 0 END
            + CASE WHEN (permissions & 4) = 4 THEN 256 ELSE 0 END
            + CASE WHEN (permissions & 8) = 8 THEN 1536 ELSE 0 END
            + CASE WHEN (permissions & 63) = 63 THEN 2048 ELSE 0 END');
        do {
            $ids = $db->table($shares)->where('permissions_version', 0)->orderBy('id')->limit(self::BATCH)->pluck('id')->all();
            if ($ids !== []) {
                // Mask and marker change in the same UPDATE, so a committed batch is never converted twice.
                $db->table($shares)->whereIn('id', $ids)->update(['permissions' => $permissions, 'permissions_version' => 1]);
            }
        } while (count($ids) === self::BATCH);

        $columns = array_column($schema->getColumns($shares), null, 'name');
        $versionDefault = $columns['permissions_version']['default'];
        $permissionsDefault = $columns['permissions']['default'];
        if (!is_numeric($versionDefault) || (int) $versionDefault !== 1
            || !is_numeric($permissionsDefault) || (int) $permissionsDefault !== 65) {
            $schema->table($shares, static function (Blueprint $table): void {
                $table->smallInteger('permissions_version')->default(1)->change();
                $table->unsignedInteger('permissions')->default(65)->change();
            });
        }
    }

    private function downgradeShareMasks(Connection $db, string $shares): void
    {
        $schema = $db->getSchemaBuilder();
        if (!$schema->hasTable($shares) || !$schema->hasColumn($shares, 'permissions_version')) {
            return;
        }
        do {
            $ids = $db->table($shares)->where('permissions_version', 1)->orderBy('id')->limit(self::BATCH)->pluck('id')->all();
            if ($ids !== []) {
                $db->table($shares)->whereIn('id', $ids)->update(['permissions' => $db->raw('permissions & 63'), 'permissions_version' => 0]);
            }
        } while (count($ids) === self::BATCH);
        $schema->table($shares, static function (Blueprint $table): void {
            $table->unsignedInteger('permissions')->default(1)->change();
            $table->dropColumn('permissions_version');
        });
    }

    private function shareBits(int $legacy): int
    {
        $bits = 0;
        foreach (self::SHARE_REQUIREMENTS as $bit => $required) {
            if (($legacy & $required) === $required) {
                $bits |= $bit;
            }
        }

        return $bits;
    }

    /**
     * Drop the indexes that cover any of the columns (SQLite cannot drop an indexed
     * column).
     *
     * @param list<string> $columns
     */
    private function dropIndexesOn(Connection $db, string $table, array $columns): void
    {
        $schema = $db->getSchemaBuilder();
        foreach ($schema->getIndexes($table) as $index) {
            if ($index['primary'] || array_intersect($columns, $index['columns']) === []) {
                continue;
            }
            $schema->table($table, static fn (Blueprint $blueprint) => $index['unique'] ? $blueprint->dropUnique($index['name']) : $blueprint->dropIndex($index['name']));
        }
    }

    private function table(string $entity): string
    {
        return app(DatabaseConfig::class)->table($entity);
    }

    private function key(mixed $value): int|string
    {
        return is_int($value) || is_string($value) ? $value : (string) json_encode($value);
    }

    private function freshInstall(): bool
    {
        $db = app(VaulterDatabase::class)->connection();
        if (!$db->getSchemaBuilder()->hasTable('migrations')) {
            return true;
        }

        $base = $db->table('migrations')->where('migration', '2026_06_13_000007_create_v_storages_table')->value('batch');
        $upgrade = $db->table('migrations')->where('migration', '2026_10_07_000005_access_model_v1')->value('batch');

        return $base !== null && $base === $upgrade;
    }
};
