<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Schema\UpgradePreflightException;
use Vaulter\Models\Activity\Activity;
use Vaulter\Schema\HostKeyColumns;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Support\Identity;
use Vaulter\Support\NodeName;

/**
 * The 1.0 node data contract (PLAN2.D39–D42):
 *  - v_nodes.name_key = NFC(casefold(NFC(name))) replaces lower(name) in the
 *    sibling-unique index and is compared byte for byte. Live siblings whose names
 *    differ under LOWER() but not under full case folding or NFC keep the earliest
 *    node's name; each later one is renamed "<base> (conflict N)<.ext>", logged,
 *    and recorded as a node.renamed activity from vaulter:system;
 *  - v_nodes.properties (namespaced strings) receives the old nodes.extra_data as
 *    the "legacy" namespace; a node whose data cannot fit the default limits stops
 *    the upgrade before anything changes, so nothing is lost;
 *  - v_nodes.content_modified_at/by, v_file_revisions.client_modified_at and the
 *    per-actor v_node_user_states;
 *  - v_nodes.is_pinned, v_nodes.position and extra_data on every table go.
 *
 * Every step checks the state it leaves behind, so a re-run after a failure (MySQL
 * cannot roll DDL back) finishes the job; a column or table in a shape this
 * migration never produces stops it with UpgradePreflightException. Backfills run
 * in batches by id. Definitions are literal: later SchemaBuilder edits never change
 * what this migration did.
 */
