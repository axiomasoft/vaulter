<?php

declare(strict_types=1);

namespace Vaulter\Documents\Schema;

use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;
use Packman\Attributes\Internal;
use Vaulter\Contracts\IdStrategy;
use Vaulter\Schema\HostKeyColumns;
use Vaulter\Schema\ModuleSchema;
use Vaulter\Schema\SchemaHelpers;
use Vaulter\Schema\ValueObjects\KeyColumnExpectation;

/** The tables the documents module owns: node comments and document metadata, payloads and sync states. */
#[Internal]
final class DocumentsSchema implements ModuleSchema
{
    public function __construct(private readonly SchemaHelpers $helpers) {}

    /** @return list<string> the entity keys of the tables this schema owns, in dependency order */
    public function entities(): array
    {
        return [
            'node_comments',
            'documents',
            'document_revisions',
            'document_payloads',
            'document_sync_states',
            'document_revision_payloads',
            'document_revision_sync_states',
        ];
    }

    /** @return list<KeyColumnExpectation> the key columns of the module's tables and the kind each must have: `HK` host key, `REF` entity id, `STRING` of a length */
    public function keyColumns(): array
    {
        return [
            new KeyColumnExpectation('node_comments', 'tenant_id', 'HK'),
            new KeyColumnExpectation('node_comments', 'author_id', 'HK'),
            new KeyColumnExpectation('node_comments', 'resolved_by', 'HK'),
            new KeyColumnExpectation('documents', 'tenant_id', 'HK'),
            new KeyColumnExpectation('documents', 'created_by', 'HK'),
            new KeyColumnExpectation('documents', 'updated_by', 'HK'),
            new KeyColumnExpectation('document_revisions', 'tenant_id', 'HK'),
            new KeyColumnExpectation('document_revisions', 'created_by', 'HK'),
            new KeyColumnExpectation('document_payloads', 'document_id', 'REF'),
            new KeyColumnExpectation('document_sync_states', 'document_id', 'REF'),
            new KeyColumnExpectation('document_revision_payloads', 'revision_id', 'REF'),
            new KeyColumnExpectation('document_revision_sync_states', 'revision_id', 'REF'),
        ];
    }

    public function create(string $entity): void
    {
        $ids = $this->helpers->ids();

        $this->helpers->schemaConnection()->create($this->helpers->table($entity), function (Blueprint $table) use ($ids, $entity): void {
            match ($entity) {
                'node_comments' => $this->defineNodeComments(table: $table, ids: $ids),
                'documents' => $this->defineDocuments(table: $table, ids: $ids),
                'document_revisions' => $this->defineDocumentRevisions(table: $table, ids: $ids),
                'document_payloads' => $this->definePayload($table, $ids, 'documents', 'document_id'),
                'document_sync_states' => $this->defineSyncState($table, $ids, working: true),
                'document_revision_payloads' => $this->definePayload($table, $ids, 'document_revisions', 'revision_id'),
                'document_revision_sync_states' => $this->defineSyncState($table, $ids, working: false),
                default => throw new InvalidArgumentException(sprintf('Unknown documents entity [%s].', $entity)),
            };
        });

        $this->afterCreate(entity: $entity);
    }

    public function drop(string $entity): void
    {
        // A document and its revisions reference each other (head FK one way, owner FK the other), so the head FK goes
        // first whichever side drops.
        $schema = $this->helpers->schemaConnection();
        $name = $this->helpers->table($entity);
        if (in_array($entity, ['documents', 'document_revisions'], true)) {
            if ($this->driver() === 'sqlite') {
                // The head FK was declared at CREATE time. Drop the two sides in
                // reverse dependency order without attempting a cascading delete.
                $schema->withoutForeignKeyConstraints(static fn () => $schema->dropIfExists($name));

                return;
            }
            $this->helpers->dropHeadForeignKey(aggregate: 'documents');
        }

        $schema->dropIfExists($name);
    }

