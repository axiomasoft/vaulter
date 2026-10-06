<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\IdStrategy;

/** The shared Vaulter schema. Runtime features never control table existence. */
final class SchemaBuilder
{
    /**
     * The Schema builder for the connection Vaulter's tables live on
     * (`app(\Vaulter\Configuration\DatabaseConfig::class)->connection()`, F-P2.3-02) — null defers to the host's default
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
    private function dbConnection(): ConnectionInterface
    {
        return DB::connection(app(DatabaseConfig::class)->connection());
    }

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
        'node_grants',
        'node_shares',
        'node_comments',
        'node_document_versions',
        'upload_sessions',
        'upload_parts',
        'activity',
        'outbox',
        'webhook_endpoints',
        'webhook_deliveries',
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
        $ids = $this->ids();

        $this->schemaConnection()->create(app(DatabaseConfig::class)->table($entity), function (Blueprint $table) use ($ids, $entity): void {
            match ($entity) {
                'drives' => $this->defineDrives(table: $table, ids: $ids),
                'nodes' => $this->defineNodes(table: $table, ids: $ids),
                'blobs' => $this->defineBlobs(table: $table, ids: $ids),
                'files' => $this->defineFiles(table: $table, ids: $ids),
                'file_revisions' => $this->defineFileRevisions(table: $table, ids: $ids),
                'file_revision_blobs' => $this->defineFileRevisionBlobs(table: $table, ids: $ids),
                'node_grants' => $this->defineNodeGrants(table: $table, ids: $ids),
                'node_shares' => $this->defineNodeShares(table: $table, ids: $ids),
                'node_comments' => $this->defineNodeComments(table: $table, ids: $ids),
                'node_document_versions' => $this->defineDocumentVersions(table: $table, ids: $ids),
                'upload_sessions' => $this->defineUploadSessions(table: $table, ids: $ids),
                'upload_parts' => $this->defineUploadParts(table: $table, ids: $ids),
                'activity' => $this->defineActivity(table: $table, ids: $ids),
                'outbox' => $this->defineOutbox(table: $table, ids: $ids),
                'webhook_endpoints' => $this->defineWebhookEndpoints(table: $table, ids: $ids),
                'webhook_deliveries' => $this->defineWebhookDeliveries(table: $table, ids: $ids),
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
        $this->schemaConnection()->dropIfExists(app(DatabaseConfig::class)->table($entity));
    }

    /**
     * v_drives — the ownable polymorphic disk container (§2.1). Always created;
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
        $table->string('status', 16)->default('active')->index();
        $this->extraData(table: $table);
        HostKeyColumns::add(table: $table, column: 'created_by', nullable: true, index: false);
        $table->timestamps();

        $table->index(['owner_type', 'owner_id']);
        $table->index(['profile', 'status']);

        // Idempotency backbone for get-or-create (M1c): one live storage per
        // (owner_type, owner_id, panel). A partial unique (driver-aware, added in
        // afterCreate) lets a soft-deleted row's owner be re-provisioned while a
        // concurrent first-touch converges on one row via this constraint.

        // root_node_id is NOT a DB foreign key: v_drives and v_nodes reference
        // each other (the storage points at its root, every node points at its
        // storage), so a hard FK here would create a chicken-and-egg insert order.
    }

    /**
     * v_nodes — the unified folder|file|document adjacency tree (§2.2, the
     * centre). Always created; carries the panel discriminator, the soft-trash
     * triplet and the sibling-unique guard (added in afterCreate, driver-aware).
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
        $table->string('mime_type', 191)->nullable()->index();
        $table->string('extension', 32)->nullable();
        $table->bigInteger('size_bytes')->default(0);
        $ids->reference(table: $table, column: 'current_version_id', nullable: true);
        $ids->reference(table: $table, column: 'file_id', nullable: true);
        $table->integer('position')->default(0);
        $table->boolean('is_pinned')->default(false);
        $table->boolean('inherit_grants')->default(true);
        $ids->reference(table: $table, column: 'shortcut_target_id', nullable: true);
        HostKeyColumns::add(table: $table, column: 'owner_id', nullable: true, index: false);
        $this->extraData(table: $table);

        // Soft-trash triplet (D17) — distinct from the permanent soft-delete below.
        $table->timestamp('trashed_at')->nullable();
        HostKeyColumns::add(table: $table, column: 'trashed_by', nullable: true, index: false);
        $ids->reference(table: $table, column: 'trashed_from_parent_id', nullable: true);

        HostKeyColumns::add(table: $table, column: 'created_by', nullable: true, index: false);
        HostKeyColumns::add(table: $table, column: 'updated_by', nullable: true, index: false);
        $table->softDeletes();
        $table->timestamps();

        // Hot listing indexes (§2.2 index plan). (drive_id, parent_id) is NOT
        // repeated as its own index (F-P4.1-02, VLT-REMED P4.3): it is a strict
        // column-prefix of the composite below, so it never wins over it and only
        // pays extra write cost. The purge-trash scan index (trashed_at, driver-aware
        // partial) is emitted in afterCreate() — F-P4.1-01.
        $table->index(['drive_id', 'parent_id', 'position']);
        $table->index(['drive_id', 'kind', 'trashed_at']);

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
     * v_blobs — the content-addressable, immutable payload (dedup + GC, §2.3).
     * Gated on 'blobs'. storage_key is scope-qualified (H.3) and the dedup-unique
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

        // Scope-qualified storage_key: "<scope>/ab/cd/<sha256>" (H.3 / D18′). A
        // scope-free path under per-scope dedup would let one GC delete another
        // scope's live bytes.
        $table->text('storage_key');

        // Blob state machine (logic is M1b; the columns exist now): pending →
        // committed → tombstoned → deleting → deleted. No standalone index on
        // status (F-P4.1-02, VLT-REMED P4.3): it is a strict column-prefix of the
        // (status, ref_count, delete_after_at) GC index below, so it never wins
        // over it and only pays extra write cost.
        $table->string('status', 12)->default('pending');
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
        $this->extraData(table: $table);

        $table->string('scope_key', 191);
        $name = $table->getTable();
        $table->unique(['scope_key', 'sha256'], $name.'_scope_sha_uniq');
        $table->unique('storage_key', $name.'_storage_key_uniq');

        // GC scan support (§2.3): committed orphans past their grace deadline.
        $table->index(['status', 'ref_count', 'delete_after_at'], $name.'_gc_idx');
    }

    /**
     * v_files — the mutable 1:1 handle for a kind=file node (§2.4). Gated on
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
        $this->extraData(table: $table);
        HostKeyColumns::add(table: $table, column: 'created_by', nullable: true, index: false);
        $table->timestamps();

        $table->unique('node_id', $table->getTable().'_node_uniq');

        $table->foreign('node_id')
            ->references('id')
            ->on(app(DatabaseConfig::class)->table('nodes'))
            ->cascadeOnDelete();
    }

    /**
     * v_file_revisions — the immutable file ↔ blob version (§2.4). Gated on
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
        $table->string('original_name');
        $table->string('status', 12)->default('pending')->index();
        $table->boolean('keep_forever')->default(false);
        $this->extraData(table: $table);
        HostKeyColumns::add(table: $table, column: 'created_by', nullable: true, index: false);
        $table->timestamp('created_at')->nullable();
        $table->timestamp('committed_at')->nullable();

        $table->unique(['file_id', 'number'], $table->getTable().'_no_uniq');
        $table->index(['file_id', 'status']);

        $table->foreign('file_id')
            ->references('id')
            ->on(app(DatabaseConfig::class)->table('files'))
            ->cascadeOnDelete();
    }

    /**
     * v_file_revision_blobs — the edge table that is the GC truth (§2.4). Gated on
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
     * v_node_grants — the per-node ACL grant with inheritance (§2.6 / H.2). Gated
     * on 'grants'. The (node_id, subject_type, subject_id, scope, effect) live-row
     * UNIQUE (added driver-aware in afterCreate) keeps one allow + one deny per
     * subject/scope per node; the (subject_type, subject_id) index backs the
     * "shared with me" fast pass.
     */
    private function defineNodeGrants(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->tenant(table: $table);
        $ids->reference(table: $table, column: 'node_id');

        // Subject morph: a registered alias (user|role|team), never a raw FQCN.
        HostKeyColumns::add(table: $table, column: 'subject_id', nullable: false, index: true);
        $table->string('subject_type');

        $table->string('level', 16)->index();
        $table->unsignedInteger('permissions')->default(0);
        $table->string('effect', 5)->default('allow')->index();
        $table->string('scope', 8)->default('subtree');

        // Reserved for the OPTIONAL materialised effective-grants read-model — the
        // source of truth in M2a is the direct rows + the tree, so these stay
        // false/null (the inherited walk is computed, not stored).

        $table->timestamp('expires_at')->nullable()->index();
        HostKeyColumns::add(table: $table, column: 'granted_by', nullable: true, index: false);
        $this->extraData(table: $table);
        $table->timestamps();

        $name = $table->getTable();

        // "Shared with me": every node a subject is granted access to (§2.6).
        $table->index(['subject_type', 'subject_id'], $name.'_subject_idx');
        $table->index(['node_id', 'effect'], $name.'_node_effect_idx');

        $table->foreign('node_id')
            ->references('id')
            ->on(app(DatabaseConfig::class)->table('nodes'))
            ->cascadeOnDelete();
    }

