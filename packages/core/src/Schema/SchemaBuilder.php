<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Contracts\IdStrategy;

/** The shared Vaulter schema. Runtime features never control table existence. */
final class SchemaBuilder
{
    /**
     * The Schema builder for the connection Vaulter's tables live on
     * (`app(\Vaulter\Configuration\DatabaseConfig::class)->connection()`) — null defers to the host's default
     * connection exactly as every call site did before this key existed.
     */
    private function schemaConnection(): Builder
    {
        return Schema::connection(app(DatabaseConfig::class)->connection());
    }

    /**
     * The raw-statement connection counterpart of {@see schemaConnection()}, for
     * the driver-aware `DB::statement(...)` calls (partial/gin indexes, MySQL
     * generated-column fallbacks) that Facade\Schema has no builder method for.
     */
    private function dbConnection(): Connection
    {
        return DB::connection(app(DatabaseConfig::class)->connection());
    }

    /** The entity key the frozen 0.2 migration shells use for the document tables. */
    private const string DOCUMENTS_SLOT = 'node_document_versions';

    /**
     * The base entity keys this builder owns, in dependency order. The generator
     * and createAll()/dropAll() iterate this list independently of runtime features.
     *
     * @var list<string>
     */
    private const array ENTITIES = [
        'drives',
        'nodes',
        'blobs',
        'files',
        'file_revisions',
        'file_revision_blobs',
        'scan_policy_states',
        'scan_obligations',
        'scan_backfill_runs',
        'scan_requests',
        'scan_evidence',

        'node_grants',
        'node_shares',
        'node_comments',
        'node_user_states',
        'documents',
        'document_revisions',
        'upload_sessions',
        'upload_parts',
        'activity',
        'outbox',
        'webhook_endpoints',
        'webhook_deliveries',
        'drive_change_heads',
        'drive_changes',
    ];

    /**
     * Create every table this panel needs in dependency order, independently of
     * runtime feature flags.
     */
    public function createAll(): void
    {
        foreach (self::ENTITIES as $entity) {
            $this->createTable(entity: $entity);
        }
    }

    /**
     * Drop every table this panel owns, in reverse dependency order. Each drop is
     * guarded so a panel whose feature set changed can still be torn down.
     */
    public function dropAll(): void
    {
        foreach (array_reverse(self::ENTITIES) as $entity) {
            $this->dropTable(entity: $entity);
        }
    }

    /**
     * Create a single entity's table for this panel. The base default-panel
     * migrations call these one-per-file so each file stays the canonical column
     * source for its own table while sharing these definitions with the generated
     * per-panel schema.
     */
    public function createTable(string $entity): void
    {
        // The frozen 0.2 migration shells still name the document slot by its old
        // entity key. Creating the aggregate there would be undone: the next frozen
        // shell drops the excised DocVault v_documents table by that very name. The
        // 2026_10_01_000005 migration creates the aggregate instead.
        if ($entity === self::DOCUMENTS_SLOT) {
            return;
        }

        $ids = $this->ids();

        $this->schemaConnection()->create(app(DatabaseConfig::class)->table($entity), function (Blueprint $table) use ($ids, $entity): void {
            match ($entity) {
                'drives' => $this->defineDrives(table: $table, ids: $ids),
                'nodes' => $this->defineNodes(table: $table, ids: $ids),
                'blobs' => $this->defineBlobs(table: $table, ids: $ids),
                'files' => $this->defineFiles(table: $table, ids: $ids),
                'file_revisions' => $this->defineFileRevisions(table: $table, ids: $ids),
                'file_revision_blobs' => $this->defineFileRevisionBlobs(table: $table, ids: $ids),
                'scan_policy_states' => $this->defineScanTable($table, $ids, 'scan_policy_states'),
                'scan_obligations' => $this->defineScanTable($table, $ids, 'scan_obligations'),
                'scan_backfill_runs' => $this->defineScanTable($table, $ids, 'scan_backfill_runs'),
                'scan_requests' => $this->defineScanTable($table, $ids, 'scan_requests'),
                'scan_evidence' => $this->defineScanTable($table, $ids, 'scan_evidence'),
                'node_grants' => $this->defineNodeGrants(table: $table, ids: $ids),
                'node_shares' => $this->defineNodeShares(table: $table, ids: $ids),
                'node_comments' => $this->defineNodeComments(table: $table, ids: $ids),
                'node_user_states' => $this->defineNodeUserStates(table: $table, ids: $ids),
                'documents' => $this->defineDocuments(table: $table, ids: $ids),
                'document_revisions' => $this->defineDocumentRevisions(table: $table, ids: $ids),
                'upload_sessions' => $this->defineUploadSessions(table: $table, ids: $ids),
                'upload_parts' => $this->defineUploadParts(table: $table, ids: $ids),
                'activity' => $this->defineActivity(table: $table, ids: $ids),
                'outbox' => $this->defineOutbox(table: $table, ids: $ids),
                'webhook_endpoints' => $this->defineWebhookEndpoints(table: $table, ids: $ids),
                'webhook_deliveries' => $this->defineWebhookDeliveries(table: $table, ids: $ids),
                'drive_change_heads' => $this->defineDriveChangeHeads(table: $table, ids: $ids),
                'drive_changes' => $this->defineDriveChanges(table: $table, ids: $ids),
                default => throw new InvalidArgumentException(sprintf('Unknown Vaulter panel entity [%s].', $entity)),
            };
        });

        $this->afterCreate(entity: $entity);
    }

    /**
     * Drop a single entity's table for this panel — the down() counterpart of
     * createTable().
     */
    public function dropTable(string $entity): void
    {
        if ($entity === self::DOCUMENTS_SLOT) {
            $this->dropTable(entity: 'document_revisions');
            $this->dropTable(entity: 'documents');

            return;
        }

        // An aggregate and its revisions reference each other (head FK one way,
        // owner FK the other), so the head FK goes first whichever side drops.
        match ($entity) {
            'documents', 'document_revisions' => $this->dropHeadForeignKey(aggregate: 'documents'),
            'files', 'file_revisions' => $this->dropHeadForeignKey(aggregate: 'files'),
            default => null,
        };

        $this->schemaConnection()->dropIfExists(app(DatabaseConfig::class)->table($entity));
    }

    /**
     * v_drives — the ownable polymorphic disk container. Always created;
     * carries the panel discriminator.
     */
    private function defineDrives(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->panelDiscriminator(table: $table);
        $this->tenant(table: $table);

        HostKeyColumns::add(table: $table, column: 'owner_id', nullable: false, index: true);
        $table->string('owner_type');

        $table->string('name');

        $ids->reference(table: $table, column: 'root_node_id', nullable: true);
        $table->string('disk', 64)->default('default');
        $table->bigInteger('quota_bytes')->nullable();
        $table->bigInteger('used_bytes')->default(0);
        $this->scanInteger($table, 'scan_admission_sequence')->default(0);
        $table->string('status', 16)->default('active')->index();
        HostKeyColumns::add(table: $table, column: 'created_by', nullable: true, index: false);
        $table->timestamps();

        $table->index(['owner_type', 'owner_id']);
        $table->index(['profile', 'status']);

        // Idempotency backbone for get-or-create: one live storage per
        // (owner_type, owner_id, panel). A partial unique (driver-aware, added in
        // afterCreate) lets a soft-deleted row's owner be re-provisioned while a
        // concurrent first-touch converges on one row via this constraint.

        // root_node_id is NOT a DB foreign key: v_drives and v_nodes reference
        // each other (the storage points at its root, every node points at its
        // storage), so a hard FK here would create a chicken-and-egg insert order.
    }

