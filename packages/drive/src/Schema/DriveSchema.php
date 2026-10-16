<?php

declare(strict_types=1);

namespace Vaulter\Drive\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use InvalidArgumentException;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Contracts\IdStrategy;
use Vaulter\Schema\HostKeyColumns;
use Vaulter\Schema\ModuleSchema;
use Vaulter\Schema\SchemaHelpers;

/** The tables the drive module owns: drives, the node tree, files and their revisions, scan work, grants and shares, uploads, activity and the change journal. */
#[Internal]
final class DriveSchema implements ModuleSchema
{
    public function __construct(private readonly SchemaHelpers $helpers = new SchemaHelpers) {}

    /** @return list<string> the entity keys of the tables this schema owns, in dependency order */
    public function entities(): array
    {
        return [
            'drives',
            'nodes',
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
            'node_user_states',
            'upload_sessions',
            'upload_parts',
            'activity',
            'drive_change_heads',
            'drive_changes',
        ];
    }

    public function create(string $entity): void
    {
        $ids = $this->helpers->ids();

        $this->helpers->schemaConnection()->create(app(DatabaseConfig::class)->table($entity), function (Blueprint $table) use ($ids, $entity): void {
            match ($entity) {
                'drives' => $this->defineDrives(table: $table, ids: $ids),
                'nodes' => $this->defineNodes(table: $table, ids: $ids),
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
                'node_user_states' => $this->defineNodeUserStates(table: $table, ids: $ids),
                'upload_sessions' => $this->defineUploadSessions(table: $table, ids: $ids),
                'upload_parts' => $this->defineUploadParts(table: $table, ids: $ids),
                'activity' => $this->defineActivity(table: $table, ids: $ids),
                'drive_change_heads' => $this->defineDriveChangeHeads(table: $table, ids: $ids),
                'drive_changes' => $this->defineDriveChanges(table: $table, ids: $ids),
                default => throw new InvalidArgumentException(sprintf('Unknown drive entity [%s].', $entity)),
            };
        });

        $this->afterCreate(entity: $entity);
    }

    public function drop(string $entity): void
    {
        // A file and its revisions reference each other (head FK one way, owner FK the other), so the head FK goes first
        // whichever side drops.
        if (in_array($entity, ['files', 'file_revisions'], true)) {
            $this->helpers->dropHeadForeignKey(aggregate: 'files');
        }

        $this->helpers->schemaConnection()->dropIfExists(app(DatabaseConfig::class)->table($entity));
    }