    /**
     * v_node_shares — the bearer share-link, the SEPARATE public sharing channel
     * (§2.7 / M2c). Gated on 'share_links'. ONLY the sha256 token_hash is stored
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
        // and never persisted (§2.7 hardening). token_prefix is a non-secret
        // leading slice kept purely for owner-side UX lookup.
        $table->char('token_hash', 64);
        $table->string('token_prefix', 16)->index();

        // A share grants EXACTLY this bitmask on its node-set (default READ); level
        // is the human-facing label that mirrors the bits.
        $table->unsignedInteger('permissions')->default(1);
        $table->string('level', 16)->default('reader');
        $table->string('scope', 8)->default('item');

        // Optional access gates (§2.7): a hashed password, a require-login flag and
        // a discovery flag (whether the share may be listed/indexed).
        $table->string('password_hash')->nullable();
        $table->boolean('require_login')->default(false);
        $table->boolean('allow_discovery')->default(false);

        // Lifecycle bounds: an optional expiry, an optional atomic use budget and a
        // revocation tombstone (§2.7). use_count is incremented atomically and
        // bounded by max_uses.
        $table->timestamp('expires_at')->nullable();
        $table->unsignedInteger('max_uses')->nullable();
        $table->unsignedInteger('use_count')->default(0);
        $table->timestamp('revoked_at')->nullable()->index();

        HostKeyColumns::add(table: $table, column: 'granted_by', nullable: true, index: false);
        $this->extraData(table: $table);
        $table->timestamps();

        $name = $table->getTable();

        // token_hash is the bearer lookup key — UNIQUE so two shares can never
        // collide on a token (the loser of a mint race retries with a fresh token).
        $table->unique('token_hash', $name.'_token_hash_uniq');

        // Owner-side "shares on this node" listing (§2.7).
        $table->index(['node_id', 'revoked_at'], $name.'_node_revoked_idx');

        $table->foreign('node_id')
            ->references('id')
            ->on(app(DatabaseConfig::class)->table('nodes'))
            ->cascadeOnDelete();
    }

    /**
     * v_node_comments — the NODE-attached, threaded, anchorable comment (ADR-0014
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

        $this->extraData(table: $table);
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
     * v_node_document_versions — the NODE-attached, append-only document version
     * (master plan §2.5 / M4a). Gated on 'document_versions'. DISTINCT from the legacy
     * document_id-attached v_document_versions (that table is untouched); this one
     * hangs off a kind=document node via node_id (cascade). version_no is monotonic
     * per node (unique). content is the TipTap/ProseMirror JSON projection; yjs_state
     * is the binary Yjs CRDT update (the M4b collaboration source of truth), with
     * yjs_state_size caching its length. Append-only — created_at only, no
     * updated_at / soft-delete.
     */
    private function defineDocumentVersions(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->tenant(table: $table);
        $ids->reference(table: $table, column: 'node_id');

        $table->integer('version_no');

        // The TipTap/ProseMirror snapshot (the structural JSON projection). jsonb on
        // Postgres (the gin jsonb_path_ops index below requires it — a plain json column
        // is rejected by that operator class); maps to json on MySQL and text on SQLite.
        $table->jsonb('content')->nullable();

        // The binary Yjs CRDT update — the realtime source of truth (M4b). bytea on
        // Postgres, longBlob on MySQL (§2.5 portability note); yjs_state_size caches
        // its byte length so a list never streams the blob to size it.
        $this->binaryColumn(table: $table, column: 'yjs_state');
        $table->integer('yjs_state_size')->nullable();

        // No DB default (VLT-CANON P0.4/L3): the writing engine stamps its own format
        // label explicitly — a silent 'tiptap' default protected exactly the kind of
        // stale mime→editor association H.5 exists to prevent (D4/D12).
        $table->string('format', 16);
        $table->char('content_hash', 64)->nullable();
        $table->string('snapshot_label')->nullable();
        $table->boolean('is_autosave')->default(false);
        $table->string('status', 12)->default('committed')->index();
        $table->boolean('keep_forever')->default(false);

        $this->extraData(table: $table);
        HostKeyColumns::add(table: $table, column: 'created_by', nullable: true, index: false);
        $table->timestamp('created_at')->nullable();

        $name = $table->getTable();

        // version_no is monotonic per node — the unique index is the append-only
        // backbone (two concurrent commits serialise on it).
        $table->unique(['node_id', 'version_no'], $name.'_no_uniq');

        // The autosave-vs-named history cursor (§2.5): the head autosave lookup and
        // the named-version listing both scan this.
        $table->index(['node_id', 'is_autosave', 'created_at'], $name.'_history_idx');

        $table->foreign('node_id')
            ->references('id')
            ->on(app(DatabaseConfig::class)->table('nodes'))
            ->cascadeOnDelete();
    }