    /**
     * v_nodes — the unified folder|file|document adjacency tree (the
     * centre). Always created; carries the panel discriminator, the soft-trash
     * triplet and the sibling-unique guard (added in afterCreate, driver-aware).
     * Content heads live in the kind's own aggregate (v_files, v_documents), never
     * on the node: one shared pointer column cannot say which table it points into.
     */
    private function defineNodes(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->panelDiscriminator(table: $table);
        $this->tenant(table: $table);
        $ids->reference(table: $table, column: 'drive_id');
        $ids->reference(table: $table, column: 'parent_id', nullable: true);

        $table->string('kind', 16)->index();
        $table->string('name');
        $this->nameKeyColumn(table: $table);
        $table->string('mime_type', 191)->nullable()->index();
        $table->string('extension', 32)->nullable();
        $table->bigInteger('size_bytes')->default(0);
        $table->boolean('inherit_grants')->default(true);
        $ids->reference(table: $table, column: 'shortcut_target_id', nullable: true);
        // Namespaced string properties: {namespace: {key: value}}.
        $table->jsonb('properties')->nullable();
        // The last content change (a file revision, a document state write), apart
        // from updated_at which any public change moves.
        $table->timestamp('content_modified_at')->nullable();
        HostKeyColumns::add(table: $table, column: 'content_modified_by', nullable: true, index: false);

        // Soft-trash triplet; purge is a real DELETE, there is no tombstone.
        $table->timestamp('trashed_at')->nullable();
        HostKeyColumns::add(table: $table, column: 'trashed_by', nullable: true, index: false);
        $ids->reference(table: $table, column: 'trashed_from_parent_id', nullable: true);
        // The explicit trash root a trashed node belongs to; the root points at itself.
        $ids->reference(table: $table, column: 'trash_root_id', nullable: true);

        HostKeyColumns::add(table: $table, column: 'created_by', nullable: true, index: false);
        HostKeyColumns::add(table: $table, column: 'updated_by', nullable: true, index: false);
        $table->timestamps();

        // Hot listing indexes (index plan). The purge-trash scan index
        // (trashed_at, driver-aware partial) is emitted in afterCreate().
        $table->index(['drive_id', 'parent_id']);
        $table->index(['drive_id', 'kind', 'trashed_at']);
        $table->index(['drive_id', 'trash_root_id']);

        $table->foreign('drive_id')
            ->references('id')
            ->on(app(DatabaseConfig::class)->table('drives'))
            ->cascadeOnDelete();

        $table->foreign('parent_id')
            ->references('id')
            ->on(app(DatabaseConfig::class)->table('nodes'))
            ->cascadeOnDelete();
    }

    /**
     * v_blobs — the content-addressable, immutable payload (dedup + GC).
     * Gated on 'blobs'. storage_key is scope-qualified and the dedup-unique
     * index spans the scope column so two scopes never share one physical row.
     */
    private function defineBlobs(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->tenant(table: $table);
        $ids->reference(table: $table, column: 'drive_id', nullable: true);

        $table->string('hash_algo', 16)->default('sha256');
        $table->char('sha256', 64);
        $table->bigInteger('size_bytes')->index();
        $table->string('disk', 64)->default('default');

        // Scope-qualified storage_key: "<scope>/ab/cd/<sha256>". A
        // scope-free path under per-scope dedup would let one GC delete another
        // scope's live bytes. BlobScope bounds canonical keys below 255 bytes;
        // VARCHAR keeps the full-value UNIQUE portable to MySQL without a lossy
        // prefix index or a hash surrogate.
        $table->string('storage_key');

        // Blob state machine (logic is; the columns exist now): pending →
        // committed → tombstoned → deleting → deleted. No standalone index on
        // status: it is a strict column-prefix of the
        // (status, ref_count, delete_after_at) GC index below, so it never wins
        // over it and only pays extra write cost.
        $table->string('status', 12)->default('pending');
        $table->bigInteger('ref_count')->default(0);
        $table->string('dedup_scope', 8)->default('tenant');
        $table->timestamp('quarantined_at')->nullable();
        $table->string('storage_version', 255)->nullable();
        $table->string('etag', 64)->nullable();
        $table->string('md5', 64)->nullable();
        $table->string('crc32c', 64)->nullable();
        $table->boolean('chunked')->default(false);
        $ids->reference(table: $table, column: 'encryption_key_id', nullable: true);
        $table->timestamp('first_seen_at')->useCurrent();
        $table->timestamp('committed_at')->nullable();
        $table->timestamp('delete_after_at')->nullable();

        $table->string('scope_key', 191);
        $name = $table->getTable();
        $table->unique(['scope_key', 'sha256'], $name.'_scope_sha_uniq');
        $table->unique('storage_key', $name.'_storage_key_uniq');

        // GC scan support: committed orphans past their grace deadline.
        $table->index(['status', 'ref_count', 'delete_after_at'], $name.'_gc_idx');
    }

    /**
     * v_files — the mutable 1:1 handle for a kind=file node. Gated on
     * 'versions'.
     */
    private function defineFiles(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->tenant(table: $table);
        $ids->reference(table: $table, column: 'node_id');
        $ids->reference(table: $table, column: 'current_revision_id', nullable: true);

        $table->string('mime_type', 191)->nullable();
        $table->bigInteger('size_bytes')->default(0);
        $table->unsignedInteger('revision_count')->default(0);
        HostKeyColumns::add(table: $table, column: 'created_by', nullable: true, index: false);
        $table->timestamps();

        $table->unique('node_id', $table->getTable().'_node_uniq');

        $table->foreign('node_id')
            ->references('id')
            ->on(app(DatabaseConfig::class)->table('nodes'))
            ->cascadeOnDelete();
    }

    /**
     * v_file_revisions — the immutable file ↔ blob version. Gated on
     * 'versions'. number is monotonic per file (unique).
     */
    private function defineFileRevisions(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->tenant(table: $table);
        $ids->reference(table: $table, column: 'file_id');
        $table->integer('number');
        $ids->reference(table: $table, column: 'blob_id', nullable: true);
        $ids->reference(table: $table, column: 'manifest_blob_id', nullable: true);
        $table->char('sha256', 64)->index();
        $table->bigInteger('size_bytes');
        $table->string('mime_type', 191)->nullable();
        $table->string('declared_mime_type', 191)->nullable();
        $table->string('scan_status', 16)->default('not_required');
        $this->scanInteger($table, 'scan_admission_sequence')->default(0);
        $table->timestamp('quarantined_at')->nullable();
        $table->string('original_name');
        $table->string('status', 12)->default('pending')->index();
        $table->boolean('keep_forever')->default(false);
        $ids->reference(table: $table, column: 'restored_from_revision_id', nullable: true);
        HostKeyColumns::add(table: $table, column: 'created_by', nullable: true, index: false);
        $table->timestamp('created_at')->nullable();
        $table->timestamp('committed_at')->nullable();
        // When the revision stopped being the head: the non-current age the
        // retention policy counts from.
        $table->timestamp('superseded_at')->nullable();
        // The uploader's own modification time: informational, never trusted.
        $table->timestamp('client_modified_at')->nullable();

        $table->unique(['file_id', 'number'], $table->getTable().'_no_uniq');
        // The target of v_files' composite head FK: a head must be a revision of
        // that very file, not merely some revision.
        $table->unique(['id', 'file_id'], $table->getTable().'_id_file_uniq');
        $table->index(['file_id', 'status']);

        $table->foreign('file_id')
            ->references('id')
            ->on(app(DatabaseConfig::class)->table('files'))
            ->cascadeOnDelete();

        // Restore is copy-forward: the new revision remembers its source until
        // that source is pruned.
        // Named explicitly: the default name outgrows MySQL's 64 characters under a
        // longer table prefix.
        $table->foreign('restored_from_revision_id', $table->getTable().'_restored_fk')
            ->references('id')
            ->on($table->getTable())
            ->nullOnDelete();
    }

    /**
     * v_file_revision_blobs — the edge table that is the GC truth. Gated on
     * 'versions'. Composite primary key; liveness of a blob = existence of an edge
     * here, so it is never soft-deleted.
     */
    private function defineFileRevisionBlobs(Blueprint $table, IdStrategy $ids): void
    {
        $ids->reference(table: $table, column: 'file_revision_id');
        $ids->reference(table: $table, column: 'blob_id');
        $table->string('role', 12)->default('data');
        $table->integer('ordinal')->default(0);
        $table->bigInteger('offset_bytes')->nullable();
        $table->bigInteger('length_bytes')->nullable();

        $table->primary(['file_revision_id', 'blob_id', 'role', 'ordinal'], $table->getTable().'_pk');

        $table->foreign('file_revision_id')
            ->references('id')
            ->on(app(DatabaseConfig::class)->table('file_revisions'))
            ->cascadeOnDelete();

        $table->foreign('blob_id')
            ->references('id')
            ->on(app(DatabaseConfig::class)->table('blobs'))
            ->cascadeOnDelete();
    }