    /**
     * v_node_comments — the NODE-attached, threaded, anchorable comment. Gated on 'comments'. A comment hangs off ANY node via node_id
     * (cascade); parent_id is a self-FK for threading (a reply cascades when its parent
     * is deleted). The author is a morph to a REGISTERED alias (user|…) written via
     * getMorphClass(), never a raw FQCN. anchor is the optional text-range descriptor
     * (jsonb on Postgres, json/text elsewhere). resolved_at/resolved_by mark a thread
     * closed. No soft-delete — a delete is permanent.
     */
    private function defineNodeComments(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->helpers->tenant(table: $table);
        $ids->reference(table: $table, column: 'node_id');

        // Threading: a reply points at its parent comment (same table). Nullable —
        // a top-level comment has no parent.
        $ids->reference(table: $table, column: 'parent_id', nullable: true);

        // Author morph: a registered alias (user|…), never a raw FQCN — the Data layer
        // writes $user->getMorphClass() (honours the host's enforceMorphMap).
        HostKeyColumns::add(table: $table, column: 'author_id', nullable: false, index: true);
        $table->string('author_type');

        $table->text('body');

        // The optional anchor (a text-range / selection descriptor). jsonb on Postgres
        // (json/text elsewhere) — never queried by shape, so no gin index.
        $table->jsonb('anchor')->nullable();

        // Resolution tombstone: when + by whom the thread was marked resolved.
        $table->timestamp('resolved_at')->nullable();
        HostKeyColumns::add(table: $table, column: 'resolved_by', nullable: true, index: false);

        // The comment's own version: resolve and delete are conditional on it (If-Match); a resolution raises
        // it with UPDATE … WHERE version = ?.
        $this->helpers->counterColumn(table: $table, column: 'version');

        $table->timestamps();

        $name = $table->getTable();

        // The per-node comment feed (oldest-first listing): node_id is the scope,
        // created_at the cursor. Index names are derived from the table so a per-panel
        // prefixed table never collides.
        $this->helpers->foreignKeyIndex(table: $table, column: 'node_id');
        $table->index(['node_id', 'created_at'], $name.'_node_created_idx');

        // Threading lookup: a parent's replies.
        $table->index('parent_id', $name.'_parent_idx');

        $table->foreign('node_id')
            ->references('id')
            ->on($this->helpers->table('nodes'))
            ->cascadeOnDelete();

        // Self-FK: deleting a parent comment cascades to its replies.
        $table->foreign('parent_id')
            ->references('id')
            ->on($this->helpers->table('node_comments'))
            ->cascadeOnDelete();
    }

    /**
     * v_documents — the 1:1 aggregate of a kind=document node. It holds the WORKING
     * state (the latest content and Yjs state every editor sees) apart from the
     * immutable checkpoints in v_document_revisions, so autosave never rewrites
     * history and the head checkpoint can be cached by id. state_version and
     * collab_epoch are the content validators; they are independent of
     * v_nodes.sequence, so renaming a document never invalidates an editor.
     *
     * The composite head FK (current_revision_id, id) → v_document_revisions(id,
     * document_id) is added in afterCreate('document_revisions'): the revisions
     * table does not exist yet when this one is created.
     */
    private function defineDocuments(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->helpers->tenant(table: $table);
        $ids->reference(table: $table, column: 'node_id');

        $table->string('type', 64);
        $this->defineSchemaVersion($table);
        $mode = $this->driver() === 'mysql' ? 'CAST(write_mode AS BINARY)' : 'write_mode';
        $table->rawColumn('write_mode', "VARCHAR(16) CHECK({$mode} IN ('snapshot', 'journal'))")->default('snapshot');
        $ids->reference(table: $table, column: 'current_revision_id', nullable: true);
        // Monotonic: pruning old checkpoints never hands a number out twice.
        $table->integer('revision_count')->default(0);
        $this->helpers->counterColumn(table: $table, column: 'collab_epoch');
        $this->helpers->counterColumn(table: $table, column: 'state_version');
        $this->helpers->counterColumn(table: $table, column: 'checkpoint_state_version');
        $table->timestamp('last_checkpoint_at')->nullable();

        HostKeyColumns::add(table: $table, column: 'created_by', nullable: true, index: false);
        HostKeyColumns::add(table: $table, column: 'updated_by', nullable: true, index: false);
        $table->timestamps();

        $name = $table->getTable();

        $table->unique('node_id', $name.'_node_uniq');
        // Lets doctor list documents of types no installed package registers.
        $table->index('type', $name.'_type_idx');
        $table->index(['current_revision_id', 'id'], $name.'_head_fk_idx');
        if ($this->driver() === 'sqlite') {
            $table->foreign(['current_revision_id', 'id'], $name.'_head_fk')
                ->references(['id', 'document_id'])
                ->on($this->helpers->table('document_revisions'))
                ->restrictOnDelete();
        }

        $table->foreign('node_id')
            ->references('id')
            ->on($this->helpers->table('nodes'))
            ->cascadeOnDelete();
    }

