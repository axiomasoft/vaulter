-- Целевая чистая установка. PostgreSQL / uuid7.bigint.v.on / public.
BEGIN;

CREATE TABLE v_activity (
    id uuid NOT NULL,
    tenant_id bigint,
    drive_id uuid,
    actor_id bigint,
    actor_type character varying(255),
    verb character varying(255) NOT NULL,
    event_id character varying(26),
    target_id uuid,
    target_type character varying(255),
    context jsonb,
    ip inet,
    user_agent text,
    request_id character varying(64),
    created_at timestamp(0) without time zone,
    CONSTRAINT v_activity_event_id_unique UNIQUE (event_id),
    CONSTRAINT v_activity_pkey PRIMARY KEY (id)
);

CREATE TABLE v_drive_change_heads (
    drive_id uuid NOT NULL,
    tenant_id bigint,
    last_seq bigint NOT NULL DEFAULT '0'::bigint,
    pruned_through bigint NOT NULL DEFAULT '0'::bigint,
    epoch integer NOT NULL DEFAULT 1,
    updated_at timestamp(0) without time zone,
    CONSTRAINT v_drive_change_heads_pkey PRIMARY KEY (drive_id)
);

CREATE TABLE v_drive_changes (
    drive_id uuid NOT NULL,
    seq bigint NOT NULL,
    tenant_id bigint,
    change_id character varying(26) NOT NULL,
    event_type character varying(64) NOT NULL,
    node_id uuid NOT NULL,
    operation character varying(8) NOT NULL,
    reason character varying(16),
    subtree boolean NOT NULL DEFAULT false,
    node_sequence bigint,
    occurred_at timestamp(0) without time zone NOT NULL,
    CONSTRAINT v_drive_changes_change_id_unique UNIQUE (change_id),
    CONSTRAINT v_drive_changes_pkey PRIMARY KEY (drive_id, seq)
);

CREATE TABLE v_drives (
    id uuid NOT NULL,
    profile character varying(64) NOT NULL DEFAULT 'default'::character varying,
    tenant_id bigint,
    owner_id bigint NOT NULL,
    owner_type character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    root_node_id uuid,
    disk character varying(64) NOT NULL DEFAULT 'default'::character varying,
    quota_bytes bigint,
    used_bytes bigint NOT NULL DEFAULT '0'::bigint,
    scan_admission_sequence bigint NOT NULL DEFAULT '0'::bigint,
    status character varying(16) NOT NULL DEFAULT 'active'::character varying,
    version bigint NOT NULL DEFAULT '0'::bigint,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT v_drives_owner_profile_uniq UNIQUE (owner_type, owner_id, profile),
    CONSTRAINT v_drives_pkey PRIMARY KEY (id),
    CONSTRAINT v_drives_scan_admission_sequence_check CHECK (((scan_admission_sequence IS NULL) OR (scan_admission_sequence >= 0))),
    CONSTRAINT v_drives_version_check CHECK ((version >= 0))
);

CREATE TABLE v_file_revision_blobs (
    file_revision_id uuid NOT NULL,
    blob_id uuid NOT NULL,
    role character varying(12) NOT NULL DEFAULT 'data'::character varying,
    ordinal integer NOT NULL DEFAULT 0,
    offset_bytes bigint,
    length_bytes bigint,
    CONSTRAINT v_file_revision_blobs_pkey PRIMARY KEY (file_revision_id, blob_id, role, ordinal)
);

CREATE TABLE v_file_revisions (
    id uuid NOT NULL,
    tenant_id bigint,
    file_id uuid NOT NULL,
    number integer NOT NULL,
    blob_id uuid,
    manifest_blob_id uuid,
    sha256 character(64) NOT NULL,
    size_bytes bigint NOT NULL,
    mime_type character varying(191),
    declared_mime_type character varying(191),
    scan_status character varying(16) NOT NULL DEFAULT 'not_required'::character varying,
    scan_admission_sequence bigint NOT NULL DEFAULT '0'::bigint,
    quarantined_at timestamp(0) without time zone,
    original_name character varying(255) NOT NULL,
    status character varying(12) NOT NULL DEFAULT 'pending'::character varying,
    keep_forever boolean NOT NULL DEFAULT false,
    restored_from_revision_id uuid,
    created_by bigint,
    created_at timestamp(0) without time zone,
    committed_at timestamp(0) without time zone,
    superseded_at timestamp(0) without time zone,
    client_modified_at timestamp(0) without time zone,
    CONSTRAINT v_file_revisions_id_file_uniq UNIQUE (id, file_id),
    CONSTRAINT v_file_revisions_no_uniq UNIQUE (file_id, number),
    CONSTRAINT v_file_revisions_pkey PRIMARY KEY (id),
    CONSTRAINT v_file_revisions_scan_admission_sequence_check CHECK (((scan_admission_sequence IS NULL) OR (scan_admission_sequence >= 0)))
);