    /**
     * v_node_grants — positive per-node grants of a versioned role. Gated
     * on 'grants'. The (node_id, subject_type, subject_id, scope) UNIQUE (added in
     * afterCreate) keeps one grant per subject/scope per node; the
     * (subject_type, subject_id) index backs the "shared with me" fast pass.
     */
    private function defineNodeGrants(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->tenant(table: $table);
        $ids->reference(table: $table, column: 'node_id');

        // Subject morph: a registered alias (user|role|team), never a raw FQCN.
        HostKeyColumns::add(table: $table, column: 'subject_id', nullable: false, index: true);
        $table->string('subject_type');

        $table->string('role_key', 32);
        $table->smallInteger('role_version')->default(1);
        $table->string('scope', 8)->default('subtree');

        $table->timestamp('expires_at')->nullable()->index();
        HostKeyColumns::add(table: $table, column: 'granted_by', nullable: true, index: false);
        $table->timestamps();

        $name = $table->getTable();

        // "Shared with me": every node a subject is granted access to.
        $table->index(['subject_type', 'subject_id'], $name.'_subject_idx');

        $table->foreign('node_id')
            ->references('id')
            ->on(app(DatabaseConfig::class)->table('nodes'))
            ->cascadeOnDelete();
    }

    /**
     * v_node_shares — the bearer share-link, the SEPARATE public sharing channel
     * . Gated on 'share_links'. ONLY the sha256 token_hash is stored
     * (UNIQUE — the raw token is never persisted); token_prefix backs owner-side UX
     * lookup without revealing the token. The partial (expires_at) WHERE
     * revoked_at IS NULL index (added driver-aware in afterCreate) backs the
     * live-share sweep/lookup.
     */
    private function defineNodeShares(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->tenant(table: $table);
        $ids->reference(table: $table, column: 'node_id');

        // ONLY the hash is stored — the raw bearer token is returned once at mint
        // and never persisted (hardening). token_prefix is a non-secret
        // leading slice kept purely for owner-side UX lookup.
        $table->char('token_hash', 64);
        $table->string('token_prefix', 16)->index();

        // A share carries a role (a stored version keeps its meaning forever), an
        // audience (anyone|authenticated|tenant) and the generation of its token:
        // rotating the token raises it and ends every link session of the old one.
        $table->string('role_key', 32);
        $table->smallInteger('role_version')->default(1);
        $table->string('audience', 16)->default('anyone');
        $table->unsignedInteger('token_generation')->default(1);
        $table->string('scope', 8)->default('item');

        // Optional password gate: stored only as a hash.
        $table->string('password_hash')->nullable();

        // Lifecycle bounds: an optional expiry, an optional atomic use budget and a
        // revocation tombstone. use_count is incremented atomically and
        // bounded by max_uses.
        $table->timestamp('expires_at')->nullable();
        $table->unsignedInteger('max_uses')->nullable();
        $table->unsignedInteger('use_count')->default(0);
        $table->timestamp('revoked_at')->nullable()->index();

        HostKeyColumns::add(table: $table, column: 'granted_by', nullable: true, index: false);
        $table->timestamps();

        $name = $table->getTable();

        // token_hash is the bearer lookup key — UNIQUE so two shares can never
        // collide on a token (the loser of a mint race retries with a fresh token).
        $table->unique('token_hash', $name.'_token_hash_uniq');

        // Owner-side "shares on this node" listing.
        $table->index(['node_id', 'revoked_at'], $name.'_node_revoked_idx');

        $table->foreign('node_id')
            ->references('id')
            ->on(app(DatabaseConfig::class)->table('nodes'))
            ->cascadeOnDelete();
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
        $this->tenant(table: $table);
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
            ->on(app(DatabaseConfig::class)->table('nodes'))
            ->cascadeOnDelete();

        // Self-FK: deleting a parent comment cascades to its replies.
        $table->foreign('parent_id')
            ->references('id')
            ->on(app(DatabaseConfig::class)->table('node_comments'))
            ->cascadeOnDelete();
    }

    /**
     * v_node_user_states — one actor's personal state of a node: starred
     * now, more per-user flags later. Never part of the node: a star changes neither
     * the node's sequence nor its updated_at, and is invisible to other actors.
     */
    private function defineNodeUserStates(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->tenant(table: $table);
        $ids->reference(table: $table, column: 'node_id');
        $table->string('actor_type', 191);
        HostKeyColumns::add(table: $table, column: 'actor_id', nullable: false, index: false);
        $table->timestamp('starred_at')->nullable();
        $table->timestamps();

        $name = $table->getTable();

        $table->unique(['node_id', 'actor_type', 'actor_id'], $name.'_actor_uniq');
        $table->index(['actor_type', 'actor_id', 'starred_at'], $name.'_starred_idx');

        $table->foreign('node_id')
            ->references('id')
            ->on($this->table('nodes'))
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
        $this->tenant(table: $table);
        $ids->reference(table: $table, column: 'node_id');

        $table->string('type', 64);
        $table->integer('schema_version');
        $ids->reference(table: $table, column: 'current_revision_id', nullable: true);
        // Monotonic: pruning old checkpoints never hands a number out twice.
        $table->integer('revision_count')->default(0);
        $this->counterColumn(table: $table, column: 'collab_epoch');
        $this->counterColumn(table: $table, column: 'state_version');
        $this->counterColumn(table: $table, column: 'checkpoint_state_version');

        // The canonical JSON projection of the working state. jsonb on Postgres,
        // json on MySQL, text on SQLite.
        $table->jsonb('content')->nullable();
        $table->char('content_hash', 64)->nullable();
        $table->integer('content_size')->nullable();
        $this->binaryColumn(table: $table, column: 'yjs_state');
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
            ->on($this->table('nodes'))
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
        $this->tenant(table: $table);
        $ids->reference(table: $table, column: 'document_id');
        $table->integer('number');
        $table->integer('schema_version');

        $table->jsonb('content')->nullable();
        $table->char('content_hash', 64)->nullable();
        $table->integer('content_size')->nullable();
        $this->binaryColumn(table: $table, column: 'yjs_state');
        $table->integer('yjs_state_size')->nullable();
        // The working state version this checkpoint captured.
        $this->counterColumn(table: $table, column: 'state_version');

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
            ->on($this->table('documents'))
            ->cascadeOnDelete();

        $table->foreign('restored_from_revision_id', $name.'_restored_fk')
            ->references('id')
            ->on($name)
            ->nullOnDelete();
    }

    /**
     * v_upload_sessions — the resumable-ingest session. Gated on
     * 'uploads'. upload_id is the client-facing UNIQUE handle (the S3 uploadId / tus
     * resource id). received_bytes is the AUTHORITATIVE server-side tus offset — the
     * client never decides where to resume. status drives the lifecycle
     * (initiated→uploading→assembled→committed, with abort/expire). assembled_blob_id
     * points at the blob produced by BlobStore.commit on completion (server
     * recompute, never the client/ETag hash). Two target shapes share one row:
     *   - new file    → target_parent_id (null = root) + target_name;
     *   - new version → node_id (the existing kind=file node).
     */
    private function defineUploadSessions(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->tenant(table: $table);
        $ids->reference(table: $table, column: 'drive_id');

        // The client handle: a UUID (S3 uploadId / tus resource id) the resumable
        // endpoints address the session by. UNIQUE — the resume key.
        $table->uuid('upload_id');

        // Targets: a NEW-VERSION upload pins node_id; a NEW-FILE upload pins
        // target_parent_id (null = storage root) + target_name. No DB FK on node_id /
        // target_parent_id: a session may outlive a concurrently-moved node and the
        // sweeper must still reclaim it.
        $ids->reference(table: $table, column: 'target_node_id', nullable: true);
        $ids->reference(table: $table, column: 'target_parent_id', nullable: true);
        $table->string('target_name')->nullable();
        $ids->reference(table: $table, column: 'result_node_id', nullable: true);
        $ids->reference(table: $table, column: 'result_revision_id', nullable: true);
        $this->defineUploadCleanup($table, $ids);

        $table->string('protocol', 16)->default('tus');
        $table->string('status', 16)->default('initiated');

        // The client-DECLARED sha256 (optional). It is NEVER trusted as the content
        // address — BlobStore.commit recomputes sha256 from the assembled staged file
        // . It is kept only as an optional integrity cross-check.
        $table->char('expected_sha256', 64)->nullable();
        $table->bigInteger('total_size_bytes')->nullable();

        // The authoritative server-side resume offset (tus Upload-Offset). A HEAD
        // returns this; a PATCH advances it.
        $table->bigInteger('received_bytes')->default(0);
        $table->bigInteger('chunk_size')->nullable();
        $table->string('mime_type', 191)->nullable();

        // Inline manifest for the small-part fast path: a denormalised parts
        // list a session may carry without spilling to v_upload_parts.
        $table->json('parts')->nullable();

        // The blob the assembled bytes deduped/committed into (set at commit).
        $ids->reference(table: $table, column: 'assembled_blob_id', nullable: true);

        // The tus Upload-Metadata (decoded key→value map).
        $table->json('metadata')->nullable();

        $table->timestamp('expires_at')->nullable();
        HostKeyColumns::add(table: $table, column: 'created_by', nullable: true, index: false);
        $table->timestamps();

        $name = $table->getTable();

        // upload_id is the resume key — UNIQUE so two sessions can never collide on a
        // handle (the loser of a mint race retries with a fresh uuid).
        $table->unique('upload_id', $name.'_upload_id_uniq');

        // The sweeper scan axis: stale OPEN sessions past expires_at. The
        // (status, expires_at) composite is added driver-aware in afterCreate so the
        // index name stays distinct per prefixed panel table.
        $table->index('drive_id', $name.'_drive_idx');

        $table->foreign('drive_id')
            ->references('id')
            ->on(app(DatabaseConfig::class)->table('drives'))
            ->cascadeOnDelete();
    }

