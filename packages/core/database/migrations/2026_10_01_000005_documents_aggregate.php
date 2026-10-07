<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Schema\UpgradePreflightException;
use Vaulter\Schema\HostKeyColumns;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Support\Identity;

/**
 * 0.2 kept every document version in v_node_document_versions and the head in the
 * polymorphic v_nodes.current_version_id, which a file revision with the same id
 * could shadow. This creates the document aggregate: v_documents (working state +
 * head) and v_document_revisions (immutable checkpoints). Every legacy version
 * keeps its primary key as a revision id, so activity and outbox payloads naming a
 * version stay valid; the head's payload becomes the working state.
 *
 * The table definitions are literal copies of the 0.3 schema at the time of this
 * migration — they must not follow later SchemaBuilder edits. Re-runnable: an
 * interrupted backfill resumes, a complete aggregate is left alone, a foreign or
 * partial structure stops with the list of differences.
 */
return new class extends Migration
{
    private const int BATCH = 500;

    private const array DOCUMENT_COLUMNS = ['id', 'tenant_id', 'node_id', 'type', 'schema_version', 'current_revision_id', 'revision_count', 'collab_epoch', 'state_version', 'checkpoint_state_version', 'content', 'content_hash', 'content_size', 'yjs_state', 'yjs_state_size', 'last_checkpoint_at', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    private const array REVISION_COLUMNS = ['id', 'tenant_id', 'document_id', 'number', 'schema_version', 'content', 'content_hash', 'content_size', 'yjs_state', 'yjs_state_size', 'state_version', 'label', 'is_autosave', 'keep_forever', 'status', 'restored_from_revision_id', 'created_by', 'created_at'];

    public function up(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $documents = app(DatabaseConfig::class)->table('documents');
        $revisions = app(DatabaseConfig::class)->table('document_revisions');
        $legacy = $this->legacyTable();
        $present = $schema->hasTable($documents) || $schema->hasTable($revisions);

        // The next, already-released migration (#6) reads v_nodes.deleted_at, which a
        // fresh 0.3 table no longer has; give it the column back for the duration
        // of the upgrade chain. 2026_10_07_000001 removes it again.
        $nodes = app(DatabaseConfig::class)->table('nodes');
        if ($schema->hasTable($nodes) && !$schema->hasColumn($nodes, 'deleted_at')) {
            $schema->table($nodes, fn (Blueprint $table) => $table->timestamp('deleted_at')->nullable());
        }

        if ($present) {
            $problems = $this->structureProblems();
            if ($problems !== []) {
                throw new UpgradePreflightException($problems);
            }

            if ($legacy === null) {
                $this->addHeadForeignKey($db);

                return;
            }
        }

        $this->preflight($db, $legacy);

        if (!$present) {
            $this->createTables($db);
        }

        if ($legacy !== null) {
            $this->backfill($db, $legacy);
            $schema->drop($legacy);
            $this->restartSequence($db, $revisions);
        }

        $this->addHeadForeignKey($db);
    }

    public function down(): void
    {
        if ($this->freshInstall()) {
            return;
        }

        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $documents = app(DatabaseConfig::class)->table('documents');
        $revisions = app(DatabaseConfig::class)->table('document_revisions');
        $legacy = app(DatabaseConfig::class)->table('node_document_versions');

        if ($schema->hasTable($legacy) || !$schema->hasTable($documents) || !$schema->hasTable($revisions)) {
            return;
        }

        $this->createLegacyTable($db, $legacy);
        $format = $this->formatSql('d.type');
        $db->statement(
            "INSERT INTO {$legacy} (id, tenant_id, node_id, version_no, content, yjs_state, yjs_state_size, format, content_hash, snapshot_label, is_autosave, status, keep_forever, created_by, created_at) "
            ."SELECT r.id, r.tenant_id, d.node_id, r.number, r.content, r.yjs_state, r.yjs_state_size, {$format}, r.content_hash, r.label, r.is_autosave, r.status, r.keep_forever, r.created_by, r.created_at "
            ."FROM {$revisions} r JOIN {$documents} d ON d.id = r.document_id",
        );
        $this->restartSequence($db, $legacy);

        // 0.2 has no working state: a state written after the head checkpoint
        // becomes the newest 0.2 version so rolling back loses no content.
        $ahead = $db->table($documents)->whereColumn('state_version', '>', 'checkpoint_state_version')->orderBy('id')->get(['id', 'node_id', 'revision_count']);
        $nodes = app(DatabaseConfig::class)->table('nodes');
        foreach ($ahead as $document) {
            $id = app(IdStrategyResolver::class)->resolve()->newId();
            $columns = ($id === null ? '' : 'id, ').'tenant_id, node_id, version_no, content, yjs_state, yjs_state_size, format, content_hash, snapshot_label, is_autosave, status, keep_forever, created_by, created_at';
            $values = ($id === null ? '' : '?, ').'d.tenant_id, d.node_id, d.revision_count + 1, d.content, d.yjs_state, d.yjs_state_size, '.$format.", d.content_hash, NULL, ?, 'committed', ?, d.updated_by, d.updated_at";
            $db->statement("INSERT INTO {$legacy} ({$columns}) SELECT {$values} FROM {$documents} d WHERE d.id = ?", [...($id === null ? [] : [$id]), true, false, $document->id]);
            if ($schema->hasColumn($nodes, 'current_version_id')) {
                $versionId = $db->table($legacy)->where('node_id', $document->node_id)->orderByDesc('version_no')->value('id');
                $db->table($nodes)->where('id', $document->node_id)->update(['current_version_id' => $versionId]);
            }
        }

        $this->dropHeadForeignKey($db);
        $schema->drop($revisions);
        $schema->drop($documents);
    }

    /** The legacy version table: renamed to the canonical prefix by #2, else its configured name. */
    private function legacyTable(): ?string
    {
        $schema = app(VaulterDatabase::class)->connection()->getSchemaBuilder();
        foreach ([app(DatabaseConfig::class)->table('node_document_versions'), app(DatabaseConfig::class)->legacyTable('node_document_versions')] as $table) {
            if ($schema->hasTable($table)) {
                return $table;
            }
        }

        return null;
    }

    /**
     * Every document needs a legacy version to become its working state, a stored
     * head must be one of its own versions, and no version may hang off a node that
     * is not a document — anything else is corrupt data this migration will not
     * guess about.
     */
    private function preflight(Connection $db, ?string $legacy): void
    {
        $nodes = app(DatabaseConfig::class)->table('nodes');
        $documents = app(DatabaseConfig::class)->table('documents');
        $problems = [];

        $orphans = $db->table($nodes.' as n')->where('n.kind', 'document')
            ->when($db->getSchemaBuilder()->hasTable($documents), fn (Builder $query): Builder => $query->whereNotExists(fn (Builder $sub): Builder => $sub->from($documents.' as d')->whereColumn('d.node_id', 'n.id')))
            ->when($legacy !== null, fn (Builder $query): Builder => $query->whereNotExists(fn (Builder $sub): Builder => $sub->from($legacy.' as v')->whereColumn('v.node_id', 'n.id')))
            ->orderBy('n.id')->pluck('n.id')->all();
        if ($orphans !== []) {
            $problems[] = 'document nodes without any version ['.implode(', ', array_map(Identity::stringify(...), $orphans)).']';
        }

        if ($legacy !== null) {
            if ($db->getSchemaBuilder()->hasColumn($nodes, 'current_version_id')) {
                $foreignHeads = $db->table($nodes.' as n')->where('n.kind', 'document')->whereNotNull('n.current_version_id')
                    ->whereNotExists(fn (Builder $sub): Builder => $sub->from($legacy.' as v')->whereColumn('v.id', 'n.current_version_id')->whereColumn('v.node_id', 'n.id'))
                    ->orderBy('n.id')->pluck('n.id')->all();
                if ($foreignHeads !== []) {
                    $problems[] = 'document nodes whose head is not one of their versions ['.implode(', ', array_map(Identity::stringify(...), $foreignHeads)).']';
                }
            }

            $strays = $db->table($legacy.' as v')->join($nodes.' as n', 'n.id', '=', 'v.node_id')->where('n.kind', '<>', 'document')
                ->orderBy('v.id')->pluck('v.id')->all();
            if ($strays !== []) {
                $problems[] = 'document versions attached to non-document nodes ['.implode(', ', array_map(Identity::stringify(...), $strays)).']';
            }
        }

        if ($problems !== []) {
            throw new UpgradePreflightException($problems);
        }
    }

    private function backfill(Connection $db, string $legacy): void
    {
        $nodes = app(DatabaseConfig::class)->table('nodes');
        $documents = app(DatabaseConfig::class)->table('documents');
        $revisions = app(DatabaseConfig::class)->table('document_revisions');
        $ids = app(IdStrategyResolver::class)->resolve();
        $hasHead = $db->getSchemaBuilder()->hasColumn($nodes, 'current_version_id');
        $cursor = null;

        do {
            $batch = $db->table($nodes)->where('kind', 'document')
                ->when($cursor !== null, fn (Builder $query): Builder => $query->where('id', '>', $cursor))
                ->orderBy('id')->limit(self::BATCH)
                ->get(['id', 'tenant_id', 'created_by', 'created_at', 'updated_at', ...($hasHead ? ['current_version_id'] : [])]);

            foreach ($batch as $node) {
                $cursor = $node->id;
                if ($db->table($documents)->where('node_id', $node->id)->exists()) {
                    continue;
                }

                $headId = $hasHead ? $node->current_version_id : null;
                $head = $db->table($legacy)->where('node_id', $node->id)
                    ->when($headId !== null, fn (Builder $query): Builder => $query->where('id', $headId), fn (Builder $query): Builder => $query->orderByDesc('version_no'))
                    ->first(['id', 'format', 'content', 'content_hash', 'created_by', 'created_at']);
                $max = $db->table($legacy)->where('node_id', $node->id)->max('version_no');
                $count = is_numeric($max) ? (int) $max : 0;
                if ($head === null) {
                    throw new UpgradePreflightException(['document node ['.Identity::stringify($node->id).'] lost its versions during the upgrade']);
                }

                $content = is_string($head->content) ? $head->content : null;
                $row = [
                    'tenant_id' => $node->tenant_id,
                    'node_id' => $node->id,
                    'type' => $this->typeFor(is_string($head->format) ? $head->format : ''),
                    'schema_version' => 1,
                    'current_revision_id' => $head->id,
                    'revision_count' => $count,
                    'collab_epoch' => 0,
                    'state_version' => 1,
                    'checkpoint_state_version' => 1,
                    'content' => $content,
                    'content_hash' => $head->content_hash,
                    'content_size' => $content === null ? null : strlen((string) json_encode(json_decode($content, true))),
                    'last_checkpoint_at' => $head->created_at,
                    'created_by' => $node->created_by,
                    'updated_by' => $head->created_by,
                    'created_at' => $node->created_at,
                    'updated_at' => $node->updated_at,
                ];
                $id = $ids->newId();
                if ($id === null) {
                    $db->table($documents)->insert($row);
                } else {
                    $db->table($documents)->insert(['id' => $id, ...$row]);
                }
            }

            $nodeIds = $batch->pluck('id')->all();
            if ($nodeIds === []) {
                break;
            }

            // Binary Yjs state is copied by the database, byte for byte.
            $marks = implode(', ', array_fill(0, count($nodeIds), '?'));
            $db->statement(
                "UPDATE {$documents} SET yjs_state = (SELECT v.yjs_state FROM {$legacy} v WHERE v.id = {$documents}.current_revision_id), "
                ."yjs_state_size = (SELECT v.yjs_state_size FROM {$legacy} v WHERE v.id = {$documents}.current_revision_id) "
                ."WHERE node_id IN ({$marks})",
                $nodeIds,
            );
            $db->statement(
                "INSERT INTO {$revisions} (id, tenant_id, document_id, number, schema_version, content, content_hash, content_size, yjs_state, yjs_state_size, state_version, label, is_autosave, keep_forever, status, restored_from_revision_id, created_by, created_at) "
                .'SELECT v.id, v.tenant_id, d.id, v.version_no, 1, v.content, v.content_hash, CASE WHEN v.id = d.current_revision_id THEN d.content_size ELSE NULL END, v.yjs_state, v.yjs_state_size, '
                .'CASE WHEN v.id = d.current_revision_id THEN 1 ELSE 0 END, v.snapshot_label, v.is_autosave, v.keep_forever, v.status, NULL, v.created_by, v.created_at '
                ."FROM {$legacy} v JOIN {$documents} d ON d.node_id = v.node_id "
                ."WHERE v.node_id IN ({$marks}) AND NOT EXISTS (SELECT 1 FROM {$revisions} r WHERE r.id = v.id)",
                $nodeIds,
            );
        } while ($batch->count() === self::BATCH);
    }

    /** The 0.2 format label → the 0.3 document type key (literal copy of DocumentTypeKey::fromFormat). */
    private function typeFor(string $format): string
    {
        return match ($format) {
            'richtext', 'table', 'template' => 'vaulter/rich-text',
            'markdown' => 'vaulter/markdown',
            'univer', 'vaulter/univer' => 'vaulter/spreadsheet',
            default => str_contains($format, '/') ? $format : 'legacy/'.$format,
        };
    }

    /** SQL for the reverse map, type key → 0.2 format label. */
    private function formatSql(string $column): string
    {
        return "CASE WHEN {$column} = 'vaulter/rich-text' THEN 'richtext' WHEN {$column} = 'vaulter/markdown' THEN 'markdown' "
            ."WHEN {$column} = 'vaulter/spreadsheet' THEN 'univer' WHEN {$column} LIKE 'legacy/%' THEN SUBSTR({$column}, 8) ELSE {$column} END";
    }

    private function createTables(Connection $db): void
    {
        $ids = app(IdStrategyResolver::class)->resolve();
        $documents = app(DatabaseConfig::class)->table('documents');
        $revisions = app(DatabaseConfig::class)->table('document_revisions');
        $nodes = app(DatabaseConfig::class)->table('nodes');

        $db->getSchemaBuilder()->create($documents, function (Blueprint $table) use ($db, $ids, $documents, $nodes): void {
            $ids->key(table: $table);
            HostKeyColumns::add(table: $table, column: 'tenant_id', nullable: true, index: true);
            $ids->reference(table: $table, column: 'node_id');
            $table->string('type', 64);
            $table->integer('schema_version');
            $ids->reference(table: $table, column: 'current_revision_id', nullable: true);
            $table->integer('revision_count')->default(0);
            $this->counter($db, $table, 'collab_epoch');
            $this->counter($db, $table, 'state_version');
            $this->counter($db, $table, 'checkpoint_state_version');
            $table->jsonb('content')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->integer('content_size')->nullable();
            $this->binary($db, $table, 'yjs_state');
            $table->integer('yjs_state_size')->nullable();
            $table->timestamp('last_checkpoint_at')->nullable();
            HostKeyColumns::add(table: $table, column: 'created_by', nullable: true, index: false);
            HostKeyColumns::add(table: $table, column: 'updated_by', nullable: true, index: false);
            $table->timestamps();
            $table->unique('node_id', $documents.'_node_uniq');
            $table->index('type', $documents.'_type_idx');
            $table->foreign('node_id')->references('id')->on($nodes)->cascadeOnDelete();
        });

        $db->getSchemaBuilder()->create($revisions, function (Blueprint $table) use ($db, $ids, $documents, $revisions): void {
            $ids->key(table: $table);
            HostKeyColumns::add(table: $table, column: 'tenant_id', nullable: true, index: true);
            $ids->reference(table: $table, column: 'document_id');
            $table->integer('number');
            $table->integer('schema_version');
            $table->jsonb('content')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->integer('content_size')->nullable();
            $this->binary($db, $table, 'yjs_state');
            $table->integer('yjs_state_size')->nullable();
            $this->counter($db, $table, 'state_version');
            $table->string('label')->nullable();
            $table->boolean('is_autosave')->default(false);
            $table->boolean('keep_forever')->default(false);
            $table->string('status', 12)->default('committed');
            $ids->reference(table: $table, column: 'restored_from_revision_id', nullable: true);
            HostKeyColumns::add(table: $table, column: 'created_by', nullable: true, index: false);
            $table->timestamp('created_at')->nullable();
            $table->unique(['document_id', 'number'], $revisions.'_no_uniq');
            $table->unique(['id', 'document_id'], $revisions.'_id_doc_uniq');
            $table->index(['document_id', 'is_autosave', 'created_at'], $revisions.'_history_idx');
            $table->foreign('document_id')->references('id')->on($documents)->cascadeOnDelete();
            $table->foreign('restored_from_revision_id', $revisions.'_restored_fk')->references('id')->on($revisions)->nullOnDelete();
        });

        if ($db->getDriverName() === 'pgsql') {
            $db->statement(sprintf('CREATE INDEX %s_content_gin ON %s USING gin (content jsonb_path_ops)', $revisions, $revisions));
        }
    }

    private function createLegacyTable(Connection $db, string $legacy): void
    {
        $ids = app(IdStrategyResolver::class)->resolve();
        $nodes = app(DatabaseConfig::class)->table('nodes');

        $db->getSchemaBuilder()->create($legacy, function (Blueprint $table) use ($db, $ids, $legacy, $nodes): void {
            $ids->key(table: $table);
            HostKeyColumns::add(table: $table, column: 'tenant_id', nullable: true, index: true);
            $ids->reference(table: $table, column: 'node_id');
            $table->integer('version_no');
            $table->jsonb('content')->nullable();
            $this->binary($db, $table, 'yjs_state');
            $table->integer('yjs_state_size')->nullable();
            $table->string('format', 16);
            $table->char('content_hash', 64)->nullable();
            $table->string('snapshot_label')->nullable();
            $table->boolean('is_autosave')->default(false);
            $table->string('status', 12)->default('committed')->index();
            $table->boolean('keep_forever')->default(false);
            $table->json('extra_data')->nullable();
            HostKeyColumns::add(table: $table, column: 'created_by', nullable: true, index: false);
            $table->timestamp('created_at')->nullable();
            $table->unique(['node_id', 'version_no'], $legacy.'_no_uniq');
            $table->index(['node_id', 'is_autosave', 'created_at'], $legacy.'_history_idx');
            $table->foreign('node_id')->references('id')->on($nodes)->cascadeOnDelete();
        });

        if ($db->getDriverName() === 'pgsql') {
            $db->statement(sprintf('CREATE INDEX %s_content_gin ON %s USING gin (content jsonb_path_ops)', $legacy, $legacy));
        }
    }

    /** A signed BIGINT counter, never negative (SQLite also refuses REAL promotion). */
    private function counter(Connection $db, Blueprint $table, string $column): void
    {
        $type = $db->getDriverName() === 'sqlite' ? "typeof({$column}) = 'integer' AND " : '';
        $table->rawColumn($column, "BIGINT CHECK({$type}{$column} >= 0)")->default(0);
    }

    /** Yjs state: longText with the binary charset on MySQL/MariaDB, binary elsewhere. */
    private function binary(Connection $db, Blueprint $table, string $column): void
    {
        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table->longText($column)->nullable()->charset('binary');

            return;
        }

        $table->binary($column)->nullable();
    }

    /** The composite head FK, PostgreSQL/MySQL only (SQLite cannot add constraints). */
    private function addHeadForeignKey(Connection $db): void
    {
        $documents = app(DatabaseConfig::class)->table('documents');
        $revisions = app(DatabaseConfig::class)->table('document_revisions');
        if ($db->getDriverName() === 'sqlite' || $this->hasForeignKey($db, $documents, $documents.'_head_fk')) {
            return;
        }

        $grammar = $db->getQueryGrammar();
        $db->statement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s, %s) REFERENCES %s (%s, %s) ON DELETE RESTRICT',
            $grammar->wrapTable($documents),
            $grammar->wrap($documents.'_head_fk'),
            $grammar->wrap('current_revision_id'),
            $grammar->wrap('id'),
            $grammar->wrapTable($revisions),
            $grammar->wrap('id'),
            $grammar->wrap('document_id'),
        ));
    }

    private function dropHeadForeignKey(Connection $db): void
    {
        $documents = app(DatabaseConfig::class)->table('documents');
        if ($this->hasForeignKey($db, $documents, $documents.'_head_fk')) {
            $db->getSchemaBuilder()->table($documents, fn (Blueprint $table) => $table->dropForeign($documents.'_head_fk'));
        }
    }

    private function hasForeignKey(Connection $db, string $table, string $name): bool
    {
        return in_array($name, array_column($db->getSchemaBuilder()->getForeignKeys($table), 'name'), true);
    }

    /**
     * Differences between the aggregate tables and the structure this migration
     * creates (the head FK is a separate, idempotent last step).
     *
     * @return list<string>
     */
    private function structureProblems(): array
    {
        $schema = app(VaulterDatabase::class)->connection()->getSchemaBuilder();
        $problems = [];
        $expected = [
            'documents' => [self::DOCUMENT_COLUMNS, ['_node_uniq', '_type_idx']],
            'document_revisions' => [self::REVISION_COLUMNS, ['_no_uniq', '_id_doc_uniq', '_history_idx']],
        ];

        foreach ($expected as $entity => [$columns, $indexes]) {
            $table = app(DatabaseConfig::class)->table($entity);
            if (!$schema->hasTable($table)) {
                $problems[] = $table.' is missing';

                continue;
            }

            $missing = array_diff($columns, $schema->getColumnListing($table));
            if ($missing !== []) {
                $problems[] = $table.' lacks columns ['.implode(', ', $missing).']';
            }

            $names = array_column($schema->getIndexes($table), 'name');
            foreach ($indexes as $suffix) {
                if (!in_array($table.$suffix, $names, true)) {
                    $problems[] = $table.' lacks index '.$table.$suffix;
                }
            }
        }

        return $problems;
    }

    /** Explicit ids were inserted; move a PostgreSQL serial past them. */
    private function restartSequence(Connection $db, string $table): void
    {
        if ($db->getDriverName() !== 'pgsql') {
            return;
        }

        $sequence = $db->selectOne('SELECT pg_get_serial_sequence(?, ?) AS name', [$table, 'id']);
        if (!is_object($sequence) || !is_string($sequence->name ?? null)) {
            return;
        }

        $db->statement("SELECT setval(?, COALESCE((SELECT MAX(id) FROM {$table}), 0) + 1, false)", [$sequence->name]);
    }

    private function freshInstall(): bool
    {
        $db = app(VaulterDatabase::class)->connection();
        if (!$db->getSchemaBuilder()->hasTable('migrations')) {
            return true;
        }

        $base = $db->table('migrations')->where('migration', '2026_06_13_000007_create_v_storages_table')->value('batch');
        $upgrade = $db->table('migrations')->where('migration', '2026_10_01_000005_documents_aggregate')->value('batch');

        return $base !== null && $base === $upgrade;
    }
};
