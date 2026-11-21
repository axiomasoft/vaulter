-- Целевая чистая установка. PostgreSQL / uuid7.bigint.v.on / public.
BEGIN;

CREATE TABLE v_document_revisions (
    id uuid NOT NULL,
    tenant_id bigint,
    document_id uuid NOT NULL,
    number integer NOT NULL,
    type character varying(64) NOT NULL,
    collab_epoch bigint NOT NULL DEFAULT '0'::bigint,
    schema_version integer NOT NULL,
    state_version bigint NOT NULL DEFAULT '0'::bigint,
    label character varying(255),
    is_autosave boolean NOT NULL DEFAULT false,
    keep_forever boolean NOT NULL DEFAULT false,
    status character varying(12) NOT NULL DEFAULT 'committed'::character varying,
    restored_from_revision_id uuid,
    created_by bigint,
    created_at timestamp(0) without time zone,
    CONSTRAINT v_document_revisions_collab_epoch_check CHECK ((collab_epoch >= 0)),
    CONSTRAINT v_document_revisions_id_doc_uniq UNIQUE (id, document_id),
    CONSTRAINT v_document_revisions_no_uniq UNIQUE (document_id, number),
    CONSTRAINT v_document_revisions_pkey PRIMARY KEY (id),
    CONSTRAINT v_document_revisions_state_version_check CHECK ((state_version >= 0)),
    CONSTRAINT v_document_revisions_schema_version_positive CHECK (schema_version >= 1)
);

CREATE TABLE v_documents (
    id uuid NOT NULL,
    tenant_id bigint,
    node_id uuid NOT NULL,
    type character varying(64) NOT NULL,
    schema_version integer NOT NULL,
    write_mode varchar(16) NOT NULL DEFAULT 'snapshot' CHECK (write_mode IN ('snapshot', 'journal')),
    current_revision_id uuid,
    revision_count integer NOT NULL DEFAULT 0,
    collab_epoch bigint NOT NULL DEFAULT '0'::bigint,
    state_version bigint NOT NULL DEFAULT '0'::bigint,
    checkpoint_state_version bigint NOT NULL DEFAULT '0'::bigint,
    last_checkpoint_at timestamp(0) without time zone,
    created_by bigint,
    updated_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT v_documents_checkpoint_state_version_check CHECK ((checkpoint_state_version >= 0)),
    CONSTRAINT v_documents_collab_epoch_check CHECK ((collab_epoch >= 0)),
    CONSTRAINT v_documents_node_uniq UNIQUE (node_id),
    CONSTRAINT v_documents_pkey PRIMARY KEY (id),
    CONSTRAINT v_documents_state_version_check CHECK ((state_version >= 0)),
    CONSTRAINT v_documents_schema_version_positive CHECK (schema_version >= 1)
);

CREATE TABLE v_node_comments (
    id uuid NOT NULL,
    tenant_id bigint,
    node_id uuid NOT NULL,
    parent_id uuid,
    author_id bigint NOT NULL,
    author_type character varying(255) NOT NULL,
    body text NOT NULL,
    anchor jsonb,
    resolved_at timestamp(0) without time zone,
    resolved_by bigint,
    version bigint NOT NULL DEFAULT '0'::bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT v_node_comments_pkey PRIMARY KEY (id),
    CONSTRAINT v_node_comments_version_check CHECK ((version >= 0))
);

CREATE TABLE v_document_payloads (
    document_id uuid NOT NULL,
    content jsonb,
    content_hash character(64),
    content_size integer,
    CONSTRAINT v_document_payloads_pkey PRIMARY KEY (document_id),
    CONSTRAINT v_document_payloads_complete CHECK ((content IS NULL AND content_hash IS NULL AND content_size IS NULL) OR
        (content IS NOT NULL AND content_hash IS NOT NULL AND content_size IS NOT NULL AND content_size >= 0))
);

CREATE TABLE v_document_sync_states (
    document_id uuid NOT NULL,
    collab_protocol character varying(255),
    collab_state_format character varying(255),
    collab_state bytea,
    collab_state_size integer,
    pending_protocol character varying(255),
    pending_state_format character varying(255),
    pending_schema_version integer,
    pending_generation bigint,
    pending_through_seq bigint,
    CONSTRAINT v_document_sync_states_pkey PRIMARY KEY (document_id),
    CONSTRAINT v_document_sync_states_protocol CHECK ((collab_protocol IS NULL AND collab_state_format IS NULL AND collab_state IS NULL) OR
        (collab_protocol IS NOT NULL AND collab_protocol ~ '[^[:space:]]' AND collab_state_format IS NOT NULL AND collab_state_format ~ '[^[:space:]]')),
    CONSTRAINT v_document_sync_states_binary_size CHECK ((collab_state IS NULL AND collab_state_size IS NULL) OR
        (collab_state IS NOT NULL AND collab_state_size IS NOT NULL AND collab_state_size = octet_length(collab_state))),
    CONSTRAINT v_document_sync_states_present CHECK (collab_protocol IS NOT NULL OR pending_protocol IS NOT NULL),
    CONSTRAINT v_document_sync_states_pending CHECK ((pending_protocol IS NULL AND pending_state_format IS NULL AND pending_schema_version IS NULL AND pending_generation IS NULL AND pending_through_seq IS NULL) OR
        (pending_protocol IS NOT NULL AND pending_protocol ~ '[^[:space:]]' AND pending_state_format IS NOT NULL AND pending_state_format ~ '[^[:space:]]' AND pending_schema_version IS NOT NULL AND pending_schema_version >= 1 AND pending_generation IS NOT NULL AND pending_generation >= 0 AND pending_through_seq IS NOT NULL AND pending_through_seq >= 1))
);