    /**
     * v_upload_sessions — the resumable-ingest session (§2.8 / D16 / M3a). Gated on
     * 'uploads'. upload_id is the client-facing UNIQUE handle (the S3 uploadId / tus
     * resource id). received_bytes is the AUTHORITATIVE server-side tus offset — the
     * client never decides where to resume. status drives the lifecycle
     * (initiated→uploading→assembled→committed, with abort/expire). assembled_blob_id
     * points at the blob produced by BlobStore.commit on completion (H.3 — server
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

        // Targets (§2.8): a NEW-VERSION upload pins node_id; a NEW-FILE upload pins
        // target_parent_id (null = storage root) + target_name. No DB FK on node_id /
        // target_parent_id: a session may outlive a concurrently-moved node and the
        // sweeper must still reclaim it.
        $ids->reference(table: $table, column: 'target_node_id', nullable: true);
        $ids->reference(table: $table, column: 'target_parent_id', nullable: true);
        $table->string('target_name')->nullable();
        $ids->reference(table: $table, column: 'result_node_id', nullable: true);
        $ids->reference(table: $table, column: 'result_revision_id', nullable: true);

        $table->string('protocol', 16)->default('tus');
        $table->string('status', 16)->default('initiated');

        // The client-DECLARED sha256 (optional). It is NEVER trusted as the content
        // address — BlobStore.commit recomputes sha256 from the assembled staged file
        // (H.3). It is kept only as an optional integrity cross-check.
        $table->char('expected_sha256', 64)->nullable();
        $table->bigInteger('total_size_bytes')->nullable();

        // The authoritative server-side resume offset (tus Upload-Offset). A HEAD
        // returns this; a PATCH advances it.
        $table->bigInteger('received_bytes')->default(0);
        $table->bigInteger('chunk_size')->nullable();
        $table->string('mime_type', 191)->nullable();

        // Inline manifest for the small-part fast path (§2.8): a denormalised parts
        // list a session may carry without spilling to v_upload_parts.
        $table->json('parts')->nullable();

        // The blob the assembled bytes deduped/committed into (set at commit).
        $ids->reference(table: $table, column: 'assembled_blob_id', nullable: true);

        // The tus Upload-Metadata (decoded key→value map) — distinct from the host
        // extra_data hatch.
        $table->json('metadata')->nullable();
        $this->extraData(table: $table);

        $table->timestamp('expires_at')->nullable();
        HostKeyColumns::add(table: $table, column: 'created_by', nullable: true, index: false);
        $table->timestamps();

        $name = $table->getTable();

        // upload_id is the resume key — UNIQUE so two sessions can never collide on a
        // handle (the loser of a mint race retries with a fresh uuid).
        $table->unique('upload_id', $name.'_upload_id_uniq');

        // The sweeper scan axis (§2.8): stale OPEN sessions past expires_at. The
        // (status, expires_at) composite is added driver-aware in afterCreate so the
        // index name stays distinct per prefixed panel table.
        $table->index('drive_id', $name.'_drive_idx');

        $table->foreign('drive_id')
            ->references('id')
            ->on(app(DatabaseConfig::class)->table('drives'))
            ->cascadeOnDelete();
    }

    /**
     * v_upload_parts — the normalised per-part record (§2.8 / D16 / M3a). Gated on
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
     * v_activity — the append-only audit/activity feed (§2.9 / M2c-activity). Gated
     * on 'activity'. ONE row per domain event; recipients are resolved at READ by
     * scoping the feed to a storage/node — there is NEVER a per-affected-user
     * fan-out (anti-NC-explosion, §1.5).
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
        $table->string('event_id', 26)->nullable()->index();

        // Target morph — the subject of the verb (nullable: e.g. nothing for a
        // storage-level row). No DB FK: the target may be any model, swappable.
        $ids->reference(table: $table, column: 'target_id', nullable: true);
        $table->string('target_type')->nullable();

        // The structured payload (before/after, old/new path/role, …). Always
        // present — this is the audit body, distinct from the host extra_data hatch.
        // jsonb on Postgres (the gin jsonb_path_ops index requires it); json/text elsewhere.
        $table->jsonb('context')->nullable();

        $this->ipColumn(table: $table);
        $table->text('user_agent')->nullable();

        // request_id is an opaque per-request CORRELATION id (X-Request-Id / a
        // minted uuid), NOT a Vaulter entity reference — so it is a fixed nullable
        // uuid column independent of the panel id strategy (§2.9 "request_id uuid").
        $table->uuid('request_id')->nullable();

        $this->extraData(table: $table);

        // Append-only: created_at ONLY. No timestamps()/softDeletes() — there is no
        // update or delete path for an audit row. No inline ->index() here
        // (F-P4.1-02, VLT-REMED P4.3): addActivityCreatedAtIndex() below already
        // emits the one (created_at) index this table carries; a second one on the
        // very same single column was a literal duplicate, pure write cost on this
        // append-only, write-hottest table with zero read benefit.
        $table->timestamp('created_at')->nullable();

        $name = $table->getTable();

        // §2.9 feed indexes — every read axis is newest-first. The leading column is
        // the scope/filter; created_at is the cursor tail.
        $table->index(['actor_type', 'actor_id', 'created_at'], $name.'_actor_idx');
        $table->index(['target_type', 'target_id', 'created_at'], $name.'_target_idx');
        $table->index(['verb', 'created_at'], $name.'_verb_idx');
        $table->index(['drive_id', 'created_at'], $name.'_drive_idx');
    }

    /**
     * v_outbox — the transactional-outbox staging table (master plan §2.11 / D14 /
     * M3b), ported from chatom 1:1. Gated on 'reliability'. ONE row per domain event,
     * idempotent by event_id (UNIQUE) so the same event never stages a duplicate and
     * the relay drains each row exactly once.
     *
     * The id honours the panel id strategy; panel is the discriminator (a row is
     * fanned out for its panel's endpoints); topic is an optional routing hint
     * (e.g. the storage/node id). status defaults 'pending'; the relay flips it to
     * 'dispatched' under a row lock. The (status, available_at) index is the relay's
     * drain cursor.
     */
    private function defineOutbox(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);