CREATE TABLE v_files (
    id uuid NOT NULL,
    tenant_id bigint,
    node_id uuid NOT NULL,
    current_revision_id uuid,
    mime_type character varying(191),
    size_bytes bigint NOT NULL DEFAULT '0'::bigint,
    revision_count integer NOT NULL DEFAULT 0,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT v_files_node_uniq UNIQUE (node_id),
    CONSTRAINT v_files_pkey PRIMARY KEY (id)
);

CREATE TABLE v_node_grants (
    id uuid NOT NULL,
    tenant_id bigint,
    node_id uuid NOT NULL,
    subject_id bigint NOT NULL,
    subject_type character varying(255) NOT NULL,
    role_key character varying(32) NOT NULL,
    role_version smallint NOT NULL DEFAULT '1'::smallint,
    scope character varying(8) NOT NULL DEFAULT 'subtree'::character varying,
    expires_at timestamp(0) without time zone,
    granted_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT v_node_grants_grant_uniq UNIQUE (node_id, subject_type, subject_id, scope),
    CONSTRAINT v_node_grants_pkey PRIMARY KEY (id)
);

CREATE TABLE v_node_shares (
    id uuid NOT NULL,
    tenant_id bigint,
    node_id uuid NOT NULL,
    token_hash character(64) NOT NULL,
    token_prefix character varying(16) NOT NULL,
    role_key character varying(32) NOT NULL,
    role_version smallint NOT NULL DEFAULT '1'::smallint,
    audience character varying(16) NOT NULL DEFAULT 'anyone'::character varying,
    token_generation integer NOT NULL DEFAULT 1,
    version bigint NOT NULL DEFAULT '0'::bigint,
    scope character varying(8) NOT NULL DEFAULT 'item'::character varying,
    password_hash character varying(255),
    expires_at timestamp(0) without time zone,
    max_uses integer,
    use_count integer NOT NULL DEFAULT 0,
    revoked_at timestamp(0) without time zone,
    granted_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT v_node_shares_pkey PRIMARY KEY (id),
    CONSTRAINT v_node_shares_token_hash_uniq UNIQUE (token_hash),
    CONSTRAINT v_node_shares_version_check CHECK ((version >= 0))
);

CREATE TABLE v_node_user_states (
    id uuid NOT NULL,
    tenant_id bigint,
    node_id uuid NOT NULL,
    actor_type character varying(191) NOT NULL,
    actor_id bigint NOT NULL,
    starred_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT v_node_user_states_actor_uniq UNIQUE (node_id, actor_type, actor_id),
    CONSTRAINT v_node_user_states_pkey PRIMARY KEY (id)
);

CREATE TABLE v_nodes (
    id uuid NOT NULL,
    profile character varying(64) NOT NULL DEFAULT 'default'::character varying,
    tenant_id bigint,
    drive_id uuid NOT NULL,
    parent_id uuid,
    kind character varying(16) NOT NULL,
    name character varying(255) NOT NULL,
    name_key character varying(1024) NOT NULL,
    mime_type character varying(191),
    extension character varying(32),
    size_bytes bigint NOT NULL DEFAULT '0'::bigint,
    inherit_grants boolean NOT NULL DEFAULT true,
    access_version bigint NOT NULL DEFAULT '0'::bigint,
    shortcut_target_id uuid,
    properties jsonb,
    content_modified_at timestamp(0) without time zone,
    content_modified_by bigint,
    trashed_at timestamp(0) without time zone,
    trashed_by bigint,
    trashed_from_parent_id uuid,
    trash_root_id uuid,
    created_by bigint,
    updated_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    sequence bigint NOT NULL DEFAULT 0,
    CONSTRAINT v_nodes_access_version_check CHECK ((access_version >= 0)),
    CONSTRAINT v_nodes_pkey PRIMARY KEY (id),
    CONSTRAINT v_nodes_sequence_nonnegative CHECK ((sequence >= 0))
);