CREATE TABLE v_document_revision_payloads (
    revision_id uuid NOT NULL,
    content jsonb,
    content_hash character(64),
    content_size integer,
    CONSTRAINT v_document_revision_payloads_pkey PRIMARY KEY (revision_id),
    CONSTRAINT v_document_revision_payloads_complete CHECK ((content IS NULL AND content_hash IS NULL AND content_size IS NULL) OR
        (content IS NOT NULL AND content_hash IS NOT NULL AND content_size IS NOT NULL AND content_size >= 0))
);

CREATE TABLE v_document_revision_sync_states (
    revision_id uuid NOT NULL,
    collab_protocol character varying(255),
    collab_state_format character varying(255),
    collab_state bytea,
    collab_state_size integer,
    CONSTRAINT v_document_revision_sync_states_pkey PRIMARY KEY (revision_id),
    CONSTRAINT v_document_revision_sync_states_protocol CHECK ((collab_protocol IS NULL AND collab_state_format IS NULL AND collab_state IS NULL) OR
        (collab_protocol IS NOT NULL AND collab_protocol ~ '[^[:space:]]' AND collab_state_format IS NOT NULL AND collab_state_format ~ '[^[:space:]]')),
    CONSTRAINT v_document_revision_sync_states_binary_size CHECK ((collab_state IS NULL AND collab_state_size IS NULL) OR
        (collab_state IS NOT NULL AND collab_state_size IS NOT NULL AND collab_state_size = octet_length(collab_state))),
    CONSTRAINT v_document_revision_sync_states_present CHECK (collab_protocol IS NOT NULL)
);

CREATE INDEX v_document_revisions_document_id_index ON v_document_revisions USING btree (document_id);
CREATE INDEX v_document_revisions_history_idx ON v_document_revisions USING btree (document_id, is_autosave, created_at);
CREATE INDEX v_document_revisions_restored_from_revision_id_index ON v_document_revisions USING btree (restored_from_revision_id);
CREATE INDEX v_document_revisions_tenant_id_index ON v_document_revisions USING btree (tenant_id);
CREATE INDEX v_documents_current_revision_id_index ON v_documents USING btree (current_revision_id);
CREATE INDEX v_documents_node_id_index ON v_documents USING btree (node_id);
CREATE INDEX v_documents_tenant_id_index ON v_documents USING btree (tenant_id);
CREATE INDEX v_documents_type_idx ON v_documents USING btree (type);
CREATE INDEX v_node_comments_author_id_index ON v_node_comments USING btree (author_id);
CREATE INDEX v_node_comments_node_created_idx ON v_node_comments USING btree (node_id, created_at);
CREATE INDEX v_node_comments_node_id_index ON v_node_comments USING btree (node_id);
CREATE INDEX v_node_comments_parent_id_index ON v_node_comments USING btree (parent_id);
CREATE INDEX v_node_comments_parent_idx ON v_node_comments USING btree (parent_id);
CREATE INDEX v_node_comments_tenant_id_index ON v_node_comments USING btree (tenant_id);
CREATE INDEX v_document_sync_states_pending_idx ON v_document_sync_states (pending_generation, document_id) WHERE pending_generation IS NOT NULL;
CREATE INDEX v_document_revision_payloads_content_gin ON v_document_revision_payloads USING gin (content jsonb_path_ops);
ALTER TABLE v_document_revisions ADD CONSTRAINT v_document_revisions_document_id_foreign FOREIGN KEY (document_id) REFERENCES v_documents(id) ON DELETE CASCADE;
ALTER TABLE v_document_revisions ADD CONSTRAINT v_document_revisions_restored_fk FOREIGN KEY (restored_from_revision_id) REFERENCES v_document_revisions(id) ON DELETE SET NULL;
ALTER TABLE v_documents ADD CONSTRAINT v_documents_head_fk FOREIGN KEY (current_revision_id, id) REFERENCES v_document_revisions(id, document_id) ON DELETE RESTRICT;
ALTER TABLE v_documents ADD CONSTRAINT v_documents_node_id_foreign FOREIGN KEY (node_id) REFERENCES v_nodes(id) ON DELETE CASCADE;
ALTER TABLE v_node_comments ADD CONSTRAINT v_node_comments_node_id_foreign FOREIGN KEY (node_id) REFERENCES v_nodes(id) ON DELETE CASCADE;
ALTER TABLE v_node_comments ADD CONSTRAINT v_node_comments_parent_id_foreign FOREIGN KEY (parent_id) REFERENCES v_node_comments(id) ON DELETE CASCADE;
ALTER TABLE v_document_payloads ADD CONSTRAINT v_document_payloads_owner_fk FOREIGN KEY (document_id) REFERENCES v_documents(id) ON DELETE CASCADE;
ALTER TABLE v_document_sync_states ADD CONSTRAINT v_document_sync_states_owner_fk FOREIGN KEY (document_id) REFERENCES v_documents(id) ON DELETE CASCADE;
ALTER TABLE v_document_revision_payloads ADD CONSTRAINT v_document_revision_payloads_owner_fk FOREIGN KEY (revision_id) REFERENCES v_document_revisions(id) ON DELETE CASCADE;
ALTER TABLE v_document_revision_sync_states ADD CONSTRAINT v_document_revision_sync_states_owner_fk FOREIGN KEY (revision_id) REFERENCES v_document_revisions(id) ON DELETE CASCADE;
COMMIT;
