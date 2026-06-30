<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Vaulter\Contracts\IdStrategy;
use Vaulter\Panels\Panel;
use Vaulter\Support\Config;

/**
 * The single source of truth for a storage panel's per-panel table definitions
 * (master plan §4.2). Every table is parameterised by a Panel: physical names
 * come from the panel table map (or its prefix, or the shared default), the
 * key/reference column shape comes from the panel's IdStrategy, and feature flags
 * decide which tables exist at all.
 *
 * The shipped default-panel migrations (one thin shell per entity) delegate here,
 * so there is ONE definition for both the shipped v_* schema and any generated
 * per-panel schema — no drift. This is the only file carrying real domain schema.
 *
 * M1a scope (6 entities): storages, nodes, blobs, files, file_versions and the
 * file_version_blobs edge table. The remaining entities (grants, shares, uploads,
 * document_versions, activity, reliability) land in later slices.
 *
 * Foreign keys are explicit and point at the panel's OWN resolved tables
 * (`$panel->table(...)`), so a prefixed own-tables panel never references the
 * shared schema by mistake.
 */
final class PanelTablesBuilder
{
    /**
     * The base entity keys this builder owns, in dependency order. The generator
     * and createAll()/dropAll() iterate this list; gating is applied per entity.
     *
     * @var list<string>
     */
    private const array ENTITIES = [
        'storages',
        'nodes',
        'blobs',
        'files',
        'file_versions',
        'file_version_blobs',
        'node_grants',
    ];

    /**
     * Create every table this panel needs, in dependency order, honouring its
     * feature flags. storages and nodes are always present; blobs is gated on the
     * 'blobs' feature; files/file_versions/file_version_blobs on 'versions'.
     */
    public function createAll(Panel $panel): void
    {
        foreach (self::ENTITIES as $entity) {
            if (!$this->entityEnabled(panel: $panel, entity: $entity)) {
                continue;
            }

            $this->createTable(panel: $panel, entity: $entity);
        }
    }

    /**
     * Drop every table this panel owns, in reverse dependency order. Each drop is
     * guarded so a panel whose feature set changed can still be torn down.
     */
    public function dropAll(Panel $panel): void
    {
        foreach (array_reverse(self::ENTITIES) as $entity) {
            $this->dropTable(panel: $panel, entity: $entity);
        }
    }

    /**
     * Create a single entity's table for this panel. The base default-panel
     * migrations call these one-per-file so each file stays the canonical column
     * source for its own table while sharing these definitions with the generated
     * per-panel schema.
     */
    public function createTable(Panel $panel, string $entity): void
    {
        $ids = $this->ids(panel: $panel);

        Schema::create($panel->table($entity), function (Blueprint $table) use ($panel, $ids, $entity): void {
            match ($entity) {
                'storages' => $this->defineStorages(table: $table, panel: $panel, ids: $ids),
                'nodes' => $this->defineNodes(table: $table, panel: $panel, ids: $ids),
                'blobs' => $this->defineBlobs(table: $table, panel: $panel, ids: $ids),
                'files' => $this->defineFiles(table: $table, panel: $panel, ids: $ids),
                'file_versions' => $this->defineFileVersions(table: $table, panel: $panel, ids: $ids),
                'file_version_blobs' => $this->defineFileVersionBlobs(table: $table, panel: $panel, ids: $ids),
                'node_grants' => $this->defineNodeGrants(table: $table, panel: $panel, ids: $ids),
                default => throw new InvalidArgumentException("Unknown Vaulter panel entity [{$entity}]."),
            };
        });

        $this->afterCreate(panel: $panel, entity: $entity);
    }

    /**
     * Drop a single entity's table for this panel — the down() counterpart of
     * createTable().
     */
    public function dropTable(Panel $panel, string $entity): void
    {
        Schema::dropIfExists($panel->table($entity));
    }