CREATE TABLE v_scan_backfill_runs (
    id uuid NOT NULL,
    drive_id uuid NOT NULL,
    tenant_id bigint,
    spec_key character(64) NOT NULL,
    request_key character(36) NOT NULL,
    target_sequence bigint NOT NULL,
    last_admission_sequence bigint NOT NULL DEFAULT '0'::bigint,
    last_revision_id uuid,
    state character varying(16) NOT NULL DEFAULT 'pending'::character varying,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT v_scan_backfill_runs_drive_id_spec_key_unique UNIQUE (drive_id, spec_key),
    CONSTRAINT v_scan_backfill_runs_last_admission_sequence_check CHECK (((last_admission_sequence IS NULL) OR (last_admission_sequence >= 0))),
    CONSTRAINT v_scan_backfill_runs_pkey PRIMARY KEY (id),
    CONSTRAINT v_scan_backfill_runs_state_check CHECK (((state)::text = ANY ((ARRAY['pending'::character varying, 'completed'::character varying])::text[]))),
    CONSTRAINT v_scan_backfill_runs_target_sequence_check CHECK (((target_sequence IS NULL) OR (target_sequence >= 0)))
);

CREATE TABLE v_scan_evidence (
    id uuid NOT NULL,
    drive_id uuid NOT NULL,
    tenant_id bigint,
    spec_key character(64) NOT NULL,
    scanner_key character varying(64) NOT NULL,
    scanner_version character varying(64) NOT NULL,
    policy_version character varying(64) NOT NULL,
    revision_id uuid NOT NULL,
    blob_id uuid,
    scope_key character varying(191) NOT NULL,
    content_identity character(64) NOT NULL,
    sha256 character(64) NOT NULL,
    size_bytes bigint NOT NULL,
    generation bigint NOT NULL,
    attempt bigint NOT NULL,
    reason character varying(32),
    retryable boolean NOT NULL DEFAULT false,
    outcome character varying(16) NOT NULL,
    obligation_id uuid NOT NULL,
    request_id uuid NOT NULL,
    observed_at timestamp(0) without time zone NOT NULL,
    reused_from_id uuid,
    revoked_at timestamp(0) without time zone,
    revocation_reason character varying(191),
    CONSTRAINT v_scan_evidence_attempt_check CHECK (((attempt IS NULL) OR (attempt >= 1))),
    CONSTRAINT v_scan_evidence_check CHECK (COALESCE((((outcome IS NULL) AND (reason IS NULL) AND (retryable = false)) OR (((outcome)::text = ANY ((ARRAY['clean'::character varying, 'infected'::character varying])::text[])) AND (reason IS NULL) AND (retryable = false) AND (blob_id IS NOT NULL)) OR (((outcome)::text = 'failed'::text) AND (reason IS NOT NULL))), false)),
    CONSTRAINT v_scan_evidence_generation_check CHECK (((generation IS NULL) OR (generation >= 1))),
    CONSTRAINT v_scan_evidence_outcome_check CHECK (((outcome)::text = ANY ((ARRAY['clean'::character varying, 'infected'::character varying, 'failed'::character varying])::text[]))),
    CONSTRAINT v_scan_evidence_pkey PRIMARY KEY (id),
    CONSTRAINT v_scan_evidence_request_id_attempt_unique UNIQUE (request_id, attempt),
    CONSTRAINT v_scan_evidence_retryable_check CHECK ((retryable = ANY (ARRAY[false, true]))),
    CONSTRAINT v_scan_evidence_size_bytes_check CHECK (((size_bytes IS NULL) OR (size_bytes >= 0)))
);