    /**
     * v_document_revisions — immutable checkpoints of a document's working state.
     * After INSERT only keep_forever changes; a restore copies a checkpoint forward
     * into a new one and records restored_from_revision_id. number is monotonic per
     * document (unique). Ids survive the 0.2 upgrade, so activity and outbox
     * payloads that name a revision id stay valid.
     */
    private function defineDocumentRevisions(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->helpers->tenant(table: $table);
        $ids->reference(table: $table, column: 'document_id');
        $table->integer('number');
        $table->string('type', 64);
        $this->helpers->counterColumn(table: $table, column: 'collab_epoch');
        $this->defineSchemaVersion($table);

        // The working state version this checkpoint captured.
        $this->helpers->counterColumn(table: $table, column: 'state_version');

        $table->string('label')->nullable();
        $table->boolean('is_autosave')->default(false);
        $table->boolean('keep_forever')->default(false);
        $table->string('status', 12)->default('committed');
        $ids->reference(table: $table, column: 'restored_from_revision_id', nullable: true);
        HostKeyColumns::add(table: $table, column: 'created_by', nullable: true, index: false);
        $table->timestamp('created_at')->nullable();

        $name = $table->getTable();

        $table->unique(['document_id', 'number'], $name.'_no_uniq');
        // The target of the composite head FK on v_documents.
        $table->unique(['id', 'document_id'], $name.'_id_doc_uniq');
        $table->index(['document_id', 'is_autosave', 'created_at'], $name.'_history_idx');

        $table->foreign('document_id')
            ->references('id')
            ->on($this->helpers->table('documents'))
            ->cascadeOnDelete();

        $table->foreign('restored_from_revision_id', $name.'_restored_fk')
            ->references('id')
            ->on($name)
            ->nullOnDelete();
    }

    private function defineSchemaVersion(Blueprint $table): void
    {
        $check = $this->integerAtLeast('schema_version', 1);
        $table->rawColumn('schema_version', "INTEGER CONSTRAINT {$table->getTable()}_schema_version_positive CHECK({$check})");
    }

    private function defineOwner(Blueprint $table, IdStrategy $ids, string $owner, string $key): void
    {
        $ids->reference(table: $table, column: $key);
        // The primary key already indexes the owner foreign key.
        $table->getColumns()[0]->index(false);
        $table->primary($key);
        $table->foreign($key, $table->getTable().'_owner_fk')
            ->references('id')
            ->on($this->helpers->table($owner))
            ->cascadeOnDelete();
    }

    private function definePayload(Blueprint $table, IdStrategy $ids, string $owner, string $key): void
    {
        $this->defineOwner($table, $ids, $owner, $key);
        $table->jsonb('content')->nullable();
        $table->char('content_hash', 64)->nullable();
        $this->checkedColumn($table, 'content_size', 'INTEGER', $this->payloadChecks());
    }

    private function defineSyncState(Blueprint $table, IdStrategy $ids, bool $working): void
    {
        $this->defineOwner($table, $ids, $working ? 'documents' : 'document_revisions', $working ? 'document_id' : 'revision_id');
        $table->string('collab_protocol')->nullable();
        $table->string('collab_state_format')->nullable();
        $this->helpers->binaryColumn($table, 'collab_state');
        $checks = $this->syncChecks($working);
        if ($working) {
            $table->integer('collab_state_size')->nullable();
            $table->string('pending_protocol')->nullable();
            $table->string('pending_state_format')->nullable();
            $table->integer('pending_schema_version')->nullable();
            $table->bigInteger('pending_generation')->nullable();
            $this->checkedColumn($table, 'pending_through_seq', 'BIGINT', $checks);

            if ($this->driver() === 'mysql') {
                $table->index(['pending_generation', 'document_id'], $table->getTable().'_pending_idx');
            }

            return;
        }
        $this->checkedColumn($table, 'collab_state_size', 'INTEGER', $checks);
    }

    /** @return array<string, string> */
    private function payloadChecks(): array
    {
        return ['complete' => '(content IS NULL AND content_hash IS NULL AND content_size IS NULL) OR '
            .'(content IS NOT NULL AND content_hash IS NOT NULL AND content_size IS NOT NULL AND '.$this->integerAtLeast('content_size', 0).')'];
    }