    /**
     * v_storages — the ownable polymorphic disk container (§2.1). Always created;
     * carries the panel discriminator.
     */
    private function defineStorages(Blueprint $table, Panel $panel, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->panelDiscriminator(table: $table);
        $this->tenant(table: $table, ids: $ids);

        $ids->reference(table: $table, column: 'owner_id');
        $table->string('owner_type');

        $table->string('name');
        $table->string('slug', 160)->nullable();
        $ids->reference(table: $table, column: 'root_node_id', nullable: true);
        $table->string('disk', 64)->default('default');
        $table->string('storage_type', 32)->default('private')->index();
        $table->bigInteger('quota_bytes')->nullable();
        $table->bigInteger('used_bytes')->default(0);
        $table->string('status', 16)->default('active')->index();
        $table->unsignedInteger('default_node_permissions')->default(31);
        $this->extraData(table: $table, panel: $panel);
        $ids->actor(table: $table, column: 'created_by', nullable: true);
        $table->softDeletes();
        $table->timestamps();

        $table->index(['owner_type', 'owner_id']);
        $table->index(['panel', 'status']);

        // Idempotency backbone for get-or-create (M1c): one live storage per
        // (owner_type, owner_id, panel). A partial unique (driver-aware, added in
        // afterCreate) lets a soft-deleted row's owner be re-provisioned while a
        // concurrent first-touch converges on one row via this constraint.

        // root_node_id is NOT a DB foreign key: v_storages and v_nodes reference
        // each other (the storage points at its root, every node points at its
        // storage), so a hard FK here would create a chicken-and-egg insert order.
    }

    /**
     * v_nodes — the unified folder|file|document adjacency tree (§2.2, the
     * centre). Always created; carries the panel discriminator, the soft-trash
     * triplet and the sibling-unique guard (added in afterCreate, driver-aware).
     */
    private function defineNodes(Blueprint $table, Panel $panel, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->panelDiscriminator(table: $table);
        $this->tenant(table: $table, ids: $ids);
        $ids->reference(table: $table, column: 'storage_id');
        $ids->reference(table: $table, column: 'parent_id', nullable: true);

        $table->string('kind', 16)->index();
        $table->string('name');
        $table->string('mime_type', 191)->nullable()->index();
        $table->string('extension', 32)->nullable();
        $table->bigInteger('size_bytes')->default(0);
        $ids->reference(table: $table, column: 'current_version_id', nullable: true);
        $ids->reference(table: $table, column: 'file_id', nullable: true);
        $table->integer('position')->default(0);
        $table->boolean('is_pinned')->default(false);
        $table->boolean('inherit_grants')->default(true);
        $ids->reference(table: $table, column: 'shortcut_target_id', nullable: true);
        $ids->actor(table: $table, column: 'owner_id', nullable: true);
        $this->extraData(table: $table, panel: $panel);

        // Soft-trash triplet (D17) — distinct from the permanent soft-delete below.
        $table->timestamp('trashed_at')->nullable();
        $ids->actor(table: $table, column: 'trashed_by', nullable: true);
        $ids->reference(table: $table, column: 'trashed_from_parent_id', nullable: true);

        $ids->actor(table: $table, column: 'created_by', nullable: true);
        $ids->actor(table: $table, column: 'updated_by', nullable: true);
        $table->softDeletes();
        $table->timestamps();

        // Hot listing indexes (§2.2 index plan).
        $table->index(['storage_id', 'parent_id']);
        $table->index(['storage_id', 'parent_id', 'position']);
        $table->index(['storage_id', 'kind', 'trashed_at']);

        $table->foreign('storage_id')
            ->references('id')
            ->on($panel->table('storages'))
            ->cascadeOnDelete();

        $table->foreign('parent_id')
            ->references('id')
            ->on($panel->table('nodes'))
            ->cascadeOnDelete();
    }

    /**
     * v_blobs — the content-addressable, immutable payload (dedup + GC, §2.3).
     * Gated on 'blobs'. storage_key is scope-qualified (H.3) and the dedup-unique
     * index spans the scope column so two scopes never share one physical row.
     */
    private function defineBlobs(Blueprint $table, Panel $panel, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->tenant(table: $table, ids: $ids);
        $ids->reference(table: $table, column: 'storage_id', nullable: true);

        $table->string('hash_algo', 16)->default('sha256');
        $table->char('sha256', 64);
        $table->bigInteger('size_bytes')->index();
        $table->string('disk', 64)->default('default');

        // Scope-qualified storage_key: "<scope>/ab/cd/<sha256>" (H.3 / D18′). A
        // scope-free path under per-scope dedup would let one GC delete another
        // scope's live bytes.
        $table->text('storage_key');

        // Blob state machine (logic is M1b; the columns exist now): pending →
        // committed → tombstoned → deleting → deleted.
        $table->string('status', 12)->default('pending')->index();
        $table->bigInteger('ref_count')->default(0);
        $table->string('dedup_scope', 8)->default('tenant');
        $table->string('etag', 64)->nullable();
        $table->string('md5', 64)->nullable();
        $table->string('crc32c', 64)->nullable();
        $table->boolean('chunked')->default(false);
        $ids->reference(table: $table, column: 'encryption_key_id', nullable: true);
        $table->timestamp('first_seen_at')->useCurrent();
        $table->timestamp('committed_at')->nullable();
        $table->timestamp('delete_after_at')->nullable();
        $this->extraData(table: $table, panel: $panel);

        // Dedup-UNIQUE by scope (D18 / H.3) — this index IS the portable race
        // backbone for concurrent identical uploads: one INSERT wins, the loser
        // catches the unique violation and re-SELECTs + reuses. The column set is
        // finalised per config('vaulter.dedup.scope') (this resolves the M1a note
        // that tenant_id was emitted unconditionally):
        //   global  → UNIQUE (sha256)              one object install-wide
        //   tenant  → UNIQUE (tenant_id, sha256)   no cross-tenant existence leak
        //   storage → UNIQUE (storage_id, sha256)
        // tenant_id/storage_id columns always exist (the model carries them); only
        // the UNIQUE index spans the scope-relevant subset. Index names are derived
        // from the table so a per-panel prefixed table never collides.
        $name = $table->getTable();
        $table->unique($this->dedupUniqueColumns(), "{$name}_dedup_uniq");
        $table->unique('storage_key', "{$name}_storage_key_uniq");

        // GC scan support (§2.3): committed orphans past their grace deadline.
        $table->index(['status', 'ref_count', 'delete_after_at'], "{$name}_gc_idx");
    }