CREATE TABLE v_scan_obligations (
    id uuid NOT NULL,
    drive_id uuid NOT NULL,
    tenant_id bigint,
    spec_key character(64) NOT NULL,
    scanner_key character varying(64) NOT NULL,
    scanner_version character varying(64) NOT NULL,
    policy_version character varying(64) NOT NULL,
    revision_id uuid NOT NULL,
    blob_id uuid,
    scope_key character varying(191) NOT NULL,
    content_identity character(64) NOT NULL,
    sha256 character(64) NOT NULL,
    size_bytes bigint NOT NULL,
    generation bigint NOT NULL,
    attempt bigint NOT NULL,
    reason character varying(32),
    retryable boolean NOT NULL DEFAULT false,
    outcome character varying(16),
    request_key character(36) NOT NULL,
    state character varying(16) NOT NULL DEFAULT 'pending'::character varying,
    available_at timestamp(0) without time zone NOT NULL,
    lease_token character(36),
    lease_expires_at timestamp without time zone,
    last_published_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT v_scan_obligations_attempt_check CHECK (((attempt IS NULL) OR (attempt >= 0))),
    CONSTRAINT v_scan_obligations_check CHECK (COALESCE((((outcome IS NULL) AND (reason IS NULL) AND (retryable = false)) OR (((outcome)::text = ANY ((ARRAY['clean'::character varying, 'infected'::character varying])::text[])) AND (reason IS NULL) AND (retryable = false) AND (blob_id IS NOT NULL)) OR (((outcome)::text = 'failed'::text) AND (reason IS NOT NULL))), false)),
    CONSTRAINT v_scan_obligations_check1 CHECK (((((state)::text = 'leased'::text) AND (lease_token IS NOT NULL) AND (lease_expires_at IS NOT NULL)) OR (((state)::text <> 'leased'::text) AND (lease_token IS NULL) AND (lease_expires_at IS NULL)))),
    CONSTRAINT v_scan_obligations_check2 CHECK ((((state)::text <> 'settled'::text) OR (outcome IS NOT NULL))),
    CONSTRAINT v_scan_obligations_generation_check CHECK (((generation IS NULL) OR (generation >= 1))),
    CONSTRAINT v_scan_obligations_outcome_check CHECK (((outcome)::text = ANY ((ARRAY['clean'::character varying, 'infected'::character varying, 'failed'::character varying])::text[]))),
    CONSTRAINT v_scan_obligations_pkey PRIMARY KEY (id),
    CONSTRAINT v_scan_obligations_retryable_check CHECK ((retryable = ANY (ARRAY[false, true]))),
    CONSTRAINT v_scan_obligations_revision_id_spec_key_unique UNIQUE (revision_id, spec_key),
    CONSTRAINT v_scan_obligations_size_bytes_check CHECK (((size_bytes IS NULL) OR (size_bytes >= 0))),
    CONSTRAINT v_scan_obligations_state_check CHECK (((state)::text = ANY ((ARRAY['pending'::character varying, 'leased'::character varying, 'retry_wait'::character varying, 'settled'::character varying])::text[])))
);

CREATE TABLE v_scan_policy_states (
    id uuid NOT NULL,
    drive_id uuid NOT NULL,
    tenant_id bigint,
    spec_key character(64) NOT NULL,
    scanner_key character varying(64) NOT NULL,
    scanner_version character varying(64) NOT NULL,
    policy_version character varying(64) NOT NULL,
    predicate_key character varying(80) NOT NULL,
    mode character varying(16) NOT NULL,
    config_fingerprint character(64) NOT NULL,
    rollout_key character varying(64),
    boundary_sequence bigint,
    activated_at timestamp(0) without time zone NOT NULL,
    updated_at timestamp(0) without time zone NOT NULL,
    CONSTRAINT v_scan_policy_states_boundary_sequence_check CHECK (((boundary_sequence IS NULL) OR (boundary_sequence >= 0))),
    CONSTRAINT v_scan_policy_states_drive_id_predicate_key_unique UNIQUE (drive_id, predicate_key),
    CONSTRAINT v_scan_policy_states_mode_check CHECK (((mode)::text = ANY ((ARRAY['off'::character varying, 'new_content_only'::character varying, 'all_content'::character varying])::text[]))),
    CONSTRAINT v_scan_policy_states_pkey PRIMARY KEY (id)
);

CREATE TABLE v_scan_requests (
    id uuid NOT NULL,
    obligation_id uuid NOT NULL,
    request_key character(36) NOT NULL,
    generation bigint NOT NULL,
    requested_at timestamp(0) without time zone NOT NULL,
    reason character varying(191) NOT NULL,
    correlation_id character varying(191) NOT NULL,
    CONSTRAINT v_scan_requests_generation_check CHECK (((generation IS NULL) OR (generation >= 1))),
    CONSTRAINT v_scan_requests_obligation_id_generation_unique UNIQUE (obligation_id, generation),
    CONSTRAINT v_scan_requests_obligation_id_request_key_unique UNIQUE (obligation_id, request_key),
    CONSTRAINT v_scan_requests_pkey PRIMARY KEY (id)
);

CREATE TABLE v_upload_parts (
    upload_session_id uuid NOT NULL,
    part_no integer NOT NULL,
    byte_offset bigint NOT NULL DEFAULT '0'::bigint,
    size_bytes bigint NOT NULL DEFAULT '0'::bigint,
    part_sha256 character(64),
    etag character varying(255),
    temp_blob_id uuid,
    received_at timestamp(0) without time zone,
    CONSTRAINT v_upload_parts_pkey PRIMARY KEY (upload_session_id, part_no)
);