    /**
     * v_upload_parts — the normalised per-part record. Gated on
     * 'uploads'. The composite primary key (upload_session_id, part_no) is the S3
     * re-upload semantics: re-uploading a part REPLACES its row (a fresh PUT of part
     * N supersedes the prior etag/size). temp_blob_id is the optional staged-blob
     * handle for a part proxied through the CAS; received_at marks the last write.
     */
    private function defineUploadParts(Blueprint $table, IdStrategy $ids): void
    {
        $ids->reference(table: $table, column: 'upload_session_id');
        $table->integer('part_no');
        $table->bigInteger('byte_offset')->default(0);
        $table->bigInteger('size_bytes')->default(0);
        $table->char('part_sha256', 64)->nullable();
        $table->string('etag')->nullable();
        $ids->reference(table: $table, column: 'temp_blob_id', nullable: true);
        $table->timestamp('received_at')->nullable();

        // Re-upload REPLACES (S3 semantics): the (session, part_no) pair is the
        // primary key, so a second PUT of part N updates the same row.
        $table->primary(['upload_session_id', 'part_no'], $table->getTable().'_pk');

        $table->foreign('upload_session_id')
            ->references('id')
            ->on(app(DatabaseConfig::class)->table('upload_sessions'))
            ->cascadeOnDelete();
    }

    /**
     * v_activity — the append-only audit/activity feed. Gated
     * on 'activity'. ONE row per domain event; recipients are resolved at READ by
     * scoping the feed to a storage/node — there is NEVER a per-affected-user
     * fan-out (anti-NC-explosion).
     *
     * Append-only: NO updated_at, NO soft-delete. The id honours the panel id
     * strategy (recommend uuid7 — time-ordered — but bigint/ulid all keep the
     * (created_at desc) index canonical). The actor and target are nullable morphs
     * (a console/queue write may have no actor; a drive.created row has no node
     * target). ip is inet on Postgres, a portable varchar(45) elsewhere.
     */
    private function defineActivity(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->tenant(table: $table);
        $ids->reference(table: $table, column: 'drive_id', nullable: true);

        // Actor morph — the acting host user (nullable: a system/console write has
        // no actor). actor_id is an actor reference (no DB FK to the host's table).
        HostKeyColumns::add(table: $table, column: 'actor_id', nullable: true, index: false);
        $table->string('actor_type')->nullable();

        $table->string('verb')->index();
        // One row per occurrence: recording an event again inserts nothing.
        $table->string('event_id', 26)->nullable()->unique();

        // Target morph — the subject of the verb (nullable: e.g. nothing for a
        // storage-level row). No DB FK: the target may be any model, swappable.
        $ids->reference(table: $table, column: 'target_id', nullable: true);
        $table->string('target_type')->nullable();

        // The structured payload (before/after, old/new path/role, …). Always
        // present — this is the audit body.
        // jsonb on Postgres (the gin jsonb_path_ops index requires it); json/text elsewhere.
        $table->jsonb('context')->nullable();

        $this->ipColumn(table: $table);
        $table->text('user_agent')->nullable();

        // request_id is an opaque per-request CORRELATION id (X-Request-Id / a
        // minted uuid), NOT a Vaulter entity reference — so it is a fixed nullable
        // uuid column independent of the panel id strategy ("request_id uuid").
        $table->uuid('request_id')->nullable();

        // Append-only: created_at ONLY. No timestamps()/softDeletes() — there is no
        // update or delete path for an audit row. No inline ->index() here
        // addActivityCreatedAtIndex() below already
        // emits the one (created_at) index this table carries; a second one on the
        // very same single column was a literal duplicate, pure write cost on this
        // append-only, write-hottest table with zero read benefit.
        $table->timestamp('created_at')->nullable();

        $name = $table->getTable();

        // feed indexes — every read axis is newest-first. The leading column is
        // the scope/filter; created_at is the cursor tail.
        $table->index(['actor_type', 'actor_id', 'created_at'], $name.'_actor_idx');
        $table->index(['target_type', 'target_id', 'created_at'], $name.'_target_idx');
        $table->index(['verb', 'created_at'], $name.'_verb_idx');
        $table->index(['drive_id', 'created_at'], $name.'_drive_idx');
    }

    /**
     * v_outbox — the transactional-outbox staging table, ported from chatom 1:1. Gated on 'reliability'. ONE row per domain event,
     * idempotent by event_id (UNIQUE) so the same event never stages a duplicate and
     * the relay drains each row exactly once.
     *
     * The id honours the panel id strategy; panel is the discriminator (a row is
     * fanned out for its panel's endpoints); topic is an optional routing hint
     * (e.g. the storage/node id). status is 'pending' until the relay has written
     * the delivery of every eligible endpoint, then 'completed' in the same
     * transaction as the last chunk. claim_* is the relay worker's fenced lease,
     * fanout_cursor the last endpoint key already materialized. The
     * (status, available_at) index is the relay's drain cursor.
     */
    private function defineOutbox(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);

        // event_id is the idempotency key — a stable, caller-supplied string (NOT a
        // Vaulter entity reference), so it is a plain unique string column independent
        // of the panel id strategy.
        $table->string('event_id')->unique();
        $this->panelDiscriminator(table: $table);
        $this->tenant(table: $table);
        $ids->reference(table: $table, column: 'drive_id', nullable: true);
        $table->string('aggregate_type', 32)->nullable();
        $table->string('aggregate_id', 64)->nullable();
        $table->bigInteger('sequence')->nullable();
        $table->string('topic')->nullable();
        $table->string('event');
        $table->json('payload');
        $table->timestamp('available_at');
        $table->unsignedInteger('attempts')->default(0);
        $table->string('status')->default('pending');
        $table->timestamps();
        $table->string('claim_token', 32)->nullable();
        $table->bigInteger('claim_generation')->default(0);
        $table->timestamp('claim_until')->nullable();
        $table->string('fanout_cursor', 64)->nullable();
        $table->timestamp('fanned_out_at')->nullable();
        $table->unsignedInteger('delivery_count')->default(0);
        $table->boolean('legacy_fanout')->default(false);

        $name = $table->getTable();