    /**
     * v_files — the mutable 1:1 handle for a kind=file node (§2.4). Gated on
     * 'versions'.
     */
    private function defineFiles(Blueprint $table, Panel $panel, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->tenant(table: $table, ids: $ids);
        $ids->reference(table: $table, column: 'node_id');
        $ids->reference(table: $table, column: 'current_version_id', nullable: true);

        $table->string('mime_type', 191)->nullable();
        $table->bigInteger('size_bytes')->default(0);
        $table->unsignedInteger('version_count')->default(0);
        $this->extraData(table: $table, panel: $panel);
        $ids->actor(table: $table, column: 'created_by', nullable: true);
        $table->softDeletes();
        $table->timestamps();

        $table->unique('node_id', "{$table->getTable()}_node_uniq");

        $table->foreign('node_id')
            ->references('id')
            ->on($panel->table('nodes'))
            ->cascadeOnDelete();
    }

    /**
     * v_file_versions — the immutable file ↔ blob version (§2.4). Gated on
     * 'versions'. version_no is monotonic per file (unique).
     */
    private function defineFileVersions(Blueprint $table, Panel $panel, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->tenant(table: $table, ids: $ids);
        $ids->reference(table: $table, column: 'file_id');
        $table->integer('version_no');
        $ids->reference(table: $table, column: 'blob_id', nullable: true);
        $ids->reference(table: $table, column: 'manifest_blob_id', nullable: true);
        $table->char('content_sha256', 64)->index();
        $table->bigInteger('size_bytes');
        $table->string('mime_type', 191)->nullable();
        $table->string('original_name');
        $table->string('status', 12)->default('pending')->index();
        $table->boolean('keep_forever')->default(false);
        $this->extraData(table: $table, panel: $panel);
        $ids->actor(table: $table, column: 'created_by', nullable: true);
        $table->timestamp('created_at')->nullable();
        $table->timestamp('committed_at')->nullable();

        $table->unique(['file_id', 'version_no'], "{$table->getTable()}_no_uniq");
        $table->index(['file_id', 'status']);

        $table->foreign('file_id')
            ->references('id')
            ->on($panel->table('files'))
            ->cascadeOnDelete();
    }

    /**
     * v_file_version_blobs — the edge table that is the GC truth (§2.4). Gated on
     * 'versions'. Composite primary key; liveness of a blob = existence of an edge
     * here, so it is never soft-deleted.
     */
    private function defineFileVersionBlobs(Blueprint $table, Panel $panel, IdStrategy $ids): void
    {
        $ids->reference(table: $table, column: 'file_version_id');
        $ids->reference(table: $table, column: 'blob_id');
        $table->string('role', 12)->default('data');
        $table->integer('ordinal')->default(0);
        $table->bigInteger('offset_bytes')->nullable();
        $table->bigInteger('length_bytes')->nullable();

        $table->primary(['file_version_id', 'blob_id', 'role', 'ordinal'], "{$table->getTable()}_pk");

        $table->foreign('file_version_id')
            ->references('id')
            ->on($panel->table('file_versions'))
            ->cascadeOnDelete();

        $table->foreign('blob_id')
            ->references('id')
            ->on($panel->table('blobs'))
            ->cascadeOnDelete();
    }

