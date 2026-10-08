<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Schema\UpgradePreflightException;
use Vaulter\Support\Identity;

/**
 * Share links 1.0 (PLAN2.D47): a link stores the role it grants (role_key,
 * role_version), who may open it (audience) and the generation of its token
 * (token_generation) instead of an ability mask, a level and the require_login and
 * allow_discovery flags.
 *
 * A mask converts only when it is exactly the abilities of a role a link may carry:
 *   current masks   65 reader, 97 commenter, 1 previewer (view without download)
 *   0.x masks       1 reader (a 0.x read included the download), 33 commenter
 * require_login = true becomes the audience "authenticated", otherwise "anyone".
 * Any other mask would have to be rounded to a role, which widens or narrows access
 * silently, so the migration lists such links (at most 50) and changes nothing.
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

    private const array LEGACY_COLUMNS = ['permissions', 'permissions_version', 'level', 'require_login', 'allow_discovery'];

    /** Mask => role key, for masks with permissions_version = 1 (the abilities of PLAN2.D45). */
    private const array CURRENT_MASKS = [1 => 'previewer', 65 => 'reader', 97 => 'commenter'];

    /** Mask => role key, for masks with permissions_version = 0 or no version (0.x). */
    private const array LEGACY_MASKS = [1 => 'reader', 33 => 'commenter'];

    /** Role key => the ability mask that role had in the schema this migration replaces. */
    private const array ROLE_MASKS = ['previewer' => 1, 'reader' => 65, 'commenter' => 97, 'writer' => 2023];

    public function up(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $shares = $this->table('node_shares');
        $schema = $db->getSchemaBuilder();

        if (!$schema->hasTable($shares) || $this->complete($db, $shares)) {
            return;
        }

        $this->refuseUnknownShapes($db, $shares);
        $this->refuseUnconvertibleShares($db, $shares);

        $this->addColumns($db, $shares);
        $this->backfill($db, $shares);
        $this->dropLegacyColumns($db, $shares);
    }

    public function down(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $shares = $this->table('node_shares');

        // A fresh install created the 1.0 shape in the base shells, which drop it.
        if ($this->freshInstall() || !$schema->hasTable($shares) || !$schema->hasColumn($shares, 'role_key')) {
            return;
        }

        $this->refuseUnrestorable($db, $shares);

        if (!$schema->hasColumn($shares, 'permissions')) {
            $schema->table($shares, static function (Blueprint $table): void {
                $table->unsignedInteger('permissions')->default(65);
                $table->smallInteger('permissions_version')->default(1);
                $table->string('level', 16)->default('reader');
                $table->boolean('require_login')->default(false);
                $table->boolean('allow_discovery')->default(false);
            });
        }
        foreach (self::ROLE_MASKS as $role => $mask) {
            $db->table($shares)->where('role_key', $role)->update(['permissions' => $mask, 'permissions_version' => 1, 'level' => $role]);
        }
        $db->table($shares)->where('audience', 'authenticated')->update(['require_login' => true]);

        $schema->table($shares, static fn (Blueprint $table) => $table->dropColumn(['role_key', 'role_version', 'audience', 'token_generation']));
    }

    /** The 1.0 shape is already in place (a fresh install, or a finished run). */
    private function complete(Connection $db, string $shares): bool
    {
        $columns = $db->getSchemaBuilder()->getColumnListing($shares);

        return array_diff(['role_key', 'role_version', 'audience', 'token_generation'], $columns) === []
            && array_intersect(self::LEGACY_COLUMNS, $columns) === [];
    }

    /**
     * Columns this migration adds must either be absent or exactly what it creates;
     * anything else was not made by Vaulter and is not overwritten.
     */
    private function refuseUnknownShapes(Connection $db, string $shares): void
    {
        $columns = array_column($db->getSchemaBuilder()->getColumns($shares), null, 'name');
        $problems = [];
        $types = [
            'role_key' => ['varchar', 'character varying', 'text'],
            'role_version' => ['smallint', 'int2', 'integer', 'int'],
            'audience' => ['varchar', 'character varying', 'text'],
            'token_generation' => ['integer', 'int', 'int4', 'smallint', 'int2', 'bigint', 'int8'],
        ];
        foreach ($types as $name => $accepted) {
            if (isset($columns[$name]) && !in_array(strtolower($columns[$name]['type_name']), $accepted, true)) {
                $problems[] = $shares.'.'.$name.' has type '.$columns[$name]['type_name'];
            }
        }
        if (!isset($columns['role_key']) && !isset($columns['permissions'])) {
            $problems[] = $shares.' has neither role_key nor permissions to convert';
        }
        if ($problems !== []) {
            throw new UpgradePreflightException($problems);
        }
    }

    /**
     * Links whose mask is not exactly a role a link may carry would change access if
     * rounded; list them (at most 50) and change nothing.
     */
    private function refuseUnconvertibleShares(Connection $db, string $shares): void
    {
        $schema = $db->getSchemaBuilder();
        if (!$schema->hasColumn($shares, 'permissions')) {
            return;
        }

        $refused = [];
        $last = null;
        do {
            $rows = $this->pending($db, $shares)
                ->when($last !== null, fn ($query) => $query->where('id', '>', $last))
                ->orderBy('id')->limit(self::BATCH)->get($this->legacyColumns($db, $shares));
            foreach ($rows as $row) {
                $last = $row->id;
                if ($this->roleFor($row) === null) {
                    $refused[] = sprintf('share %s (mask %s)', Identity::stringify($this->key($row->id)), is_scalar($row->permissions) ? (string) $row->permissions : '?');
                }
            }
        } while ($rows->count() === self::BATCH && count($refused) < 50);

        if ($refused !== []) {
            throw new UpgradePreflightException(['share links carry a role, and these links have no role with exactly their abilities: '
                .implode('; ', $refused).'. Revoke them or recreate them with a role, then retry']);
        }
    }

    private function addColumns(Connection $db, string $shares): void
    {
        $schema = $db->getSchemaBuilder();
        if (!$schema->hasColumn($shares, 'role_key')) {
            // NOT NULL from the start; the empty default marks rows the backfill has
            // not reached and is dropped afterwards.
            $grammar = $db->getQueryGrammar();
            $db->statement('ALTER TABLE '.$grammar->wrapTable($shares).' ADD COLUMN '.$grammar->wrap('role_key')." VARCHAR(32) NOT NULL DEFAULT ''");
        }
        foreach ([
            'role_version' => static fn (Blueprint $table) => $table->smallInteger('role_version')->default(1),
            'audience' => static fn (Blueprint $table) => $table->string('audience', 16)->default('anyone'),
            'token_generation' => static fn (Blueprint $table) => $table->unsignedInteger('token_generation')->default(1),
        ] as $column => $add) {
            if (!$schema->hasColumn($shares, $column)) {
                $schema->table($shares, $add);
            }
        }
    }

    private function backfill(Connection $db, string $shares): void
    {
        if ($db->getSchemaBuilder()->hasColumn($shares, 'permissions')) {
            do {
                $rows = $db->table($shares)->where('role_key', '')->orderBy('id')->limit(self::BATCH)->get($this->legacyColumns($db, $shares));
                /** @var array<string, list<mixed>> $groups */
                $groups = [];
                foreach ($rows as $row) {
                    $role = $this->roleFor($row) ?? throw new UpgradePreflightException(['share '.Identity::stringify($this->key($row->id)).' has no exact role']);
                    $audience = $this->truthy($row->require_login ?? false) ? 'authenticated' : 'anyone';
                    $groups[$role."\0".$audience][] = $row->id;
                }
                foreach ($groups as $group => $ids) {
                    [$role, $audience] = explode("\0", $group);
                    $db->table($shares)->whereIn('id', $ids)->update(['role_key' => $role, 'role_version' => 1, 'audience' => $audience]);
                }
            } while ($rows->count() === self::BATCH);
        }

        if ($db->table($shares)->where('role_key', '')->exists()) {
            throw new UpgradePreflightException([$shares.' has links without a role and without a mask to convert']);
        }

        if ($db->getDriverName() !== 'sqlite') {
            $grammar = $db->getQueryGrammar();
            $db->statement('ALTER TABLE '.$grammar->wrapTable($shares).' ALTER COLUMN '.$grammar->wrap('role_key').' DROP DEFAULT');
        }
    }

    private function dropLegacyColumns(Connection $db, string $shares): void
    {
        $schema = $db->getSchemaBuilder();
        $present = array_values(array_intersect(self::LEGACY_COLUMNS, $schema->getColumnListing($shares)));
        if ($present === []) {
            return;
        }
        $this->dropIndexesOn($db, $shares, $present);
        $schema->table($shares, static fn (Blueprint $table) => $table->dropColumn($present));
    }

    /** A role the previous schema cannot express (tenant audience, a role version it never had) is refused. */
    private function refuseUnrestorable(Connection $db, string $shares): void
    {
        $refused = $db->table($shares)
            ->where(static function (Builder $query): void {
                $query->where('audience', 'tenant')
                    ->orWhere('role_version', '<>', 1)
                    ->orWhereNotIn('role_key', array_keys(self::ROLE_MASKS));
            })
            ->orderBy('id')->limit(50)->pluck('id')
            ->map(fn (mixed $id): string => Identity::stringify($this->key($id)))->all();
        if ($refused !== []) {
            throw new UpgradePreflightException(['shares ['.implode(', ', $refused).'] use the tenant audience or a role the previous schema cannot hold; revoke them before rolling back']);
        }
    }

    /** Links the backfill has not reached: the whole table before the column exists. */
    private function pending(Connection $db, string $shares): Builder
    {
        $query = $db->table($shares);

        return $db->getSchemaBuilder()->hasColumn($shares, 'role_key') ? $query->where('role_key', '') : $query;
    }

    /** @return list<string> */
    private function legacyColumns(Connection $db, string $shares): array
    {
        $schema = $db->getSchemaBuilder();

        return array_values(array_filter(['id', 'permissions', 'permissions_version', 'require_login'], static fn (string $column): bool => $column === 'id' || $schema->hasColumn($shares, $column)));
    }

    private function roleFor(stdClass $row): ?string
    {
        if (!is_numeric($row->permissions ?? null)) {
            return null;
        }
        $mask = (int) $row->permissions;
        $version = is_numeric($row->permissions_version ?? null) ? (int) $row->permissions_version : 0;

        return match ($version) {
            0 => self::LEGACY_MASKS[$mask] ?? null,
            1 => self::CURRENT_MASKS[$mask] ?? null,
            default => null,
        };
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
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
        $upgrade = $db->table('migrations')->where('migration', '2026_10_07_000006_share_links_v1')->value('batch');

        return $base !== null && $base === $upgrade;
    }
};