        $table->index(['status', 'available_at'], $name.'_drain_idx');
        // The retention sweep (vaulter:webhooks:prune) deletes finished rows by
        // (status='completed', fanned_out_at <= threshold) — its own scan cursor.
        $table->index(['status', 'fanned_out_at'], $name.'_prune_idx');
    }

    /**
     * v_webhook_endpoints — the subscriber endpoints,
     * ported from chatom 1:1. Gated on 'reliability'. events is the JSON subscription
     * list (matched in SQL with whereJsonContains); signing_secret signs the
     * deliveries (Standard Webhooks, HMAC-SHA256); failing_since is the auto-disable
     * clock, previous_signing_secret the rotation overlap. tenant_id
     * and the optional drive_id narrow which events the endpoint receives.
     */
    private function defineWebhookEndpoints(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);

        $table->string('url');
        $table->json('events');
        // text, not string: the signing_secret is encrypted at rest and
        // the ciphertext is longer than the 64-char plaintext.
        $table->text('signing_secret');
        // The secret before the last rotation, valid for signing until its expiry.
        $table->text('previous_signing_secret')->nullable();
        $table->timestamp('previous_secret_expires_at')->nullable();
        $table->boolean('is_active')->default(true)->index();
        $table->timestamp('last_triggered_at')->nullable();
        $table->unsignedInteger('failure_count')->default(0);
        // Start of the current unbroken run of failed attempts; the auto-disable clock.
        $table->timestamp('failing_since')->nullable();
        $table->timestamp('disabled_at')->nullable();
        // 'failing' (auto-disabled) or 'manual'.
        $table->string('disabled_reason', 32)->nullable();
        $table->timestamps();
        $this->tenant(table: $table);
        $ids->reference(table: $table, column: 'drive_id', nullable: true);

        $name = $table->getTable();

        // The relay's eligible-endpoint scan filters by both.
        $table->index(['is_active', 'tenant_id'], $name.'_active_tenant_idx');
        $table->foreign('drive_id')
            ->references('id')
            ->on($this->table('drives'))
            ->cascadeOnDelete();
    }

    /**
     * v_webhook_deliveries — a single delivery attempt with retry bookkeeping (master
     * plan), ported from chatom 1:1. Gated on 'reliability'.
     * endpoint_id is an explicit FK → v_webhook_endpoints (cascade on delete). The
     * (status, next_retry_at) index is the due-retry sweep cursor; (status, updated_at)
     * backs the retention prune of terminal (delivered|dead) rows. (event_id,
     * endpoint_id) is unique, so an occurrence reaches an endpoint through one row
     * however often the relay retries its fan-out.
     */
    private function defineWebhookDeliveries(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $ids->reference(table: $table, column: 'endpoint_id', nullable: false);

        $table->string('event');
        $table->json('payload');
        $table->string('status');
        $table->unsignedInteger('attempts')->default(0);
        $table->timestamp('next_retry_at')->nullable();
        $table->string('claim_token', 32)->nullable();
        $table->timestamp('claim_until')->nullable();
        $table->text('last_error')->nullable();
        $table->timestamps();
        $table->string('event_id', 64)->nullable();

        $name = $table->getTable();

        $table->unique(['event_id', 'endpoint_id'], $name.'_event_endpoint_unique');
        $table->index(['status', 'next_retry_at'], $name.'_retry_idx');
        // The retention sweep (vaulter:webhooks:prune) deletes terminal rows by
        // (status in delivered|dead, updated_at <= threshold) — its own scan cursor.
        $table->index(['status', 'updated_at'], $name.'_prune_idx');

        $table->foreign('endpoint_id')
            ->references('id')
            ->on(app(DatabaseConfig::class)->table('webhook_endpoints'))
            ->cascadeOnDelete();
    }

    /**
     * v_drive_change_heads — one row per drive: the change journal's counter
     * . last_seq is the seq of the newest entry; the UPDATE that raises it
     * is the journal lock, held until the mutation commits, so entries become visible
     * in seq order. pruned_through is the highest seq the retention prune deleted;
     * epoch changes on a reset and expires every cursor of the drive.
     */
    private function defineDriveChangeHeads(Blueprint $table, IdStrategy $ids): void
    {
        $ids->reference(table: $table, column: 'drive_id', nullable: false);
        $this->tenant(table: $table);
        $table->bigInteger('last_seq')->default(0);
        $table->bigInteger('pruned_through')->default(0);
        $table->unsignedInteger('epoch')->default(1);
        $table->timestamp('updated_at')->nullable();

        $table->primary(['drive_id']);
        $table->foreign('drive_id')
            ->references('id')
            ->on($this->table('drives'))
            ->cascadeOnDelete();
    }

    /**
     * v_drive_changes — the change journal of a drive: one entry per
     * journalled event, for the node it is about. change_id is the event id, so an
     * occurrence is journalled once. node_id has no foreign key: the entry outlives a
     * purged node. Entries are compacted to the latest state of a node and pruned by age.
     */
    private function defineDriveChanges(Blueprint $table, IdStrategy $ids): void
    {
        $ids->reference(table: $table, column: 'drive_id', nullable: false);
        $table->bigInteger('seq');
        $this->tenant(table: $table);
        $table->string('change_id', 26)->unique();
        $table->string('event_type', 64);
        $ids->reference(table: $table, column: 'node_id', nullable: false);
        // 'upsert' | 'remove'; reason is 'deleted' on a remove, null otherwise.
        $table->string('operation', 8);
        $table->string('reason', 16)->nullable();
        $table->boolean('subtree')->default(false);
        $table->bigInteger('node_sequence')->nullable();
        $table->timestamp('occurred_at');

        $name = $table->getTable();

        $table->primary(['drive_id', 'seq']);
        // Compaction finds the earlier entries of a node; the prune finds the age boundary.
        $table->index(['drive_id', 'node_id'], $name.'_node_idx');
        $table->index(['drive_id', 'occurred_at'], $name.'_occurred_idx');
        $table->foreign('drive_id')
            ->references('id')
            ->on($this->table('drives'))
            ->cascadeOnDelete();
    }

    /**
     * Create the change-journal tables on an existing installation; each step checks
     * what is already there, so a re-run after a failure finishes the job.
     */
    public function addDriveChanges(): void
    {
        foreach (['drive_change_heads', 'drive_changes'] as $entity) {
            if (!$this->schemaConnection()->hasTable($this->table($entity))) {
                $this->createTable($entity);
            }
        }
    }

    public function removeDriveChanges(): void
    {
        foreach (['drive_changes', 'drive_change_heads'] as $entity) {
            $this->dropTable($entity);
        }
    }

    /**
     * Post-create hooks that cannot be expressed inside the Blueprint, currently
     * the driver-aware sibling-unique guard on v_nodes.
     */
    public function addNodeSequence(): void
    {
        $this->assertEnforcedMysqlChecks();
        $name = app(DatabaseConfig::class)->table('nodes');
        if ($this->schemaConnection()->hasColumn($name, 'sequence')) {
            return;
        }
        $wrapped = $this->dbConnection()->getQueryGrammar()->wrapTable($name);
        $check = $this->dbConnection()->getDriverName() === 'sqlite'
            ? "CHECK(typeof(sequence) = 'integer' AND sequence >= 0)"
            : 'CONSTRAINT '.$this->dbConnection()->getQueryGrammar()->wrap($name.'_sequence_nonnegative').' CHECK(sequence >= 0)';
        $this->dbConnection()->statement("ALTER TABLE {$wrapped} ADD COLUMN sequence BIGINT NOT NULL DEFAULT 0 {$check}");
    }

    public function removeNodeSequence(): void
    {
        $name = app(DatabaseConfig::class)->table('nodes');
        if ($this->schemaConnection()->hasColumn($name, 'sequence')) {
            if ($this->dbConnection()->getDriverName() === 'mysql') {
                $grammar = $this->dbConnection()->getQueryGrammar();
                $this->dbConnection()->statement('ALTER TABLE '.$grammar->wrapTable($name).' DROP CHECK '.$grammar->wrap($name.'_sequence_nonnegative'));
            }
            // Native DROP COLUMN preserves SQLite FKs without toggling PRAGMA inside an outer transaction.
            $this->dbConnection()->statement('ALTER TABLE '.$this->dbConnection()->getQueryGrammar()->wrapTable($name).' DROP COLUMN sequence');
        }
    }

    private function afterCreate(string $entity): void
    {
        if (in_array($entity, ['scan_obligations', 'scan_evidence'], true) && $this->dbConnection()->getDriverName() === 'mysql') {
            $name = app(DatabaseConfig::class)->table($entity);
            $grammar = $this->dbConnection()->getQueryGrammar();
            $checks = ['outcome_fields' => "COALESCE((outcome IS NULL AND reason IS NULL AND retryable = false) OR (outcome IN ('clean','infected') AND reason IS NULL AND retryable = false AND blob_id IS NOT NULL) OR (outcome = 'failed' AND reason IS NOT NULL), false) = true"];
            if ($entity === 'scan_obligations') {
                $checks['lease_fields'] = "(state = 'leased' AND lease_token IS NOT NULL AND lease_expires_at IS NOT NULL) OR (state <> 'leased' AND lease_token IS NULL AND lease_expires_at IS NULL)";
                $checks['settled_outcome'] = "state <> 'settled' OR outcome IS NOT NULL";
            }
            foreach ($checks as $suffix => $predicate) {
                if ($this->dbConnection()->table('information_schema.TABLE_CONSTRAINTS')->whereRaw('TABLE_SCHEMA = DATABASE()')->where('TABLE_NAME', $name)->where('CONSTRAINT_NAME', $name.'_'.$suffix.'_check')->exists()) {
                    continue;
                }
                $this->dbConnection()->statement('ALTER TABLE '.$grammar->wrapTable($name).' ADD CONSTRAINT '.$grammar->wrap($name.'_'.$suffix.'_check').' CHECK('.$predicate.')');
            }
        }

        if ($entity === 'drives') {
            $this->addOwnerUniqueIndex(table: app(DatabaseConfig::class)->table('drives'));
        }

        if ($entity === 'nodes') {
            $this->addNodeSequence();
            $this->addSiblingUniqueIndex(table: app(DatabaseConfig::class)->table('nodes'));
            $this->addSingleRootIndex(table: app(DatabaseConfig::class)->table('nodes'));
            $this->addTrashedAtIndex(table: app(DatabaseConfig::class)->table('nodes'));
        }

        if ($entity === 'node_grants') {
            $this->addGrantUniqueIndex(table: app(DatabaseConfig::class)->table('node_grants'));
        }

        if ($entity === 'node_shares') {
            $this->addShareExpiryIndex(table: app(DatabaseConfig::class)->table('node_shares'));
        }

        if ($entity === 'file_revisions') {
            $this->addHeadForeignKey(aggregate: 'files', revisions: 'file_revisions', owner: 'file_id');
        }

        if ($entity === 'document_revisions') {
            $this->addDocumentContentGinIndex(table: $this->table('document_revisions'));
            $this->addHeadForeignKey(aggregate: 'documents', revisions: 'document_revisions', owner: 'document_id');
        }

        if ($entity === 'upload_sessions') {
            $this->addUploadSweeperIndex(table: app(DatabaseConfig::class)->table('upload_sessions'));
        }

        if ($entity === 'activity') {
            $this->addActivityCreatedAtIndex(table: app(DatabaseConfig::class)->table('activity'));
            $this->addActivityContextGinIndex(table: app(DatabaseConfig::class)->table('activity'));
        }
    }

    /**
     * The plain (created_at desc) feed index. Added here rather than inline
     * because the column is added last (a fresh-row tail) and naming it off the
     * table keeps a per-panel prefixed table's index distinct.
     */
    private function addActivityCreatedAtIndex(string $table): void
    {
        $this->schemaConnection()->table($table, static function (Blueprint $blueprint) use ($table): void {
            $blueprint->index('created_at', $table.'_created_idx');
        });
    }

    /**
     * The OPTIONAL gin(context) index for jsonb containment queries. Only
     * Postgres has gin + jsonb; on SQLite/MySQL context is a plain json column and
     * the index is skipped (the feed never queries by context shape — it scopes by
     * storage/node/actor/verb, all covered by btree indexes).
     */
    private function addActivityContextGinIndex(string $table): void
    {
        if ($this->schemaConnection()->getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->dbConnection()->statement(sprintf('CREATE INDEX %s_context_gin ON %s USING gin (context jsonb_path_ops)', $table, $table));
    }

    /**
     * The OPTIONAL gin(content jsonb_path_ops) index for document-revision content
     * containment queries. Postgres-only (gin + jsonb); on SQLite/MySQL
     * content is a plain json column and the index is skipped — the history is read
     * by (document_id, number) / (document_id, is_autosave), all btree-covered.
     */
    private function addDocumentContentGinIndex(string $table): void
    {
        if ($this->schemaConnection()->getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->dbConnection()->statement(sprintf('CREATE INDEX %s_content_gin ON %s USING gin (content jsonb_path_ops)', $table, $table));
    }

    /**
     * The partial expiry index: (expires_at) WHERE revoked_at IS NULL — the
     * sweep of live, expiring shares. Driver-aware: a real partial index on
     * Postgres/SQLite, a plain (expires_at) index elsewhere (the WHERE is then
     * applied at query time by the Live scope).
     */
    private function addShareExpiryIndex(string $table): void
    {
        $driver = $this->schemaConnection()->getConnection()->getDriverName();
        $name = $table.'_expiry_live_idx';

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            $this->dbConnection()->statement(
                sprintf('CREATE INDEX %s ON %s (expires_at) WHERE revoked_at IS NULL', $name, $table),
            );

            return;
        }

        $this->schemaConnection()->table($table, static function (Blueprint $blueprint) use ($name): void {
            $blueprint->index('expires_at', $name);
        });
    }

    /**
     * the trashed_at scan index PurgeTrashCommand's
     * predicate needs — trashedBefore() filters `whereNotNull('trashed_at')
     * ->where('trashed_at', '<=', $threshold)`, and without a leading-column index
     * on trashed_at that scan is a full v_nodes seq-scan on every scheduled run
     * (the only existing index touching this column, (drive_id, kind,
     * trashed_at), never wins for a query that filters neither drive_id nor
     * kind — leftmost-prefix rule). Driver-aware like every other partial index
     * in this file: a real partial index `WHERE trashed_at IS NOT NULL` on
     * Postgres/SQLite (the predicate already excludes NULLs, so the partial index
     * covers 100% of the scan and nothing else), a plain btree elsewhere.
     */
    private function addTrashedAtIndex(string $table): void
    {
        $driver = $this->schemaConnection()->getConnection()->getDriverName();
        $name = $table.'_trashed_idx';

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            $this->dbConnection()->statement(
                sprintf('CREATE INDEX %s ON %s (trashed_at) WHERE trashed_at IS NOT NULL', $name, $table),
            );

            return;
        }

        $this->schemaConnection()->table($table, static function (Blueprint $blueprint) use ($name): void {
            $blueprint->index('trashed_at', $name);
        });
    }

    /**
     * The sweeper index: (status, expires_at). The leading status narrows to the
     * open sessions; expires_at is the cursor the sweeper scans for stale rows. A
     * plain composite btree on every driver — there is no partial predicate here (the
     * status filter is the leading column), so no driver split is needed.
     */
    private function addUploadSweeperIndex(string $table): void
    {
        $this->schemaConnection()->table($table, static function (Blueprint $blueprint) use ($table): void {
            $blueprint->index(['status', 'expires_at'], $table.'_sweeper_idx');
        });
    }

    /**
     * One grant per (node_id, subject_type, subject_id, scope) — the
     * grant-unique key.
     */
    private function addGrantUniqueIndex(string $table): void
    {
        $this->schemaConnection()->table($table, static function (Blueprint $blueprint) use ($table): void {
            $blueprint->unique(['node_id', 'subject_type', 'subject_id', 'scope'], $table.'_grant_uniq');
        });
    }

    /**
     * One LIVE storage per (owner_type, owner_id, panel) — the get-or-create
     * idempotency key. Driver-aware so the partial (soft-delete-aware)
     * shape works on Postgres/SQLite, with a MySQL generated-column fallback and a
     * plain unique on unknown drivers.
     */
    private function addOwnerUniqueIndex(string $table): void
    {
        $this->schemaConnection()->table($table, static function (Blueprint $blueprint) use ($table): void {
            $blueprint->unique(['owner_type', 'owner_id', 'profile'], $table.'_owner_profile_uniq');
        });
    }

    /**
     * Sibling-name uniqueness among active nodes: one live name_key per
     * parent, compared byte for byte so the host's collation never decides.
     *
     *  - PostgreSQL: a PARTIAL unique index on (parent_id, name_key COLLATE "C")
     *    WHERE trashed_at IS NULL — a trashed duplicate is allowed, a live one not.
     *  - SQLite: the same partial index; BINARY is SQLite's default collation.
     *  - MySQL / MariaDB: a varchar(1024) utf8mb4 key outgrows the 3072-byte index
     *    limit, so a stored generated column holds sha2(name_key) for live rows and
     *    NULL for trashed ones (NULL is exempt from UNIQUE). It reads neither
     *    parent_id nor any other FK column, which a stored generated column may not
     *    do alongside a cascading FK.
     */
    private function addSiblingUniqueIndex(string $table): void
    {
        $driver = $this->schemaConnection()->getConnection()->getDriverName();
        // Derive the index name from the table so a per-panel prefixed table gets
        // its own index name (index names are global on SQLite/PG, not per-table).
        $name = $table.'_sibling_uniq';

        if ($driver === 'pgsql') {
            $this->dbConnection()->statement(sprintf('CREATE UNIQUE INDEX %s ON %s (parent_id, name_key COLLATE "C") WHERE trashed_at IS NULL', $name, $table));

            return;
        }

        if ($driver === 'sqlite') {
            $this->dbConnection()->statement(sprintf('CREATE UNIQUE INDEX %s ON %s (parent_id, name_key) WHERE trashed_at IS NULL', $name, $table));

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $this->dbConnection()->statement(sprintf(
                'ALTER TABLE `%s` ADD COLUMN `name_key_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin '
                .'GENERATED ALWAYS AS (CASE WHEN `trashed_at` IS NULL THEN SHA2(`name_key`, 256) ELSE NULL END) STORED',
                $table,
            ));
            $this->dbConnection()->statement(sprintf('CREATE UNIQUE INDEX `%s` ON `%s` (`parent_id`, `name_key_hash`)', $name, $table));

            return;
        }

        // Unknown driver: fall back to a plain (non-trash-aware) unique so the schema
        // is still defended. Supported drivers above are the canon.
        $this->schemaConnection()->table($table, static function (Blueprint $blueprint) use ($name): void {
            $blueprint->unique(['parent_id', 'name_key'], $name);
        });
    }

    /** name_key compares byte for byte; MySQL needs a binary collation on the column itself. */
    private function nameKeyColumn(Blueprint $table): void
    {
        $column = $table->string('name_key', 1024);
        if (in_array($this->dbConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $column->charset('utf8mb4')->collation('utf8mb4_bin');
        }
    }

    /**
     * Only the drive root may have no parent (one per storage). Without it a node
     * written with a NULL parent becomes a second root that the root listing never
     * shows, and sibling-unique cannot catch duplicates because NULL parents differ.
     *
     *  - PostgreSQL / SQLite: a PARTIAL unique index on (drive_id) WHERE parent_id IS NULL.
     *  - MySQL: a functional key part emits 1 only for a parentless row. Combined
     *    with drive_id it enforces one root per drive without a stored generated
     *    column based on cascading FK columns.
     *
     * Public so the upgrade migration adds the same index to an existing table.
     */
    public function addSingleRootIndex(string $table): void
    {
        $driver = $this->schemaConnection()->getConnection()->getDriverName();
        $name = $table.'_root_uniq';

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            $this->dbConnection()->statement(
                sprintf('CREATE UNIQUE INDEX %s ON %s (drive_id) WHERE parent_id IS NULL', $name, $table),
            );

            return;
        }

        if ($driver === 'mysql') {
            if (!$this->schemaConnection()->hasIndex($table, $name)) {
                $this->dbConnection()->statement(
                    sprintf('CREATE UNIQUE INDEX `%s` ON `%s` ', $name, $table).
                    '(`drive_id`, ((CASE WHEN `parent_id` IS NULL THEN 1 ELSE NULL END)))',
                );
            }

            return;
        }

        if ($driver === 'mariadb') {
            if (!$this->schemaConnection()->hasColumn($table, 'root_drive')) {
                $this->dbConnection()->statement(
                    sprintf('ALTER TABLE `%s` ADD COLUMN `root_drive` VARCHAR(64) ', $table).
                    'GENERATED ALWAYS AS (CASE WHEN `parent_id` IS NULL THEN `drive_id` ELSE NULL END) STORED',
                );
            }

            if (!$this->schemaConnection()->hasIndex($table, $name)) {
                $this->dbConnection()->statement(sprintf('ALTER TABLE `%s` ADD UNIQUE INDEX `%s` (`root_drive`)', $table, $name));
            }
        }
    }

    /**
     * The default-panel discriminator column. Only emitted on the panel roots
     * (storages + nodes); other entities resolve their panel via their parent FK.
     */
    private function panelDiscriminator(Blueprint $table): void
    {
        $table->string(column: 'profile', length: 64)->default(value: 'default')->index();
    }

    /**
     * Tenant keys belong to the host. The column always exists for CAS scope indexes.
     */
    private function tenant(Blueprint $table): void
    {
        HostKeyColumns::add(table: $table, column: 'tenant_id', nullable: true, index: true);
    }

    /**
     * The activity ip column. A native inet on Postgres (efficient,
     * type-checked); a portable varchar(45) (max IPv6 length) everywhere else. The
     * Activity model casts it to a plain string either way.
     */
    private function ipColumn(Blueprint $table): void
    {
        if ($this->schemaConnection()->getConnection()->getDriverName() === 'pgsql') {
            $table->ipAddress('ip')->nullable();

            return;
        }

        $table->string('ip', 45)->nullable();
    }

    /**
     * A nullable binary column (yjs_state). longBlob on MySQL/MariaDB (the
     * portability note — Yjs state can outgrow a plain blob); a portable
     * binary() (bytea on Postgres) everywhere else.
     */
    private function binaryColumn(Blueprint $table, string $column): void
    {
        $driver = $this->schemaConnection()->getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $table->longText($column)->nullable()->charset('binary');

            return;
        }

        $table->binary($column)->nullable();
    }

    /**
     * A signed BIGINT counter (0..2^63-1) with the same contract as
     * v_nodes.sequence: the CHECK rejects negatives on every driver, and SQLite
     * additionally rejects REAL values its overflow promotion would produce.
     */
    private function counterColumn(Blueprint $table, string $column): void
    {
        $type = $this->dbConnection()->getDriverName() === 'sqlite' ? "typeof({$column}) = 'integer' AND " : '';
        $table->rawColumn($column, "BIGINT CHECK({$type}{$column} >= 0)")->default(0);
    }

    /**
     * The composite head FK (current_revision_id, id) → revisions(id, <owner>): a
     * head can only name a revision of the same aggregate, and RESTRICT keeps
     * pruning from deleting it. SQLite cannot add a constraint to an existing
     * table, so there the Actions (and the head integrity tests) hold the
     * invariant alone.
     */
    private function addHeadForeignKey(string $aggregate, string $revisions, string $owner): void
    {
        $connection = $this->dbConnection();
        $table = $this->table($aggregate);
        $name = $table.'_head_fk';

        if ($connection->getDriverName() === 'sqlite'
            || !$this->schemaConnection()->hasColumn($table, 'current_revision_id')
            || $this->hasForeignKey($table, $name)) {
            return;
        }

        $grammar = $connection->getQueryGrammar();
        $connection->statement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s, %s) REFERENCES %s (%s, %s) ON DELETE RESTRICT',
            $grammar->wrapTable($table),
            $grammar->wrap($name),
            $grammar->wrap('current_revision_id'),
            $grammar->wrap('id'),
            $grammar->wrapTable($this->table($revisions)),
            $grammar->wrap('id'),
            $grammar->wrap($owner),
        ));
    }

    private function dropHeadForeignKey(string $aggregate): void
    {
        $table = $this->table($aggregate);
        $name = $table.'_head_fk';

        if ($this->schemaConnection()->hasTable($table) && $this->hasForeignKey($table, $name)) {
            $this->schemaConnection()->table($table, static fn (Blueprint $blueprint) => $blueprint->dropForeign($name));
        }
    }

    private function hasForeignKey(string $table, string $name): bool
    {
        foreach ($this->schemaConnection()->getForeignKeys($table) as $key) {
            if ($key['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    /** A signed counter with SQLite type checks preventing REAL overflow promotion. */
    private function scanInteger(Blueprint $table, string $column, int $minimum = 0): ColumnDefinition
    {
        $type = $this->dbConnection()->getDriverName() === 'sqlite' ? "typeof({$column}) = 'integer' AND " : '';

        return $table->rawColumn($column, "BIGINT CHECK({$column} IS NULL OR ({$type}{$column} >= {$minimum}))");
    }

    /** @param list<string> $values */
    private function scanEnum(Blueprint $table, string $column, array $values): ColumnDefinition
    {
        $allowed = implode(',', array_map(static fn (string $value): string => "'".$value."'", $values));

        return $table->rawColumn($column, "VARCHAR(16) CHECK({$column} IN ({$allowed}))");
    }

    private function scanReference(Blueprint $table, IdStrategy $ids, string $column, string $entity, bool $nullable = false, string $delete = 'cascade'): void
    {
        $ids->reference($table, $column, $nullable);
        $table->foreign($column)->references('id')->on(app(DatabaseConfig::class)->table($entity))->onDelete($delete);
    }

    private function defineScanTable(Blueprint $table, IdStrategy $ids, string $entity): void
    {
        $ids->key($table);
        if ($entity === 'scan_requests') {
            $this->scanReference($table, $ids, 'obligation_id', 'scan_obligations');
            $table->char('request_key', 36);
            $this->scanInteger($table, 'generation', 1);
            $table->timestamp('requested_at');
            $table->string('reason', 191);
            $table->string('correlation_id', 191);
            $table->unique(['obligation_id', 'request_key']);
            $table->unique(['obligation_id', 'generation']);

            return;
        }
        $this->scanReference($table, $ids, 'drive_id', 'drives');
        $this->tenant($table);
        $table->char('spec_key', 64);
        if ($entity === 'scan_backfill_runs') {
            $table->char('request_key', 36);
            $this->scanInteger($table, 'target_sequence');
            $this->scanInteger($table, 'last_admission_sequence')->default(0);
            $ids->reference($table, 'last_revision_id', true);
            $this->scanEnum($table, 'state', ['pending', 'completed'])->default('pending');
            $table->timestamps();
            $table->unique(['drive_id', 'spec_key']);
            $table->index(['state', 'id']);

            return;
        }
        $table->string('scanner_key', 64);
        $table->string('scanner_version', 64);
        $table->string('policy_version', 64);
        if ($entity === 'scan_policy_states') {
            $table->string('predicate_key', 80);
            $this->scanEnum($table, 'mode', ['off', 'new_content_only', 'all_content']);
            $table->char('config_fingerprint', 64);
            $table->string('rollout_key', 64)->nullable();
            $this->scanInteger($table, 'boundary_sequence')->nullable();
            $table->timestamp('activated_at');
            $table->timestamp('updated_at');
            $table->unique(['drive_id', 'predicate_key']);

            return;
        }
        $this->scanReference($table, $ids, 'revision_id', 'file_revisions');
        $this->scanReference($table, $ids, 'blob_id', 'blobs', true, 'restrict');
        $table->string('scope_key', 191);
        $table->char('content_identity', 64);
        $table->char('sha256', 64);
        $this->scanInteger($table, 'size_bytes');
        $this->scanInteger($table, 'generation', 1);
        $this->scanInteger($table, 'attempt', $entity === 'scan_evidence' ? 1 : 0);
        $table->string('reason', 32)->nullable();
        $table->rawColumn('retryable', 'BOOLEAN CHECK(retryable IN (false, true))')->default(false);
        $reasonCheck = "CHECK(COALESCE((outcome IS NULL AND reason IS NULL AND retryable = false) OR (outcome IN ('clean','infected') AND reason IS NULL AND retryable = false AND blob_id IS NOT NULL) OR (outcome = 'failed' AND reason IS NOT NULL), false))";
        $reasonDefinition = $this->dbConnection()->getDriverName() === 'mysql' ? '' : $reasonCheck;
        $table->rawColumn('outcome', "VARCHAR(16) CHECK(outcome IN ('clean','infected','failed')) {$reasonDefinition}")->nullable($entity === 'scan_obligations');
        if ($entity === 'scan_evidence') {
            $this->scanReference($table, $ids, 'obligation_id', 'scan_obligations');
            $this->scanReference($table, $ids, 'request_id', 'scan_requests');
            $table->timestamp('observed_at');
            $this->scanReference($table, $ids, 'reused_from_id', 'scan_evidence', true, 'set null');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 191)->nullable();
            $table->unique(['request_id', 'attempt']);
            $table->index(['revision_id', 'spec_key', 'outcome']);
            $table->index(['blob_id', 'outcome']);

            return;
        }
        $table->char('request_key', 36);
        $this->scanEnum($table, 'state', ['pending', 'leased', 'retry_wait', 'settled'])->default('pending');
        $table->timestamp('available_at');
        $table->char('lease_token', 36)->nullable();
        $leaseDefinition = $this->dbConnection()->getDriverName() === 'mysql' ? 'TIMESTAMP' : "TIMESTAMP CHECK((state = 'leased' AND lease_token IS NOT NULL AND lease_expires_at IS NOT NULL) OR (state <> 'leased' AND lease_token IS NULL AND lease_expires_at IS NULL)) CHECK(state <> 'settled' OR outcome IS NOT NULL)";
        $table->rawColumn('lease_expires_at', $leaseDefinition)->nullable();
        $table->timestamp('last_published_at')->nullable();
        $table->timestamps();
        $table->unique(['revision_id', 'spec_key']);
        $table->index(['state', 'available_at', 'id']);
        $table->index(['state', 'lease_expires_at', 'id']);
        $table->index(['drive_id', 'id']);
    }

    private function defineUploadCleanup(Blueprint $table, IdStrategy $ids): void
    {
        $this->scanReference($table, $ids, 'result_blob_id', 'blobs', true, 'restrict');
        $table->char('input_fingerprint', 64)->nullable();
        $table->rawColumn('cleanup_pending', 'BOOLEAN CHECK(cleanup_pending IN (false, true))')->default(false);
        $this->scanInteger($table, 'cleanup_attempts')->default(0);
        $table->timestamp('cleanup_available_at')->nullable();
        $table->string('cleanup_last_reason', 32)->nullable();
        $table->index(['status', 'cleanup_pending', 'cleanup_available_at', 'id'], $table->getTable().'_cleanup_idx');
    }

    /**
     * The mysql driver only ever installs CHECKs it can rely on: a server that parses but ignores
     * them (MariaDB behind the mysql driver, MySQL before 8.0.16) or clamps numeric overflow
     * (no STRICT sql_mode) fails closed instead of installing a constraint that never fires.
     */
    private function assertEnforcedMysqlChecks(): void
    {
        $db = $this->dbConnection();
        if ($db->getDriverName() !== 'mysql') {
            return;
        }
        $engine = $db->selectOne('SELECT VERSION() AS v, @@SESSION.sql_mode AS m');
        if (!$engine instanceof \stdClass || !is_string($engine->v ?? null) || !is_string($engine->m ?? null)) {
            throw new \RuntimeException('Cannot prove enforced MySQL constraints.');
        }
        if (str_contains($engine->v, 'MariaDB') || version_compare($engine->v, '8.0.16', '<') || !str_contains($engine->m, 'STRICT_')) {
            throw new \RuntimeException('Schema requires enforced MySQL CHECKs and strict numeric overflow.');
        }
    }

    public function addScanEvidenceAndWork(): void
    {
        $this->assertEnforcedMysqlChecks();
        foreach (['drives', 'file_revisions'] as $entity) {
            $name = app(DatabaseConfig::class)->table($entity);
            if (!$this->schemaConnection()->hasColumn($name, 'scan_admission_sequence')) {
                $this->schemaConnection()->table($name, fn (Blueprint $table) => $this->scanInteger($table, 'scan_admission_sequence')->default(0));
            }
        }
        foreach (['file_revisions', 'blobs'] as $entity) {
            $name = app(DatabaseConfig::class)->table($entity);
            if (!$this->schemaConnection()->hasColumn($name, 'quarantined_at')) {
                $this->schemaConnection()->table($name, static fn (Blueprint $table) => $table->timestamp('quarantined_at')->nullable());
            }
        }
        $blobs = app(DatabaseConfig::class)->table('blobs');
        if (!$this->schemaConnection()->hasColumn($blobs, 'storage_version')) {
            $this->schemaConnection()->table($blobs, static fn (Blueprint $table) => $table->string('storage_version', 255)->nullable());
        }
        $sessions = app(DatabaseConfig::class)->table('upload_sessions');
        if (!$this->schemaConnection()->hasColumn($sessions, 'cleanup_pending')) {
            $this->schemaConnection()->table($sessions, fn (Blueprint $table) => $this->defineUploadCleanup($table, $this->ids()));
        }
        foreach (['scan_policy_states', 'scan_obligations', 'scan_backfill_runs', 'scan_requests', 'scan_evidence'] as $entity) {
            if (!$this->schemaConnection()->hasTable(app(DatabaseConfig::class)->table($entity))) {
                $this->createTable($entity);
            } elseif ($this->dbConnection()->getDriverName() === 'mysql') {
                $this->afterCreate($entity);
            }
        }
    }

    public function removeScanEvidenceAndWork(): void
    {
        foreach (['scan_evidence', 'scan_requests', 'scan_backfill_runs', 'scan_obligations', 'scan_policy_states'] as $entity) {
            $this->dropTable($entity);
        }
        $sessions = app(DatabaseConfig::class)->table('upload_sessions');
        if ($this->schemaConnection()->hasColumn($sessions, 'cleanup_pending')) {
            $this->schemaConnection()->table($sessions, function (Blueprint $table): void {
                $table->dropForeign(['result_blob_id']);
                $table->dropIndex($table->getTable().'_result_blob_id_index');
                $table->dropIndex($table->getTable().'_cleanup_idx');
                $table->dropColumn(['result_blob_id', 'input_fingerprint', 'cleanup_pending', 'cleanup_attempts', 'cleanup_available_at', 'cleanup_last_reason']);
            });
        }
        foreach (['blobs' => ['quarantined_at', 'storage_version'], 'file_revisions' => ['scan_admission_sequence', 'quarantined_at'], 'drives' => ['scan_admission_sequence']] as $entity => $columns) {
            $name = app(DatabaseConfig::class)->table($entity);
            $present = array_values(array_filter($columns, fn (string $column): bool => $this->schemaConnection()->hasColumn($name, $column)));
            if ($present !== []) {
                $this->schemaConnection()->table($name, static fn (Blueprint $table) => $table->dropColumn($present));
            }
        }
    }

    private function table(string $entity): string
    {
        return app(DatabaseConfig::class)->table($entity);
    }

    private function ids(): IdStrategy
    {
        return app(IdStrategyResolver::class)->resolve();
    }
}