    /**
     * v_node_grants — the per-node ACL grant with inheritance (§2.6 / H.2). Gated
     * on 'grants'. The (node_id, subject_type, subject_id, scope, effect) live-row
     * UNIQUE (added driver-aware in afterCreate) keeps one allow + one deny per
     * subject/scope per node; the (subject_type, subject_id) index backs the
     * "shared with me" fast pass.
     */
    private function defineNodeGrants(Blueprint $table, Panel $panel, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->tenant(table: $table, ids: $ids);
        $ids->reference(table: $table, column: 'node_id');

        // Subject morph: a registered alias (user|role|team), never a raw FQCN.
        $ids->reference(table: $table, column: 'subject_id');
        $table->string('subject_type');

        $table->string('level', 16)->index();
        $table->unsignedInteger('permissions')->default(0);
        $table->string('effect', 5)->default('allow')->index();
        $table->string('scope', 8)->default('subtree');

        // Reserved for the OPTIONAL materialised effective-grants read-model — the
        // source of truth in M2a is the direct rows + the tree, so these stay
        // false/null (the inherited walk is computed, not stored).
        $table->boolean('inherited')->default(false)->index();
        $ids->reference(table: $table, column: 'inherited_from_node_id', nullable: true);

        $table->timestamp('expires_at')->nullable()->index();
        $ids->actor(table: $table, column: 'granted_by', nullable: true);
        $this->extraData(table: $table, panel: $panel);
        $table->softDeletes();
        $table->timestamps();

        $name = $table->getTable();

        // "Shared with me": every node a subject is granted access to (§2.6).
        $table->index(['subject_type', 'subject_id'], "{$name}_subject_idx");
        $table->index(['node_id', 'effect'], "{$name}_node_effect_idx");

        $table->foreign('node_id')
            ->references('id')
            ->on($panel->table('nodes'))
            ->cascadeOnDelete();
    }

    /**
     * Post-create hooks that cannot be expressed inside the Blueprint, currently
     * the driver-aware sibling-unique guard on v_nodes.
     */
    private function afterCreate(Panel $panel, string $entity): void
    {
        if ($entity === 'storages') {
            $this->addOwnerUniqueIndex(table: $panel->table('storages'));
        }

        if ($entity === 'nodes') {
            $this->addSiblingUniqueIndex(table: $panel->table('nodes'));
        }

        if ($entity === 'node_grants') {
            $this->addGrantUniqueIndex(table: $panel->table('node_grants'));
        }
    }