CREATE TABLE v_upload_sessions (
    id uuid NOT NULL,
    tenant_id bigint,
    drive_id uuid NOT NULL,
    upload_id uuid NOT NULL,
    target_node_id uuid,
    target_parent_id uuid,
    target_name character varying(255),
    result_node_id uuid,
    result_revision_id uuid,
    result_blob_id uuid,
    input_fingerprint character(64),
    cleanup_pending boolean NOT NULL DEFAULT false,
    cleanup_attempts bigint NOT NULL DEFAULT '0'::bigint,
    cleanup_available_at timestamp(0) without time zone,
    cleanup_last_reason character varying(32),
    protocol character varying(16) NOT NULL DEFAULT 'tus'::character varying,
    status character varying(16) NOT NULL DEFAULT 'initiated'::character varying,
    expected_sha256 character(64),
    total_size_bytes bigint,
    received_bytes bigint NOT NULL DEFAULT '0'::bigint,
    chunk_size bigint,
    mime_type character varying(191),
    parts json,
    assembled_blob_id uuid,
    metadata json,
    expires_at timestamp(0) without time zone,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT v_upload_sessions_cleanup_attempts_check CHECK (((cleanup_attempts IS NULL) OR (cleanup_attempts >= 0))),
    CONSTRAINT v_upload_sessions_cleanup_pending_check CHECK ((cleanup_pending = ANY (ARRAY[false, true]))),
    CONSTRAINT v_upload_sessions_pkey PRIMARY KEY (id),
    CONSTRAINT v_upload_sessions_upload_id_uniq UNIQUE (upload_id)
);

