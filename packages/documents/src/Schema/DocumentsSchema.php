<?php

declare(strict_types=1);

namespace Vaulter\Documents\Schema;

use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\IdStrategy;
use Vaulter\Schema\HostKeyColumns;
use Vaulter\Schema\ModuleSchema;
use Vaulter\Schema\SchemaHelpers;
use Vaulter\Schema\ValueObjects\KeyColumnExpectation;

/** The tables the documents module owns: node comments, documents and their revisions. */
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
                default => throw new InvalidArgumentException(sprintf('Unknown documents entity [%s].', $entity)),
            };
        });

        $this->afterCreate(entity: $entity);
    }

    public function drop(string $entity): void
    {
        // A document and its revisions reference each other (head FK one way, owner FK the other), so the head FK goes
        // first whichever side drops.
        if (in_array($entity, ['documents', 'document_revisions'], true)) {
            $this->helpers->dropHeadForeignKey(aggregate: 'documents');
        }

        $this->helpers->schemaConnection()->dropIfExists($this->helpers->table($entity));
    }

    /**
     * v_node_comments — the NODE-attached, threaded, anchorable comment (docs/adr/0014-legacy-docvault-strangler.md
     * шаг 1 — parity with the legacy document_id-attached v_document_comments, which
     * stays untouched). Gated on 'comments'. A comment hangs off ANY node via node_id
     * (cascade); parent_id is a self-FK for threading (a reply cascades when its parent
     * is deleted). The author is a morph to a REGISTERED alias (user|…) written via
     * getMorphClass(), never a raw FQCN. anchor is the optional text-range descriptor
     * (jsonb on Postgres, json/text elsewhere). resolved_at/resolved_by mark a thread
     * closed. No soft-delete — a delete is permanent (legacy parity: hard delete).
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

        $table->timestamps();

        $name = $table->getTable();

        // The per-node comment feed (oldest-first listing): node_id is the scope,
        // created_at the cursor. Index names are derived from the table so a per-panel
        // prefixed table never collides.
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
        $table->integer('schema_version');
        $ids->reference(table: $table, column: 'current_revision_id', nullable: true);
        // Monotonic: pruning old checkpoints never hands a number out twice.
        $table->integer('revision_count')->default(0);
        $this->helpers->counterColumn(table: $table, column: 'collab_epoch');
        $this->helpers->counterColumn(table: $table, column: 'state_version');
        $this->helpers->counterColumn(table: $table, column: 'checkpoint_state_version');

        // The canonical JSON projection of the working state. jsonb on Postgres,
        // json on MySQL, text on SQLite.
        $table->jsonb('content')->nullable();
        $table->char('content_hash', 64)->nullable();
        $table->integer('content_size')->nullable();
        $this->helpers->binaryColumn(table: $table, column: 'yjs_state');
        $table->integer('yjs_state_size')->nullable();
        $table->timestamp('last_checkpoint_at')->nullable();

        HostKeyColumns::add(table: $table, column: 'created_by', nullable: true, index: false);
        HostKeyColumns::add(table: $table, column: 'updated_by', nullable: true, index: false);
        $table->timestamps();

        $name = $table->getTable();

        $table->unique('node_id', $name.'_node_uniq');
        // Lets doctor list documents of types no installed package registers.
        $table->index('type', $name.'_type_idx');

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
        $table->integer('schema_version');

        $table->jsonb('content')->nullable();
        $table->char('content_hash', 64)->nullable();
        $table->integer('content_size')->nullable();
        $this->helpers->binaryColumn(table: $table, column: 'yjs_state');
        $table->integer('yjs_state_size')->nullable();
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

    /**
     * The OPTIONAL gin(content jsonb_path_ops) index for document-revision content
     * containment queries. Postgres-only (gin + jsonb); on SQLite/MySQL
     * content is a plain json column and the index is skipped — the history is read
     * by (document_id, number) / (document_id, is_autosave), all btree-covered.
     */
    private function addDocumentContentGinIndex(string $table): void
    {
        if ($this->helpers->schemaConnection()->getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->helpers->dbConnection()->statement(sprintf('CREATE INDEX %s_content_gin ON %s USING gin (content jsonb_path_ops)', $table, $table));
    }

    private function afterCreate(string $entity): void
    {
        if ($entity === 'document_revisions') {
            $this->addDocumentContentGinIndex(table: $this->helpers->table('document_revisions'));
            $this->helpers->addHeadForeignKey(aggregate: 'documents', revisions: 'document_revisions', owner: 'document_id');
        }
    }
}
