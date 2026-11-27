-- Целевая чистая установка. PostgreSQL / uuid7.bigint.v.on / public.
BEGIN;

CREATE TABLE v_blob_references (
    id uuid NOT NULL,
    blob_id uuid NOT NULL,
    owner_type character varying(64) NOT NULL,
    reference_key character varying(191) NOT NULL,
    created_at timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT v_blob_references_pin_uniq UNIQUE (blob_id, owner_type, reference_key),
    CONSTRAINT v_blob_references_pkey PRIMARY KEY (id)
);

CREATE TABLE v_blobs (
    id uuid NOT NULL,
    tenant_id bigint,
    storage_id uuid,
    hash_algo character varying(16) NOT NULL DEFAULT 'sha256'::character varying,
    sha256 character(64) NOT NULL,
    size_bytes bigint NOT NULL,
    disk character varying(64) NOT NULL DEFAULT 'default'::character varying,
    storage_key character varying(255) COLLATE "C" NOT NULL,
    status character varying(12) NOT NULL DEFAULT 'pending'::character varying,
    ref_count bigint NOT NULL DEFAULT '0'::bigint,
    dedup_scope character varying(8) NOT NULL DEFAULT 'tenant'::character varying,
    quarantined_at timestamp(0) without time zone,
    integrity_checked_at timestamp(0) without time zone,
    integrity_failed_at timestamp(0) without time zone,
    storage_version character varying(255),
    etag character varying(64),
    md5 character varying(64),
    crc32c character varying(64),
    chunked boolean NOT NULL DEFAULT false,
    encryption_key_id uuid,
    first_seen_at timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    committed_at timestamp(0) without time zone,
    delete_after_at timestamp(0) without time zone,
    scope_key character varying(191) COLLATE "C" NOT NULL,
    CONSTRAINT v_blobs_pkey PRIMARY KEY (id),
    CONSTRAINT v_blobs_scope_sha_uniq UNIQUE (scope_key, sha256),
    CONSTRAINT v_blobs_storage_key_uniq UNIQUE (storage_key)
);

CREATE INDEX v_blob_references_blob_id_index ON v_blob_references USING btree (blob_id);
CREATE INDEX v_blob_references_owner_idx ON v_blob_references USING btree (owner_type, reference_key);
CREATE INDEX v_blobs_encryption_key_id_index ON v_blobs USING btree (encryption_key_id);
CREATE INDEX v_blobs_gc_idx ON v_blobs USING btree (status, ref_count, delete_after_at);
CREATE INDEX v_blobs_integrity_idx ON v_blobs USING btree (integrity_checked_at);
CREATE INDEX v_blobs_size_bytes_index ON v_blobs USING btree (size_bytes);
CREATE INDEX v_blobs_storage_id_index ON v_blobs USING btree (storage_id);
CREATE INDEX v_blobs_tenant_id_index ON v_blobs USING btree (tenant_id);
ALTER TABLE v_blob_references ADD CONSTRAINT v_blob_references_blob_fk FOREIGN KEY (blob_id) REFERENCES v_blobs(id) ON DELETE RESTRICT;
COMMIT;