    /**
     * v_drives — the ownable polymorphic disk container. Always created;
     * carries the panel discriminator.
     */
    private function defineDrives(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->helpers->panelDiscriminator(table: $table);
        $this->helpers->tenant(table: $table);

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
        $this->helpers->panelDiscriminator(table: $table);
        $this->helpers->tenant(table: $table);
        $ids->reference(table: $table, column: 'drive_id');
        $ids->reference(table: $table, column: 'parent_id', nullable: true);

        $table->string('kind', 16)->index();
        $table->string('name');
        $this->helpers->nameKeyColumn(table: $table);
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
     * v_files — the mutable 1:1 handle for a kind=file node. Gated on
     * 'versions'.
     */
    private function defineFiles(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->helpers->tenant(table: $table);
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
        $this->helpers->tenant(table: $table);
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
        $this->helpers->tenant(table: $table);
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
        $this->helpers->tenant(table: $table);
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
     * v_node_user_states — one actor's personal state of a node: starred
     * now, more per-user flags later. Never part of the node: a star changes neither
     * the node's sequence nor its updated_at, and is invisible to other actors.
     */
    private function defineNodeUserStates(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->helpers->tenant(table: $table);
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
            ->on($this->helpers->table('nodes'))
            ->cascadeOnDelete();
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
        $this->helpers->tenant(table: $table);
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
        $this->helpers->tenant(table: $table);
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

        $this->helpers->ipColumn(table: $table);
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
     * v_drive_change_heads — one row per drive: the change journal's counter
     * . last_seq is the seq of the newest entry; the UPDATE that raises it
     * is the journal lock, held until the mutation commits, so entries become visible
     * in seq order. pruned_through is the highest seq the retention prune deleted;
     * epoch changes on a reset and expires every cursor of the drive.
     */
    private function defineDriveChangeHeads(Blueprint $table, IdStrategy $ids): void
    {
        $ids->reference(table: $table, column: 'drive_id', nullable: false);
        $this->helpers->tenant(table: $table);
        $table->bigInteger('last_seq')->default(0);
        $table->bigInteger('pruned_through')->default(0);
        $table->unsignedInteger('epoch')->default(1);
        $table->timestamp('updated_at')->nullable();

        $table->primary(['drive_id']);
        $table->foreign('drive_id')
            ->references('id')
            ->on($this->helpers->table('drives'))
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
        $this->helpers->tenant(table: $table);
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
            ->on($this->helpers->table('drives'))
            ->cascadeOnDelete();
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
        $this->helpers->tenant($table);
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
        $reasonDefinition = $this->helpers->dbConnection()->getDriverName() === 'mysql' ? '' : $reasonCheck;
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
        $leaseDefinition = $this->helpers->dbConnection()->getDriverName() === 'mysql' ? 'TIMESTAMP' : "TIMESTAMP CHECK((state = 'leased' AND lease_token IS NOT NULL AND lease_expires_at IS NOT NULL) OR (state <> 'leased' AND lease_token IS NULL AND lease_expires_at IS NULL)) CHECK(state <> 'settled' OR outcome IS NOT NULL)";
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
     * The v_nodes change sequence: BIGINT NOT NULL DEFAULT 0 with a non-negative CHECK, added after the
     * table is created because the CHECK is spelled per engine. A step of afterCreate('nodes').
     */
    public function addNodeSequence(): void
    {
        $this->helpers->assertEnforcedMysqlChecks();
        $name = app(DatabaseConfig::class)->table('nodes');
        if ($this->helpers->schemaConnection()->hasColumn($name, 'sequence')) {
            return;
        }
        $wrapped = $this->helpers->dbConnection()->getQueryGrammar()->wrapTable($name);
        $check = $this->helpers->dbConnection()->getDriverName() === 'sqlite'
            ? "CHECK(typeof(sequence) = 'integer' AND sequence >= 0)"
            : 'CONSTRAINT '.$this->helpers->dbConnection()->getQueryGrammar()->wrap($name.'_sequence_nonnegative').' CHECK(sequence >= 0)';
        $this->helpers->dbConnection()->statement("ALTER TABLE {$wrapped} ADD COLUMN sequence BIGINT NOT NULL DEFAULT 0 {$check}");
    }

    /**
     * The plain (created_at desc) feed index. Added here rather than inline
     * because the column is added last (a fresh-row tail) and naming it off the
     * table keeps a per-panel prefixed table's index distinct.
     */
    private function addActivityCreatedAtIndex(string $table): void
    {
        $this->helpers->schemaConnection()->table($table, static function (Blueprint $blueprint) use ($table): void {
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
        if ($this->helpers->schemaConnection()->getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->helpers->dbConnection()->statement(sprintf('CREATE INDEX %s_context_gin ON %s USING gin (context jsonb_path_ops)', $table, $table));
    }

    /**
     * The partial expiry index: (expires_at) WHERE revoked_at IS NULL — the
     * sweep of live, expiring shares. Driver-aware: a real partial index on
     * Postgres/SQLite, a plain (expires_at) index elsewhere (the WHERE is then
     * applied at query time by the Live scope).
     */
    private function addShareExpiryIndex(string $table): void
    {
        $driver = $this->helpers->schemaConnection()->getConnection()->getDriverName();
        $name = $table.'_expiry_live_idx';

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            $this->helpers->dbConnection()->statement(
                sprintf('CREATE INDEX %s ON %s (expires_at) WHERE revoked_at IS NULL', $name, $table),
            );

            return;
        }

        $this->helpers->schemaConnection()->table($table, static function (Blueprint $blueprint) use ($name): void {
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
        $driver = $this->helpers->schemaConnection()->getConnection()->getDriverName();
        $name = $table.'_trashed_idx';

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            $this->helpers->dbConnection()->statement(
                sprintf('CREATE INDEX %s ON %s (trashed_at) WHERE trashed_at IS NOT NULL', $name, $table),
            );

            return;
        }

        $this->helpers->schemaConnection()->table($table, static function (Blueprint $blueprint) use ($name): void {
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
        $this->helpers->schemaConnection()->table($table, static function (Blueprint $blueprint) use ($table): void {
            $blueprint->index(['status', 'expires_at'], $table.'_sweeper_idx');
        });
    }

    /**
     * One grant per (node_id, subject_type, subject_id, scope) — the
     * grant-unique key.
     */
    private function addGrantUniqueIndex(string $table): void
    {
        $this->helpers->schemaConnection()->table($table, static function (Blueprint $blueprint) use ($table): void {
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
        $this->helpers->schemaConnection()->table($table, static function (Blueprint $blueprint) use ($table): void {
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
        $driver = $this->helpers->schemaConnection()->getConnection()->getDriverName();
        // Derive the index name from the table so a per-panel prefixed table gets
        // its own index name (index names are global on SQLite/PG, not per-table).
        $name = $table.'_sibling_uniq';

        if ($driver === 'pgsql') {
            $this->helpers->dbConnection()->statement(sprintf('CREATE UNIQUE INDEX %s ON %s (parent_id, name_key COLLATE "C") WHERE trashed_at IS NULL', $name, $table));

            return;
        }

        if ($driver === 'sqlite') {
            $this->helpers->dbConnection()->statement(sprintf('CREATE UNIQUE INDEX %s ON %s (parent_id, name_key) WHERE trashed_at IS NULL', $name, $table));

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $this->helpers->dbConnection()->statement(sprintf(
                'ALTER TABLE `%s` ADD COLUMN `name_key_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin '
                .'GENERATED ALWAYS AS (CASE WHEN `trashed_at` IS NULL THEN SHA2(`name_key`, 256) ELSE NULL END) STORED',
                $table,
            ));
            $this->helpers->dbConnection()->statement(sprintf('CREATE UNIQUE INDEX `%s` ON `%s` (`parent_id`, `name_key_hash`)', $name, $table));

            return;
        }

        // Unknown driver: fall back to a plain (non-trash-aware) unique so the schema
        // is still defended. Supported drivers above are the canon.
        $this->helpers->schemaConnection()->table($table, static function (Blueprint $blueprint) use ($name): void {
            $blueprint->unique(['parent_id', 'name_key'], $name);
        });
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
        $driver = $this->helpers->schemaConnection()->getConnection()->getDriverName();
        $name = $table.'_root_uniq';

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            $this->helpers->dbConnection()->statement(
                sprintf('CREATE UNIQUE INDEX %s ON %s (drive_id) WHERE parent_id IS NULL', $name, $table),
            );

            return;
        }

        if ($driver === 'mysql') {
            if (!$this->helpers->schemaConnection()->hasIndex($table, $name)) {
                $this->helpers->dbConnection()->statement(
                    sprintf('CREATE UNIQUE INDEX `%s` ON `%s` ', $name, $table).
                    '(`drive_id`, ((CASE WHEN `parent_id` IS NULL THEN 1 ELSE NULL END)))',
                );
            }

            return;
        }

        if ($driver === 'mariadb') {
            if (!$this->helpers->schemaConnection()->hasColumn($table, 'root_drive')) {
                $this->helpers->dbConnection()->statement(
                    sprintf('ALTER TABLE `%s` ADD COLUMN `root_drive` VARCHAR(64) ', $table).
                    'GENERATED ALWAYS AS (CASE WHEN `parent_id` IS NULL THEN `drive_id` ELSE NULL END) STORED',
                );
            }

            if (!$this->helpers->schemaConnection()->hasIndex($table, $name)) {
                $this->helpers->dbConnection()->statement(sprintf('ALTER TABLE `%s` ADD UNIQUE INDEX `%s` (`root_drive`)', $table, $name));
            }
        }
    }

    /** A signed counter with SQLite type checks preventing REAL overflow promotion. */
    private function scanInteger(Blueprint $table, string $column, int $minimum = 0): ColumnDefinition
    {
        $type = $this->helpers->dbConnection()->getDriverName() === 'sqlite' ? "typeof({$column}) = 'integer' AND " : '';

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

    private function afterCreate(string $entity): void
    {
        if (in_array($entity, ['scan_obligations', 'scan_evidence'], true) && $this->helpers->dbConnection()->getDriverName() === 'mysql') {
            $name = app(DatabaseConfig::class)->table($entity);
            $grammar = $this->helpers->dbConnection()->getQueryGrammar();
            $checks = ['outcome_fields' => "COALESCE((outcome IS NULL AND reason IS NULL AND retryable = false) OR (outcome IN ('clean','infected') AND reason IS NULL AND retryable = false AND blob_id IS NOT NULL) OR (outcome = 'failed' AND reason IS NOT NULL), false) = true"];
            if ($entity === 'scan_obligations') {
                $checks['lease_fields'] = "(state = 'leased' AND lease_token IS NOT NULL AND lease_expires_at IS NOT NULL) OR (state <> 'leased' AND lease_token IS NULL AND lease_expires_at IS NULL)";
                $checks['settled_outcome'] = "state <> 'settled' OR outcome IS NOT NULL";
            }
            foreach ($checks as $suffix => $predicate) {
                if ($this->helpers->dbConnection()->table('information_schema.TABLE_CONSTRAINTS')->whereRaw('TABLE_SCHEMA = DATABASE()')->where('TABLE_NAME', $name)->where('CONSTRAINT_NAME', $name.'_'.$suffix.'_check')->exists()) {
                    continue;
                }
                $this->helpers->dbConnection()->statement('ALTER TABLE '.$grammar->wrapTable($name).' ADD CONSTRAINT '.$grammar->wrap($name.'_'.$suffix.'_check').' CHECK('.$predicate.')');
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
            $this->helpers->addHeadForeignKey(aggregate: 'files', revisions: 'file_revisions', owner: 'file_id');
        }

        if ($entity === 'upload_sessions') {
            $this->addUploadSweeperIndex(table: app(DatabaseConfig::class)->table('upload_sessions'));
        }

        if ($entity === 'activity') {
            $this->addActivityCreatedAtIndex(table: app(DatabaseConfig::class)->table('activity'));
            $this->addActivityContextGinIndex(table: app(DatabaseConfig::class)->table('activity'));
        }
    }
}