    /**
     * One LIVE grant per (node_id, subject_type, subject_id, scope, effect) — the
     * §2.6 grant-unique key. Driver-aware: a partial (soft-delete-aware) index on
     * Postgres/SQLite, a MySQL generated-column fallback, a plain unique elsewhere.
     */
    private function addGrantUniqueIndex(string $table): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $name = "{$table}_grant_uniq";

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX {$name} ON {$table} ".
                '(node_id, subject_type, subject_id, scope, effect) '.
                'WHERE deleted_at IS NULL',
            );

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement(
                "ALTER TABLE `{$table}` ADD COLUMN `grant_active` VARCHAR(700) ".
                'GENERATED ALWAYS AS (CASE WHEN `deleted_at` IS NULL '.
                "THEN CONCAT(`node_id`, ':', `subject_type`, ':', `subject_id`, ':', `scope`, ':', `effect`) ".
                'ELSE NULL END) STORED',
            );

            DB::statement("ALTER TABLE `{$table}` ADD UNIQUE INDEX `{$name}` (`grant_active`)");

            return;
        }

        Schema::table($table, static function (Blueprint $blueprint) use ($name): void {
            $blueprint->unique(['node_id', 'subject_type', 'subject_id', 'scope', 'effect'], $name);
        });
    }

    /**
     * One LIVE storage per (owner_type, owner_id, panel) — the M1c get-or-create
     * idempotency key (§2.1). Driver-aware so the partial (soft-delete-aware)
     * shape works on Postgres/SQLite, with a MySQL generated-column fallback and a
     * plain unique on unknown drivers.
     */
    private function addOwnerUniqueIndex(string $table): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $name = "{$table}_owner_panel_uniq";

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX {$name} ON {$table} (owner_type, owner_id, panel) ".
                'WHERE deleted_at IS NULL',
            );

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement(
                "ALTER TABLE `{$table}` ADD COLUMN `owner_active` VARCHAR(600) ".
                'GENERATED ALWAYS AS (CASE WHEN `deleted_at` IS NULL '.
                "THEN CONCAT(`owner_type`, ':', COALESCE(`owner_id`, ''), ':', `panel`) ELSE NULL END) STORED",
            );

            DB::statement("ALTER TABLE `{$table}` ADD UNIQUE INDEX `{$name}` (`owner_active`)");

            return;
        }

        Schema::table($table, static function (Blueprint $blueprint) use ($name): void {
            $blueprint->unique(['owner_type', 'owner_id', 'panel'], $name);
        });
    }

    /**
     * Sibling-name uniqueness that survives soft-delete AND soft-trash (§2.2).
     *
     *  - PostgreSQL / SQLite: a PARTIAL unique index on (parent_id, lower(name))
     *    WHERE deleted_at IS NULL AND trashed_at IS NULL — a trashed or
     *    soft-deleted duplicate name is allowed, a live one is rejected.
     *  - MySQL/MariaDB: no partial indexes, so a stored generated column folds the
     *    active liveness into a value (NULL when trashed/deleted, which is exempt
     *    from UNIQUE) and a plain unique index sits on it.
     */
    private function addSiblingUniqueIndex(string $table): void
    {
        $driver = Schema::getConnection()->getDriverName();
        // Derive the index name from the table so a per-panel prefixed table gets
        // its own index name (index names are global on SQLite/PG, not per-table).
        $name = "{$table}_sibling_uniq";

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX {$name} ON {$table} (parent_id, lower(name)) ".
                'WHERE deleted_at IS NULL AND trashed_at IS NULL',
            );

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement(
                "ALTER TABLE `{$table}` ADD COLUMN `sibling_active` VARCHAR(512) ".
                'GENERATED ALWAYS AS (CASE WHEN `deleted_at` IS NULL AND `trashed_at` IS NULL '.
                "THEN CONCAT(COALESCE(`parent_id`, ''), ':', LOWER(`name`)) ELSE NULL END) STORED",
            );

            DB::statement("ALTER TABLE `{$table}` ADD UNIQUE INDEX `{$name}` (`sibling_active`)");

            return;
        }

        // Unknown driver: fall back to a plain (non-soft-delete-aware) unique so
        // the schema is still defended. Supported drivers above are the canon.
        Schema::table($table, static function (Blueprint $blueprint) use ($name): void {
            $blueprint->unique(['parent_id', 'name'], $name);
        });
    }

    /**
     * The default-panel discriminator column. Only emitted on the panel roots
     * (storages + nodes); other entities resolve their panel via their parent FK.
     */
    private function panelDiscriminator(Blueprint $table): void
    {
        $table->string(column: 'panel')->default(value: 'default')->index();
    }

    /**
     * The optional tenant_id column (host-owned, D8) — emitted only when tenancy
     * is enabled. On blobs it participates in the dedup-unique index, so the
     * column must always exist there; we still gate it on the global flag, and the
     * dedup index simply spans a NULL column when tenancy is off.
     */
    private function tenant(Blueprint $table, IdStrategy $ids): void
    {
        $ids->reference(table: $table, column: 'tenant_id', nullable: true);
    }

    /**
     * The optional extra_data jsonb escape hatch (D13), gated per panel.
     */
    private function extraData(Blueprint $table, Panel $panel): void
    {
        if (!$panel->featureEnabled('extra_data')) {
            return;
        }

        $table->json(column: 'extra_data')->nullable();
    }

    /**
     * Whether a per-panel entity's table should be created given the panel's
     * feature flags. storages + nodes are unconditional roots.
     */
    private function entityEnabled(Panel $panel, string $entity): bool
    {
        return match ($entity) {
            'storages', 'nodes' => true,
            'blobs' => $panel->featureEnabled('blobs'),
            'files', 'file_versions', 'file_version_blobs' => $panel->featureEnabled('versions'),
            'node_grants' => $panel->featureEnabled('grants'),
            default => false,
        };
    }

    /**
     * The column set for the blob dedup-UNIQUE index, derived from the configured
     * dedup scope (D18 / H.3). sha256 is always last so the index also serves
     * content-address lookups within the scope.
     *
     * @return list<string>
     */
    private function dedupUniqueColumns(): array
    {
        return match (Config::dedupScope()) {
            'global' => ['sha256'],
            'storage' => ['storage_id', 'sha256'],
            default => ['tenant_id', 'sha256'],
        };
    }

    private function ids(Panel $panel): IdStrategy
    {
        return app(IdStrategyResolver::class)->resolve($panel->idStrategyKey());
    }
}