        // event_id is the idempotency key — a stable, caller-supplied string (NOT a
        // Vaulter entity reference), so it is a plain unique string column independent
        // of the panel id strategy.
        $table->string('event_id')->unique();
        $this->panelDiscriminator(table: $table);
        $ids->reference(table: $table, column: 'drive_id', nullable: true);
        $table->string('aggregate_type', 32)->nullable();
        $table->string('aggregate_id', 64)->nullable();
        $table->bigInteger('sequence')->nullable();
        $table->string('topic')->nullable();
        $table->string('event');
        $table->json('payload');
        $table->timestamp('available_at');
        $table->timestamp('dispatched_at')->nullable();
        $table->unsignedInteger('attempts')->default(0);
        $table->string('status')->default('pending');
        $table->timestamps();

        $name = $table->getTable();

        $table->index(['status', 'available_at'], $name.'_drain_idx');
        // The retention sweep (vaulter:prune-webhooks) deletes settled rows by
        // (status='dispatched', dispatched_at <= threshold) — its own scan cursor.
        $table->index(['status', 'dispatched_at'], $name.'_prune_idx');
    }

    /**
     * v_webhook_endpoints — the subscriber endpoints (master plan §2.11 / D14 / M3b),
     * ported from chatom 1:1. Gated on 'reliability'. events is the JSON subscription
     * list (matched in PHP for driver portability); signing_secret signs the
     * deliveries (HMAC-SHA256); failure_count drives the circuit breaker.
     */
    private function defineWebhookEndpoints(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);

        $table->string('url');
        $table->json('events');
        // text, not string: the signing_secret is encrypted at rest (F-P1.7-02) and
        // the ciphertext is longer than the 64-char plaintext.
        $table->text('signing_secret');
        $table->boolean('is_active')->default(true)->index();
        $table->timestamp('last_triggered_at')->nullable();
        $table->unsignedInteger('failure_count')->default(0);
        $table->timestamps();
    }

    /**
     * v_webhook_deliveries — a single delivery attempt with retry bookkeeping (master
     * plan §2.11 / D14 / M3b), ported from chatom 1:1. Gated on 'reliability'.
     * endpoint_id is an explicit FK → v_webhook_endpoints (cascade on delete). The
     * (status, next_retry_at) index is the due-retry sweep cursor; (status, updated_at)
     * backs the retention prune of terminal (delivered|dead) rows.
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

        $name = $table->getTable();

        $table->index(['status', 'next_retry_at'], $name.'_retry_idx');
        // The retention sweep (vaulter:prune-webhooks) deletes terminal rows by
        // (status in delivered|dead, updated_at <= threshold) — its own scan cursor.
        $table->index(['status', 'updated_at'], $name.'_prune_idx');

        $table->foreign('endpoint_id')
            ->references('id')
            ->on(app(DatabaseConfig::class)->table('webhook_endpoints'))
            ->cascadeOnDelete();
    }

    /**
     * Post-create hooks that cannot be expressed inside the Blueprint, currently
     * the driver-aware sibling-unique guard on v_nodes.
     */
    private function afterCreate(string $entity): void
    {
        if ($entity === 'drives') {
            $this->addOwnerUniqueIndex(table: app(DatabaseConfig::class)->table('drives'));
        }

        if ($entity === 'nodes') {
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

        if ($entity === 'node_document_versions') {
            $this->addDocumentContentGinIndex(table: app(DatabaseConfig::class)->table('node_document_versions'));
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
     * The plain (created_at desc) feed index (§2.9). Added here rather than inline
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
     * The OPTIONAL gin(context) index for jsonb containment queries (§2.9). Only
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
     * The OPTIONAL gin(content jsonb_path_ops) index for document-version content
     * containment queries (§2.5). Postgres-only (gin + jsonb); on SQLite/MySQL
     * content is a plain json column and the index is skipped — the history is read
     * by (node_id, version_no) / (node_id, is_autosave), all btree-covered.
     */
    private function addDocumentContentGinIndex(string $table): void
    {
        if ($this->schemaConnection()->getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->dbConnection()->statement(sprintf('CREATE INDEX %s_content_gin ON %s USING gin (content jsonb_path_ops)', $table, $table));
    }

    /**
     * The §2.7 partial expiry index: (expires_at) WHERE revoked_at IS NULL — the
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
     * F-P4.1-01 (VLT-REMED P4.3): the trashed_at scan index PurgeTrashCommand's
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
     * The §2.8 sweeper index: (status, expires_at). The leading status narrows to the
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
     * One LIVE grant per (node_id, subject_type, subject_id, scope, effect) — the
     * §2.6 grant-unique key. Driver-aware: a partial (soft-delete-aware) index on
     * Postgres/SQLite, a MySQL generated-column fallback, a plain unique elsewhere.
     */
    private function addGrantUniqueIndex(string $table): void
    {
        $this->schemaConnection()->table($table, static function (Blueprint $blueprint) use ($table): void {
            $blueprint->unique(['node_id', 'subject_type', 'subject_id', 'scope', 'effect'], $table.'_grant_uniq');
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
        $this->schemaConnection()->table($table, static function (Blueprint $blueprint) use ($table): void {
            $blueprint->unique(['owner_type', 'owner_id', 'profile'], $table.'_owner_profile_uniq');
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
        $driver = $this->schemaConnection()->getConnection()->getDriverName();
        // Derive the index name from the table so a per-panel prefixed table gets
        // its own index name (index names are global on SQLite/PG, not per-table).
        $name = $table.'_sibling_uniq';

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            $this->dbConnection()->statement(
                sprintf('CREATE UNIQUE INDEX %s ON %s (parent_id, lower(name)) ', $name, $table).
                'WHERE deleted_at IS NULL AND trashed_at IS NULL',
            );

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $this->dbConnection()->statement(
                sprintf('ALTER TABLE `%s` ADD COLUMN `sibling_active` VARCHAR(512) ', $table).
                'GENERATED ALWAYS AS (CASE WHEN `deleted_at` IS NULL AND `trashed_at` IS NULL '.
                "THEN CONCAT(COALESCE(`parent_id`, ''), ':', LOWER(`name`)) ELSE NULL END) STORED",
            );

            $this->dbConnection()->statement(sprintf('ALTER TABLE `%s` ADD UNIQUE INDEX `%s` (`sibling_active`)', $table, $name));

            return;
        }

        // Unknown driver: fall back to a plain (non-soft-delete-aware) unique so
        // the schema is still defended. Supported drivers above are the canon.
        $this->schemaConnection()->table($table, static function (Blueprint $blueprint) use ($name): void {
            $blueprint->unique(['parent_id', 'name'], $name);
        });
    }

    /**
     * Only the drive root may have no parent (one per storage). Without it a node
     * written with a NULL parent becomes a second root that the root listing never
     * shows, and sibling-unique cannot catch duplicates because NULL parents differ.
     *
     *  - PostgreSQL / SQLite: a PARTIAL unique index on (drive_id) WHERE parent_id IS NULL.
     *  - MySQL/MariaDB: a stored generated column carries drive_id only for a
     *    parentless row (NULL otherwise, exempt from UNIQUE) under a plain unique index.
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

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Two auto-committing DDL statements: each step is skipped when already
            // applied, so an upgrade interrupted between them can simply be re-run.
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
     * The activity ip column (§2.9). A native inet on Postgres (efficient,
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
     * A nullable binary column (§2.5 yjs_state). longBlob on MySQL/MariaDB (the
     * §2.5 portability note — Yjs state can outgrow a plain blob); a portable
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
     * The optional extra_data jsonb escape hatch (D13), gated per panel.
     */
    private function extraData(Blueprint $table): void
    {
        // The node extra-data flag remains until P4.1; it never gates a table.
        if ($table->getTable() === app(DatabaseConfig::class)->table('nodes') && !app(VaulterConfig::class)->extraDataEnabled()) {
            return;
        }

        $table->json(column: 'extra_data')->nullable();
    }

    private function ids(): IdStrategy
    {
        return app(IdStrategyResolver::class)->resolve();
    }
}