return new class extends Migration
{
    private const int BATCH = 500;

    /** The width of the nodes name column (varchar(255)). */
    private const int NAME_MAX_BYTES = 255;

    /** The PLAN2.D41/D43 default limits the legacy namespace must fit. */
    private const int MAX_PROPERTY_COUNT = 100;

    private const int MAX_PROPERTIES_BYTES = 16_384;

    private const int MAX_VALUE_BYTES = 1024;

    private const int MAX_KEY_BYTES = 64;

    /** Every entity that carried the extra_data escape hatch. */
    private const array EXTRA_DATA_ENTITIES = [
        'drives', 'nodes', 'blobs', 'files', 'file_revisions', 'node_grants', 'node_shares',
        'node_comments', 'documents', 'document_revisions', 'upload_sessions', 'activity',
    ];

    public function up(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $nodes = $this->table('nodes');

        if (!$db->getSchemaBuilder()->hasTable($nodes)) {
            return;
        }

        $this->refuseUnknownShapes($db);
        $this->refuseUnfitLegacyData($db, $nodes);

        $this->addNodeColumns($db, $nodes);
        $this->addClientModifiedAt($db);
        $this->createUserStates($db);

        $this->backfillNameKeys($db, $nodes);
        $this->backfillContentModified($db, $nodes);
        $this->moveExtraDataToProperties($db, $nodes);

        $this->replaceSiblingIndex($db, $nodes);
        $this->dropPinnedAndPosition($db, $nodes);
        $this->dropExtraData($db);
    }

    public function down(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $nodes = $this->table('nodes');
        $states = $this->table('node_user_states');

        // A fresh install created only the user-state table here; the base shells own the rest.
        $schema->dropIfExists($states);
        if ($this->freshInstall() || !$schema->hasTable($nodes) || !$schema->hasColumn($nodes, 'name_key')) {
            return;
        }

        foreach (self::EXTRA_DATA_ENTITIES as $entity) {
            $table = $this->table($entity);
            if ($schema->hasTable($table) && !$schema->hasColumn($table, 'extra_data')) {
                $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->json('extra_data')->nullable());
            }
        }
        $this->restoreExtraData($db, $nodes);

        if (!$schema->hasColumn($nodes, 'position')) {
            $schema->table($nodes, static function (Blueprint $table): void {
                $table->integer('position')->default(0);
                $table->boolean('is_pinned')->default(false);
            });
        }
        if (!$schema->hasIndex($nodes, $nodes.'_drive_id_parent_id_position_index')) {
            $schema->table($nodes, static fn (Blueprint $table) => $table->index(['drive_id', 'parent_id', 'position'], $nodes.'_drive_id_parent_id_position_index'));
        }
        if ($schema->hasIndex($nodes, $nodes.'_drive_id_parent_id_index')) {
            $schema->table($nodes, static fn (Blueprint $table) => $table->dropIndex($nodes.'_drive_id_parent_id_index'));
        }

        $this->dropSiblingIndex($db, $nodes);
        $this->createLowerNameSiblingIndex($db, $nodes);

        $schema->table($nodes, static fn (Blueprint $table) => $table->dropColumn(['name_key', 'properties', 'content_modified_at', 'content_modified_by']));

        $revisions = $this->table('file_revisions');
        if ($schema->hasTable($revisions) && $schema->hasColumn($revisions, 'client_modified_at')) {
            $schema->table($revisions, static fn (Blueprint $table) => $table->dropColumn('client_modified_at'));
        }
    }

    /**
     * Columns and tables this migration adds must either be absent or exactly what
     * it creates; anything else was not made by Vaulter and is not overwritten.
     */
    private function refuseUnknownShapes(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();
        $problems = [];
        $nodes = $this->table('nodes');
        $columns = array_column($schema->getColumns($nodes), null, 'name');
        $expected = [
            'name_key' => ['varchar', 'character varying', 'text'],
            'properties' => ['json', 'jsonb', 'text', 'longtext'],
            'content_modified_at' => ['timestamp', 'timestamp without time zone', 'datetime'],
        ];
        foreach ($expected as $column => $types) {
            if (isset($columns[$column]) && !in_array(strtolower($columns[$column]['type_name']), $types, true)) {
                $problems[] = $nodes.'.'.$column.' has type '.$columns[$column]['type_name'];
            }
        }

        $states = $this->table('node_user_states');
        if ($schema->hasTable($states)) {
            $missing = array_diff(['id', 'tenant_id', 'node_id', 'actor_type', 'actor_id', 'starred_at', 'created_at', 'updated_at'], $schema->getColumnListing($states));
            if ($missing !== []) {
                $problems[] = $states.' lacks ['.implode(', ', $missing).']';
            }
        }

        if ($problems !== []) {
            throw new UpgradePreflightException($problems);
        }
    }

    /**
     * nodes.extra_data becomes properties['legacy']. Keys are made valid by replacing
     * other characters with "_"; non-string values become JSON strings. A node whose
     * data still does not fit (or whose keys collide after the replacement) is listed
     * and nothing is changed.
     */
    private function refuseUnfitLegacyData(Connection $db, string $nodes): void
    {
        if (!$db->getSchemaBuilder()->hasColumn($nodes, 'extra_data')) {
            return;
        }

        $unfit = [];
        $last = null;
        do {
            $rows = $db->table($nodes)->whereNotNull('extra_data')
                ->when($last !== null, fn ($query) => $query->where('id', '>', $last))
                ->orderBy('id')->limit(self::BATCH)->get(['id', 'extra_data']);
            foreach ($rows as $row) {
                $last = $row->id;
                if ($this->legacyProperties($row->extra_data) === false) {
                    $unfit[] = Identity::stringify($this->key($row->id));
                }
            }
        } while ($rows->count() === self::BATCH && count($unfit) < 50);

        if ($unfit !== []) {
            throw new UpgradePreflightException(['nodes ['.implode(', ', $unfit).'] carry extra_data that does not fit the 1.0 properties limits '
                .'(at most '.self::MAX_PROPERTY_COUNT.' keys of '.self::MAX_KEY_BYTES.' bytes, values of '.self::MAX_VALUE_BYTES.' bytes, '
                .self::MAX_PROPERTIES_BYTES.' bytes in all); move that data out of extra_data first']);
        }
    }

    /**
     * The legacy namespace for one row's extra_data: null when there is nothing to
     * keep, false when it cannot fit.
     *
     * @return array<string, string>|false|null
     */
    private function legacyProperties(mixed $raw): array|false|null
    {
        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        if ($data === null || $data === [] || $data === '') {
            return null;
        }
        if (!is_array($data)) {
            $data = ['value' => $data];
        }

        $values = [];
        foreach ($data as $key => $value) {
            $key = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $key) ?? '';
            $value = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($key === '' || strlen($key) > self::MAX_KEY_BYTES || isset($values[$key])
                || !is_string($value) || !mb_check_encoding($value, 'UTF-8') || strlen($value) > self::MAX_VALUE_BYTES) {
                return false;
            }
            $values[$key] = $value;
        }
        ksort($values, SORT_STRING);
        $encoded = json_encode(['legacy' => $values], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (count($values) > self::MAX_PROPERTY_COUNT || !is_string($encoded) || strlen($encoded) > self::MAX_PROPERTIES_BYTES) {
            return false;
        }

        return $values;
    }

    private function addNodeColumns(Connection $db, string $nodes): void
    {
        $schema = $db->getSchemaBuilder();
        $grammar = $db->getQueryGrammar();

        if (!$schema->hasColumn($nodes, 'name_key')) {
            // NOT NULL from the start; the empty default marks rows the backfill has
            // not reached (no valid name has an empty key) and is dropped afterwards.
            $collation = in_array($db->getDriverName(), ['mysql', 'mariadb'], true) ? ' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' : '';
            $db->statement('ALTER TABLE '.$grammar->wrapTable($nodes).' ADD COLUMN '.$grammar->wrap('name_key')." VARCHAR(1024){$collation} NOT NULL DEFAULT ''");
        }
        if (!$schema->hasColumn($nodes, 'properties')) {
            $schema->table($nodes, static fn (Blueprint $table) => $table->jsonb('properties')->nullable());
        }
        if (!$schema->hasColumn($nodes, 'content_modified_at')) {
            $schema->table($nodes, static fn (Blueprint $table) => $table->timestamp('content_modified_at')->nullable());
        }
        if (!$schema->hasColumn($nodes, 'content_modified_by')) {
            $schema->table($nodes, static fn (Blueprint $table) => HostKeyColumns::add(table: $table, column: 'content_modified_by', nullable: true, index: false));
        }
    }

    private function addClientModifiedAt(Connection $db): void
    {
        $revisions = $this->table('file_revisions');
        $schema = $db->getSchemaBuilder();
        if ($schema->hasTable($revisions) && !$schema->hasColumn($revisions, 'client_modified_at')) {
            $schema->table($revisions, static fn (Blueprint $table) => $table->timestamp('client_modified_at')->nullable());
        }
    }

    private function createUserStates(Connection $db): void
    {
        $states = $this->table('node_user_states');
        $nodes = $this->table('nodes');
        if ($db->getSchemaBuilder()->hasTable($states)) {
            return;
        }

        $ids = app(IdStrategyResolver::class)->resolve();
        $db->getSchemaBuilder()->create($states, static function (Blueprint $table) use ($ids, $states, $nodes): void {
            $ids->key(table: $table);
            HostKeyColumns::add(table: $table, column: 'tenant_id', nullable: true, index: true);
            $ids->reference(table: $table, column: 'node_id');
            $table->string('actor_type', 191);
            HostKeyColumns::add(table: $table, column: 'actor_id', nullable: false, index: false);
            $table->timestamp('starred_at')->nullable();
            $table->timestamps();
            $table->unique(['node_id', 'actor_type', 'actor_id'], $states.'_actor_uniq');
            $table->index(['actor_type', 'actor_id', 'starred_at'], $states.'_starred_idx');
            $table->foreign('node_id')->references('id')->on($nodes)->cascadeOnDelete();
        });
    }

    /**
     * Keys in id order, so of two live siblings that now collide the earlier node
     * keeps its name. A rename, its key and its activity row commit together.
     */
    private function backfillNameKeys(Connection $db, string $nodes): void
    {
        $last = null;
        do {
            $rows = $db->table($nodes)->where('name_key', '')
                ->when($last !== null, fn ($query) => $query->where('id', '>', $last))
                ->orderBy('id')->limit(self::BATCH)->get(['id', 'tenant_id', 'drive_id', 'parent_id', 'name', 'trashed_at']);

            $db->transaction(function () use ($db, $nodes, $rows, &$last): void {
                foreach ($rows as $row) {
                    $last = $row->id;
                    $id = $this->key($row->id);
                    $name = is_string($row->name) ? $row->name : '';
                    $key = NodeName::key($name);

                    if ($row->trashed_at === null && $this->keyTaken($db, $nodes, $id, $row->parent_id, $key)) {
                        $renamed = $this->freeName($db, $nodes, $id, $row->parent_id, $name);
                        $db->table($nodes)->where('id', $id)->update(['name' => $renamed, 'name_key' => NodeName::key($renamed)]);
                        $this->recordRename($row, $id, $name, $renamed);

                        continue;
                    }

                    $db->table($nodes)->where('id', $id)->update(['name_key' => $key]);
                }
            });
        } while ($rows->count() === self::BATCH);

        if ($db->getDriverName() !== 'sqlite') {
            $grammar = $db->getQueryGrammar();
            $db->statement(match ($db->getDriverName()) {
                'pgsql' => 'ALTER TABLE '.$grammar->wrapTable($nodes).' ALTER COLUMN name_key DROP DEFAULT',
                default => 'ALTER TABLE '.$grammar->wrapTable($nodes).' ALTER COLUMN '.$grammar->wrap('name_key').' DROP DEFAULT',
            });
        }
    }

    private function keyTaken(Connection $db, string $nodes, int|string $id, mixed $parentId, string $key): bool
    {
        return $db->table($nodes)
            ->where('parent_id', $parentId)
            ->where('id', '<>', $id)
            ->whereNull('trashed_at')
            ->where('name_key', $key)
            ->exists();
    }

    /**
     * The first "<base> (conflict N)<.ext>" whose key no live sibling has and which the
     * still-present lower(name) index accepts. The base is shortened on a UTF-8
     * character boundary so the candidate fits the name column.
     */
    private function freeName(Connection $db, string $nodes, int|string $id, mixed $parentId, string $name): string
    {
        $dot = strrpos($name, '.');
        [$base, $extension] = $dot === false || $dot === 0 ? [$name, ''] : [substr($name, 0, $dot), substr($name, $dot)];

        for ($n = 1; ; $n++) {
            $marker = sprintf(' (conflict %d)', $n);
            // An extension too long to keep beside the marker is shortened with the base.
            [$head, $tail] = strlen($marker.$extension) < self::NAME_MAX_BYTES ? [$base, $extension] : [$name, ''];
            $candidate = mb_strcut($head, 0, self::NAME_MAX_BYTES - strlen($marker.$tail), 'UTF-8').$marker.$tail;
            $taken = $this->keyTaken($db, $nodes, $id, $parentId, NodeName::key($candidate))
                || $db->table($nodes)->where('parent_id', $parentId)->where('id', '<>', $id)->whereNull('trashed_at')
                    ->whereRaw('lower(name) = lower(?)', [$candidate])->exists();
            if (!$taken) {
                return $candidate;
            }
        }
    }

    private function recordRename(stdClass $row, int|string $id, string $from, string $to): void
    {
        Log::warning('[Vaulter] name_key upgrade renamed node ['.Identity::stringify($id).'] from '
            .json_encode($from, JSON_UNESCAPED_UNICODE).' to '.json_encode($to, JSON_UNESCAPED_UNICODE)
            .': a live sibling has the same name under Unicode case folding.');

        $nodeModel = app(VaulterConfig::class)->model('node');
        $activityModel = app(VaulterConfig::class)->model('activity');

        /** @var Activity $activity */
        $activity = new $activityModel;
        $activity->forceFill([
            'tenant_id' => Identity::key($row->tenant_id),
            'drive_id' => $this->key($row->drive_id),
            'actor_type' => 'vaulter:system',
            'actor_id' => null,
            'verb' => 'node.renamed',
            'target_type' => (new $nodeModel)->getMorphClass(),
            'target_id' => $id,
            'context' => ['from' => $from, 'to' => $to, 'reason' => 'name_key_upgrade'],
            'created_at' => now(),
        ])->save();
    }

    /** A file's content changed when its head committed; a document's at its last state write. */
    private function backfillContentModified(Connection $db, string $nodes): void
    {
        $files = $this->table('files');
        $revisions = $this->table('file_revisions');
        $documents = $this->table('documents');
        $schema = $db->getSchemaBuilder();
        $last = null;
        do {
            $ids = $db->table($nodes)->whereNull('content_modified_at')->whereIn('kind', ['file', 'document'])
                ->when($last !== null, fn ($query) => $query->where('id', '>', $last))
                ->orderBy('id')->limit(self::BATCH)->pluck('id')->all();
            if ($ids === []) {
                break;
            }
            $last = end($ids);

            $heads = $db->table($files.' as f')->join($revisions.' as r', 'r.id', '=', 'f.current_revision_id')
                ->whereIn('f.node_id', $ids)->get(['f.node_id', 'r.committed_at', 'r.created_at', 'r.created_by']);
            foreach ($heads as $head) {
                $db->table($nodes)->where('id', $head->node_id)->update([
                    'content_modified_at' => $head->committed_at ?? $head->created_at,
                    'content_modified_by' => $head->created_by,
                ]);
            }
            if ($schema->hasTable($documents)) {
                foreach ($db->table($documents)->whereIn('node_id', $ids)->get(['node_id', 'updated_at', 'updated_by']) as $document) {
                    $db->table($nodes)->where('id', $document->node_id)->update([
                        'content_modified_at' => $document->updated_at,
                        'content_modified_by' => $document->updated_by,
                    ]);
                }
            }
        } while (count($ids) === self::BATCH);
    }

    private function moveExtraDataToProperties(Connection $db, string $nodes): void
    {
        if (!$db->getSchemaBuilder()->hasColumn($nodes, 'extra_data')) {
            return;
        }

        $last = null;
        do {
            $rows = $db->table($nodes)->whereNotNull('extra_data')
                ->when($last !== null, fn ($query) => $query->where('id', '>', $last))
                ->orderBy('id')->limit(self::BATCH)->get(['id', 'extra_data', 'properties']);
            $db->transaction(function () use ($db, $nodes, $rows, &$last): void {
                foreach ($rows as $row) {
                    $last = $row->id;
                    $legacy = $this->legacyProperties($row->extra_data);
                    if ($legacy === false) {
                        throw new UpgradePreflightException(['node ['.Identity::stringify($this->key($row->id)).'] extra_data changed during the upgrade and no longer fits']);
                    }
                    $properties = is_string($row->properties) ? json_decode($row->properties, true) : null;
                    $properties = is_array($properties) ? $properties : [];
                    if ($legacy !== null) {
                        $properties['legacy'] = $legacy;
                        ksort($properties, SORT_STRING);
                    }
                    $db->table($nodes)->where('id', $row->id)->update([
                        'properties' => $properties === [] ? null : json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'extra_data' => null,
                    ]);
                }
            });
        } while ($rows->count() === self::BATCH);
    }

    private function restoreExtraData(Connection $db, string $nodes): void
    {
        $last = null;
        do {
            $rows = $db->table($nodes)->whereNotNull('properties')
                ->when($last !== null, fn ($query) => $query->where('id', '>', $last))
                ->orderBy('id')->limit(self::BATCH)->get(['id', 'properties']);
            foreach ($rows as $row) {
                $last = $row->id;
                $properties = is_string($row->properties) ? json_decode($row->properties, true) : null;
                if (is_array($properties) && is_array($properties['legacy'] ?? null)) {
                    $db->table($nodes)->where('id', $row->id)->update(['extra_data' => json_encode($properties['legacy'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                }
            }
        } while ($rows->count() === self::BATCH);
    }

    private function replaceSiblingIndex(Connection $db, string $nodes): void
    {
        if ($this->siblingIndexUsesNameKey($db, $nodes)) {
            return;
        }

        $this->dropSiblingIndex($db, $nodes);
        $name = $nodes.'_sibling_uniq';

        match ($db->getDriverName()) {
            'pgsql' => $db->statement(sprintf('CREATE UNIQUE INDEX %s ON %s (parent_id, name_key COLLATE "C") WHERE trashed_at IS NULL', $name, $nodes)),
            'sqlite' => $db->statement(sprintf('CREATE UNIQUE INDEX %s ON %s (parent_id, name_key) WHERE trashed_at IS NULL', $name, $nodes)),
            'mysql', 'mariadb' => $this->createHashSiblingIndex($db, $nodes, $name),
            default => $db->getSchemaBuilder()->table($nodes, static fn (Blueprint $table) => $table->unique(['parent_id', 'name_key'], $name)),
        };
    }

    private function createHashSiblingIndex(Connection $db, string $nodes, string $name): void
    {
        if (!$db->getSchemaBuilder()->hasColumn($nodes, 'name_key_hash')) {
            $db->statement(sprintf(
                'ALTER TABLE `%s` ADD COLUMN `name_key_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin '
                .'GENERATED ALWAYS AS (CASE WHEN `trashed_at` IS NULL THEN SHA2(`name_key`, 256) ELSE NULL END) STORED',
                $nodes,
            ));
        }
        $db->statement(sprintf('CREATE UNIQUE INDEX `%s` ON `%s` (`parent_id`, `name_key_hash`)', $name, $nodes));
    }

    private function siblingIndexUsesNameKey(Connection $db, string $nodes): bool
    {
        $schema = $db->getSchemaBuilder();
        foreach ($schema->getIndexes($nodes) as $index) {
            if ($index['name'] === $nodes.'_sibling_uniq') {
                return in_array('name_key', $index['columns'], true) || in_array('name_key_hash', $index['columns'], true)
                    || $this->indexDefinitionMentions($db, $nodes, 'name_key');
            }
        }

        return false;
    }

    private function indexDefinitionMentions(Connection $db, string $nodes, string $needle): bool
    {
        $definition = match ($db->getDriverName()) {
            'pgsql' => $db->table('pg_indexes')->whereRaw('schemaname = current_schema()')->where('indexname', $nodes.'_sibling_uniq')->value('indexdef'),
            'sqlite' => $db->table('sqlite_master')->where('type', 'index')->where('name', $nodes.'_sibling_uniq')->value('sql'),
            default => null,
        };

        return is_string($definition) && str_contains($definition, $needle);
    }

    private function dropSiblingIndex(Connection $db, string $nodes): void
    {
        $schema = $db->getSchemaBuilder();
        if ($schema->hasIndex($nodes, $nodes.'_sibling_uniq')) {
            $schema->table($nodes, static fn (Blueprint $table) => $table->dropIndex($nodes.'_sibling_uniq'));
        }
        foreach (['sibling_active', 'name_key_hash'] as $generated) {
            if ($schema->hasColumn($nodes, $generated)) {
                $schema->table($nodes, static fn (Blueprint $table) => $table->dropColumn($generated));
            }
        }
    }

    /** The 0.3 sibling-unique index before PLAN2.D39, for down(). */
    private function createLowerNameSiblingIndex(Connection $db, string $nodes): void
    {
        $name = $nodes.'_sibling_uniq';

        match ($db->getDriverName()) {
            'pgsql', 'sqlite' => $db->statement(sprintf('CREATE UNIQUE INDEX %s ON %s (parent_id, lower(name)) WHERE trashed_at IS NULL', $name, $nodes)),
            'mysql' => $db->statement(sprintf('CREATE UNIQUE INDEX `%s` ON `%s` (`parent_id`, ((CASE WHEN `trashed_at` IS NULL THEN LOWER(`name`) ELSE NULL END)))', $name, $nodes)),
            'mariadb' => $this->createMariaDbLowerNameIndex($db, $nodes, $name),
            default => $db->getSchemaBuilder()->table($nodes, static fn (Blueprint $table) => $table->unique(['parent_id', 'name'], $name)),
        };
    }

    private function createMariaDbLowerNameIndex(Connection $db, string $nodes, string $name): void
    {
        $db->statement(sprintf("ALTER TABLE `%s` ADD COLUMN `sibling_active` VARCHAR(512) GENERATED ALWAYS AS (CASE WHEN `trashed_at` IS NULL THEN CONCAT(COALESCE(`parent_id`, ''), ':', LOWER(`name`)) ELSE NULL END) STORED", $nodes));
        $db->statement(sprintf('ALTER TABLE `%s` ADD UNIQUE INDEX `%s` (`sibling_active`)', $nodes, $name));
    }

    private function dropPinnedAndPosition(Connection $db, string $nodes): void
    {
        $schema = $db->getSchemaBuilder();
        if (!$schema->hasIndex($nodes, $nodes.'_drive_id_parent_id_index')) {
            $schema->table($nodes, static fn (Blueprint $table) => $table->index(['drive_id', 'parent_id'], $nodes.'_drive_id_parent_id_index'));
        }
        foreach ($schema->getIndexes($nodes) as $index) {
            if (!$index['primary'] && array_intersect(['position', 'is_pinned'], $index['columns']) !== []) {
                $schema->table($nodes, static fn (Blueprint $table) => $table->dropIndex($index['name']));
            }
        }
        $present = array_values(array_intersect(['position', 'is_pinned'], $schema->getColumnListing($nodes)));
        if ($present !== []) {
            $schema->table($nodes, static fn (Blueprint $table) => $table->dropColumn($present));
        }
    }

    private function dropExtraData(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();
        foreach (self::EXTRA_DATA_ENTITIES as $entity) {
            $table = $this->table($entity);
            if ($schema->hasTable($table) && $schema->hasColumn($table, 'extra_data')) {
                $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->dropColumn('extra_data'));
            }
        }
    }

    private function table(string $entity): string
    {
        return app(DatabaseConfig::class)->table($entity);
    }

    /**
     * Ids selected from a NOT NULL key column are always int or string.
     */
    private function key(mixed $value): int|string
    {
        return Identity::key($value) ?? throw new UnexpectedValueException('[Vaulter] Unexpected key '.get_debug_type($value).'.');
    }

    private function freshInstall(): bool
    {
        $db = app(VaulterDatabase::class)->connection();
        if (!$db->getSchemaBuilder()->hasTable('migrations')) {
            return true;
        }

        $base = $db->table('migrations')->where('migration', '2026_06_13_000007_create_v_storages_table')->value('batch');
        $upgrade = $db->table('migrations')->where('migration', '2026_10_07_000004_node_data_contract')->value('batch');

        return $base !== null && $base === $upgrade;
    }
};