CREATE INDEX v_activity_actor_idx ON v_activity USING btree (actor_type, actor_id, created_at);
CREATE INDEX v_activity_context_gin ON v_activity USING gin (context jsonb_path_ops);
CREATE INDEX v_activity_created_idx ON v_activity USING btree (created_at);
CREATE INDEX v_activity_drive_id_index ON v_activity USING btree (drive_id);
CREATE INDEX v_activity_drive_idx ON v_activity USING btree (drive_id, created_at);
CREATE INDEX v_activity_target_id_index ON v_activity USING btree (target_id);
CREATE INDEX v_activity_target_idx ON v_activity USING btree (target_type, target_id, created_at);
CREATE INDEX v_activity_tenant_id_index ON v_activity USING btree (tenant_id);
CREATE INDEX v_activity_verb_idx ON v_activity USING btree (verb, created_at);
CREATE INDEX v_activity_verb_index ON v_activity USING btree (verb);
CREATE INDEX v_drive_change_heads_drive_id_index ON v_drive_change_heads USING btree (drive_id);
CREATE INDEX v_drive_change_heads_tenant_id_index ON v_drive_change_heads USING btree (tenant_id);
CREATE INDEX v_drive_changes_drive_id_index ON v_drive_changes USING btree (drive_id);
CREATE INDEX v_drive_changes_node_id_index ON v_drive_changes USING btree (node_id);
CREATE INDEX v_drive_changes_node_idx ON v_drive_changes USING btree (drive_id, node_id);
CREATE INDEX v_drive_changes_occurred_idx ON v_drive_changes USING btree (drive_id, occurred_at);
CREATE INDEX v_drive_changes_tenant_id_index ON v_drive_changes USING btree (tenant_id);
CREATE INDEX v_drives_owner_id_index ON v_drives USING btree (owner_id);
CREATE INDEX v_drives_owner_type_owner_id_index ON v_drives USING btree (owner_type, owner_id);
CREATE INDEX v_drives_profile_index ON v_drives USING btree (profile);
CREATE INDEX v_drives_profile_status_index ON v_drives USING btree (profile, status);
CREATE INDEX v_drives_root_node_id_index ON v_drives USING btree (root_node_id);
CREATE INDEX v_drives_status_index ON v_drives USING btree (status);
CREATE INDEX v_drives_tenant_id_index ON v_drives USING btree (tenant_id);
CREATE INDEX v_file_revision_blobs_blob_id_index ON v_file_revision_blobs USING btree (blob_id);
CREATE INDEX v_file_revision_blobs_file_revision_id_index ON v_file_revision_blobs USING btree (file_revision_id);
CREATE INDEX v_file_revisions_blob_id_index ON v_file_revisions USING btree (blob_id);
CREATE INDEX v_file_revisions_file_id_index ON v_file_revisions USING btree (file_id);
CREATE INDEX v_file_revisions_file_id_status_index ON v_file_revisions USING btree (file_id, status);
CREATE INDEX v_file_revisions_manifest_blob_id_index ON v_file_revisions USING btree (manifest_blob_id);
CREATE INDEX v_file_revisions_restored_from_revision_id_index ON v_file_revisions USING btree (restored_from_revision_id);
CREATE INDEX v_file_revisions_sha256_index ON v_file_revisions USING btree (sha256);
CREATE INDEX v_file_revisions_status_index ON v_file_revisions USING btree (status);
CREATE INDEX v_file_revisions_tenant_id_index ON v_file_revisions USING btree (tenant_id);
CREATE INDEX v_files_current_revision_id_index ON v_files USING btree (current_revision_id);
CREATE INDEX v_files_node_id_index ON v_files USING btree (node_id);
CREATE INDEX v_files_tenant_id_index ON v_files USING btree (tenant_id);
CREATE INDEX v_node_grants_expires_at_index ON v_node_grants USING btree (expires_at);
CREATE INDEX v_node_grants_node_id_index ON v_node_grants USING btree (node_id);
CREATE INDEX v_node_grants_subject_id_index ON v_node_grants USING btree (subject_id);
CREATE INDEX v_node_grants_subject_idx ON v_node_grants USING btree (subject_type, subject_id);
CREATE INDEX v_node_grants_tenant_id_index ON v_node_grants USING btree (tenant_id);
CREATE INDEX v_node_shares_expiry_live_idx ON v_node_shares USING btree (expires_at) WHERE (revoked_at IS NULL);
CREATE INDEX v_node_shares_node_id_index ON v_node_shares USING btree (node_id);
CREATE INDEX v_node_shares_node_revoked_idx ON v_node_shares USING btree (node_id, revoked_at);
CREATE INDEX v_node_shares_revoked_at_index ON v_node_shares USING btree (revoked_at);
CREATE INDEX v_node_shares_tenant_id_index ON v_node_shares USING btree (tenant_id);
CREATE INDEX v_node_shares_token_prefix_index ON v_node_shares USING btree (token_prefix);
CREATE INDEX v_node_user_states_node_id_index ON v_node_user_states USING btree (node_id);
CREATE INDEX v_node_user_states_starred_idx ON v_node_user_states USING btree (actor_type, actor_id, starred_at);
CREATE INDEX v_node_user_states_tenant_id_index ON v_node_user_states USING btree (tenant_id);
CREATE INDEX v_nodes_drive_id_index ON v_nodes USING btree (drive_id);
CREATE INDEX v_nodes_drive_id_kind_trashed_at_index ON v_nodes USING btree (drive_id, kind, trashed_at);
CREATE INDEX v_nodes_drive_id_parent_id_index ON v_nodes USING btree (drive_id, parent_id);
CREATE INDEX v_nodes_drive_id_trash_root_id_index ON v_nodes USING btree (drive_id, trash_root_id);
CREATE INDEX v_nodes_kind_index ON v_nodes USING btree (kind);
CREATE INDEX v_nodes_mime_type_index ON v_nodes USING btree (mime_type);
CREATE INDEX v_nodes_parent_id_index ON v_nodes USING btree (parent_id);
CREATE INDEX v_nodes_profile_index ON v_nodes USING btree (profile);
CREATE UNIQUE INDEX v_nodes_root_uniq ON v_nodes USING btree (drive_id) WHERE (parent_id IS NULL);
CREATE INDEX v_nodes_shortcut_target_id_index ON v_nodes USING btree (shortcut_target_id);
CREATE UNIQUE INDEX v_nodes_sibling_uniq ON v_nodes USING btree (parent_id, name_key COLLATE "C") WHERE (trashed_at IS NULL);
CREATE INDEX v_nodes_tenant_id_index ON v_nodes USING btree (tenant_id);
CREATE INDEX v_nodes_trash_root_id_index ON v_nodes USING btree (trash_root_id);
CREATE INDEX v_nodes_trashed_from_parent_id_index ON v_nodes USING btree (trashed_from_parent_id);
CREATE INDEX v_nodes_trashed_idx ON v_nodes USING btree (trashed_at) WHERE (trashed_at IS NOT NULL);
CREATE INDEX v_scan_backfill_runs_drive_id_index ON v_scan_backfill_runs USING btree (drive_id);
CREATE INDEX v_scan_backfill_runs_last_revision_id_index ON v_scan_backfill_runs USING btree (last_revision_id);
CREATE INDEX v_scan_backfill_runs_state_id_index ON v_scan_backfill_runs USING btree (state, id);
CREATE INDEX v_scan_backfill_runs_tenant_id_index ON v_scan_backfill_runs USING btree (tenant_id);
CREATE INDEX v_scan_evidence_blob_id_index ON v_scan_evidence USING btree (blob_id);
CREATE INDEX v_scan_evidence_blob_id_outcome_index ON v_scan_evidence USING btree (blob_id, outcome);
CREATE INDEX v_scan_evidence_drive_id_index ON v_scan_evidence USING btree (drive_id);
CREATE INDEX v_scan_evidence_obligation_id_index ON v_scan_evidence USING btree (obligation_id);
CREATE INDEX v_scan_evidence_request_id_index ON v_scan_evidence USING btree (request_id);
CREATE INDEX v_scan_evidence_reused_from_id_index ON v_scan_evidence USING btree (reused_from_id);
CREATE INDEX v_scan_evidence_revision_id_index ON v_scan_evidence USING btree (revision_id);
CREATE INDEX v_scan_evidence_revision_id_spec_key_outcome_index ON v_scan_evidence USING btree (revision_id, spec_key, outcome);
CREATE INDEX v_scan_evidence_tenant_id_index ON v_scan_evidence USING btree (tenant_id);
CREATE INDEX v_scan_obligations_blob_id_index ON v_scan_obligations USING btree (blob_id);
CREATE INDEX v_scan_obligations_drive_id_id_index ON v_scan_obligations USING btree (drive_id, id);
CREATE INDEX v_scan_obligations_drive_id_index ON v_scan_obligations USING btree (drive_id);
CREATE INDEX v_scan_obligations_revision_id_index ON v_scan_obligations USING btree (revision_id);
CREATE INDEX v_scan_obligations_state_available_at_id_index ON v_scan_obligations USING btree (state, available_at, id);
CREATE INDEX v_scan_obligations_state_lease_expires_at_id_index ON v_scan_obligations USING btree (state, lease_expires_at, id);
CREATE INDEX v_scan_obligations_tenant_id_index ON v_scan_obligations USING btree (tenant_id);
CREATE INDEX v_scan_policy_states_drive_id_index ON v_scan_policy_states USING btree (drive_id);
CREATE INDEX v_scan_policy_states_tenant_id_index ON v_scan_policy_states USING btree (tenant_id);
CREATE INDEX v_scan_requests_obligation_id_index ON v_scan_requests USING btree (obligation_id);
CREATE INDEX v_upload_parts_temp_blob_id_index ON v_upload_parts USING btree (temp_blob_id);
CREATE INDEX v_upload_parts_upload_session_id_index ON v_upload_parts USING btree (upload_session_id);
CREATE INDEX v_upload_sessions_assembled_blob_id_index ON v_upload_sessions USING btree (assembled_blob_id);
CREATE INDEX v_upload_sessions_cleanup_idx ON v_upload_sessions USING btree (status, cleanup_pending, cleanup_available_at, id);
CREATE INDEX v_upload_sessions_drive_id_index ON v_upload_sessions USING btree (drive_id);
CREATE INDEX v_upload_sessions_drive_idx ON v_upload_sessions USING btree (drive_id);
CREATE INDEX v_upload_sessions_result_blob_id_index ON v_upload_sessions USING btree (result_blob_id);
CREATE INDEX v_upload_sessions_result_node_id_index ON v_upload_sessions USING btree (result_node_id);
CREATE INDEX v_upload_sessions_result_revision_id_index ON v_upload_sessions USING btree (result_revision_id);
CREATE INDEX v_upload_sessions_sweeper_idx ON v_upload_sessions USING btree (status, expires_at);
CREATE INDEX v_upload_sessions_target_node_id_index ON v_upload_sessions USING btree (target_node_id);
CREATE INDEX v_upload_sessions_target_parent_id_index ON v_upload_sessions USING btree (target_parent_id);
CREATE INDEX v_upload_sessions_tenant_id_index ON v_upload_sessions USING btree (tenant_id);
ALTER TABLE v_drive_change_heads ADD CONSTRAINT v_drive_change_heads_drive_id_foreign FOREIGN KEY (drive_id) REFERENCES v_drives(id) ON DELETE CASCADE;
ALTER TABLE v_drive_changes ADD CONSTRAINT v_drive_changes_drive_id_foreign FOREIGN KEY (drive_id) REFERENCES v_drives(id) ON DELETE CASCADE;
ALTER TABLE v_file_revision_blobs ADD CONSTRAINT v_file_revision_blobs_blob_id_foreign FOREIGN KEY (blob_id) REFERENCES v_blobs(id) ON DELETE CASCADE;
ALTER TABLE v_file_revision_blobs ADD CONSTRAINT v_file_revision_blobs_file_revision_id_foreign FOREIGN KEY (file_revision_id) REFERENCES v_file_revisions(id) ON DELETE CASCADE;
ALTER TABLE v_file_revisions ADD CONSTRAINT v_file_revisions_file_id_foreign FOREIGN KEY (file_id) REFERENCES v_files(id) ON DELETE CASCADE;
ALTER TABLE v_file_revisions ADD CONSTRAINT v_file_revisions_restored_fk FOREIGN KEY (restored_from_revision_id) REFERENCES v_file_revisions(id) ON DELETE SET NULL;
ALTER TABLE v_files ADD CONSTRAINT v_files_head_fk FOREIGN KEY (current_revision_id, id) REFERENCES v_file_revisions(id, file_id) ON DELETE RESTRICT;
ALTER TABLE v_files ADD CONSTRAINT v_files_node_id_foreign FOREIGN KEY (node_id) REFERENCES v_nodes(id) ON DELETE CASCADE;
ALTER TABLE v_node_grants ADD CONSTRAINT v_node_grants_node_id_foreign FOREIGN KEY (node_id) REFERENCES v_nodes(id) ON DELETE CASCADE;
ALTER TABLE v_node_shares ADD CONSTRAINT v_node_shares_node_id_foreign FOREIGN KEY (node_id) REFERENCES v_nodes(id) ON DELETE CASCADE;
ALTER TABLE v_node_user_states ADD CONSTRAINT v_node_user_states_node_id_foreign FOREIGN KEY (node_id) REFERENCES v_nodes(id) ON DELETE CASCADE;
ALTER TABLE v_nodes ADD CONSTRAINT v_nodes_drive_id_foreign FOREIGN KEY (drive_id) REFERENCES v_drives(id) ON DELETE CASCADE;
ALTER TABLE v_nodes ADD CONSTRAINT v_nodes_parent_id_foreign FOREIGN KEY (parent_id) REFERENCES v_nodes(id) ON DELETE CASCADE;
ALTER TABLE v_scan_backfill_runs ADD CONSTRAINT v_scan_backfill_runs_drive_id_foreign FOREIGN KEY (drive_id) REFERENCES v_drives(id) ON DELETE CASCADE;
ALTER TABLE v_scan_evidence ADD CONSTRAINT v_scan_evidence_blob_id_foreign FOREIGN KEY (blob_id) REFERENCES v_blobs(id) ON DELETE RESTRICT;
ALTER TABLE v_scan_evidence ADD CONSTRAINT v_scan_evidence_drive_id_foreign FOREIGN KEY (drive_id) REFERENCES v_drives(id) ON DELETE CASCADE;
ALTER TABLE v_scan_evidence ADD CONSTRAINT v_scan_evidence_obligation_id_foreign FOREIGN KEY (obligation_id) REFERENCES v_scan_obligations(id) ON DELETE CASCADE;
ALTER TABLE v_scan_evidence ADD CONSTRAINT v_scan_evidence_request_id_foreign FOREIGN KEY (request_id) REFERENCES v_scan_requests(id) ON DELETE CASCADE;
ALTER TABLE v_scan_evidence ADD CONSTRAINT v_scan_evidence_reused_from_id_foreign FOREIGN KEY (reused_from_id) REFERENCES v_scan_evidence(id) ON DELETE SET NULL;
ALTER TABLE v_scan_evidence ADD CONSTRAINT v_scan_evidence_revision_id_foreign FOREIGN KEY (revision_id) REFERENCES v_file_revisions(id) ON DELETE CASCADE;
ALTER TABLE v_scan_obligations ADD CONSTRAINT v_scan_obligations_blob_id_foreign FOREIGN KEY (blob_id) REFERENCES v_blobs(id) ON DELETE RESTRICT;
ALTER TABLE v_scan_obligations ADD CONSTRAINT v_scan_obligations_drive_id_foreign FOREIGN KEY (drive_id) REFERENCES v_drives(id) ON DELETE CASCADE;
ALTER TABLE v_scan_obligations ADD CONSTRAINT v_scan_obligations_revision_id_foreign FOREIGN KEY (revision_id) REFERENCES v_file_revisions(id) ON DELETE CASCADE;
ALTER TABLE v_scan_policy_states ADD CONSTRAINT v_scan_policy_states_drive_id_foreign FOREIGN KEY (drive_id) REFERENCES v_drives(id) ON DELETE CASCADE;
ALTER TABLE v_scan_requests ADD CONSTRAINT v_scan_requests_obligation_id_foreign FOREIGN KEY (obligation_id) REFERENCES v_scan_obligations(id) ON DELETE CASCADE;
ALTER TABLE v_upload_parts ADD CONSTRAINT v_upload_parts_upload_session_id_foreign FOREIGN KEY (upload_session_id) REFERENCES v_upload_sessions(id) ON DELETE CASCADE;
ALTER TABLE v_upload_sessions ADD CONSTRAINT v_upload_sessions_drive_id_foreign FOREIGN KEY (drive_id) REFERENCES v_drives(id) ON DELETE CASCADE;
ALTER TABLE v_upload_sessions ADD CONSTRAINT v_upload_sessions_result_blob_id_foreign FOREIGN KEY (result_blob_id) REFERENCES v_blobs(id) ON DELETE RESTRICT;
COMMIT;