    /** @return array<string, string> */
    private function syncChecks(bool $working): array
    {
        $byteLength = $this->driver() === 'sqlite' ? 'length(CAST(collab_state AS BLOB))' : 'octet_length(collab_state)';
        $checks = [
            'protocol' => '(collab_protocol IS NULL AND collab_state_format IS NULL AND collab_state IS NULL) OR '
                .'('.$this->nonblank('collab_protocol').' AND '.$this->nonblank('collab_state_format').')',
            'binary_size' => '(collab_state IS NULL AND collab_state_size IS NULL) OR '
                .'(collab_state IS NOT NULL AND collab_state_size IS NOT NULL AND collab_state_size = '.$byteLength.')',
            'present' => $working ? 'collab_protocol IS NOT NULL OR pending_protocol IS NOT NULL' : 'collab_protocol IS NOT NULL',
        ];

        if ($working) {
            $checks['pending'] = '(pending_protocol IS NULL AND pending_state_format IS NULL AND pending_schema_version IS NULL AND pending_generation IS NULL AND pending_through_seq IS NULL) OR '
                .'('.$this->nonblank('pending_protocol').' AND '.$this->nonblank('pending_state_format')
                .' AND pending_schema_version IS NOT NULL AND '.$this->integerAtLeast('pending_schema_version', 1)
                .' AND pending_generation IS NOT NULL AND '.$this->integerAtLeast('pending_generation', 0)
                .' AND pending_through_seq IS NOT NULL AND '.$this->integerAtLeast('pending_through_seq', 1).')';
        }

        return $checks;
    }

    private function nonblank(string $column): string
    {
        $test = match ($this->driver()) {
            'pgsql' => "{$column} ~ '[^[:space:]]'",
            'mysql' => "{$column} REGEXP '[^[:space:]]'",
            default => "length(trim({$column}, char(9, 10, 11, 12, 13, 32))) > 0",
        };

        return "{$column} IS NOT NULL AND {$test}";
    }

    private function integerAtLeast(string $column, int $minimum): string
    {
        $type = $this->driver() === 'sqlite' ? "typeof({$column}) = 'integer' AND " : '';

        return "{$type}{$column} >= {$minimum}";
    }

    /** @param array<string, string> $checks */
    private function checkedColumn(Blueprint $table, string $column, string $type, array $checks): void
    {
        // MySQL requires checks that reference other columns to be table constraints.
        if ($this->driver() !== 'mysql') {
            foreach ($checks as $suffix => $check) {
                $type .= " CONSTRAINT {$table->getTable()}_{$suffix} CHECK({$check})";
            }
        }

        $table->rawColumn($column, $type)->nullable();
    }

    private function afterCreate(string $entity): void
    {
        if ($entity === 'document_revisions') {
            $this->helpers->addHeadForeignKey(aggregate: 'documents', revisions: 'document_revisions', owner: 'document_id');
        }

        $connection = $this->helpers->dbConnection();
        $name = $this->helpers->table($entity);
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable($name);

        if ($this->driver() === 'mysql') {
            $checks = match ($entity) {
                'document_payloads', 'document_revision_payloads' => $this->payloadChecks(),
                'document_sync_states' => $this->syncChecks(working: true),
                'document_revision_sync_states' => $this->syncChecks(working: false),
                default => [],
            };
            foreach ($checks as $suffix => $expression) {
                $constraint = $grammar->wrap($name.'_'.$suffix);
                $connection->statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK({$expression})");
            }
        }

        if ($entity === 'document_sync_states' && $this->driver() !== 'mysql') {
            $index = $grammar->wrap($name.'_pending_idx');
            $connection->statement("CREATE INDEX {$index} ON {$table} (pending_generation, document_id) WHERE pending_generation IS NOT NULL");
        }

        if ($entity === 'document_revision_payloads' && $this->driver() === 'pgsql') {
            $index = $grammar->wrap($name.'_content_gin');
            $connection->statement("CREATE INDEX {$index} ON {$table} USING gin (content jsonb_path_ops)");
        }
    }

    private function driver(): string
    {
        $driver = $this->helpers->dbConnection()->getDriverName();

        return $driver === 'mariadb' ? 'mysql' : $driver;
    }
}
