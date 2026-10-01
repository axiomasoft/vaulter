--
-- PostgreSQL database dump
--

\restrict vaulterfixturev020

-- Dumped from database version 16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: public; Type: SCHEMA; Schema: -; Owner: -
--

-- *not* creating schema, since initdb creates it


SET default_tablespace = '';

SET default_table_access_method = "heap";

--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."migrations" (
    "id" integer NOT NULL,
    "migration" character varying(255) NOT NULL,
    "batch" integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE "public"."migrations_id_seq"
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE "public"."migrations_id_seq" OWNED BY "public"."migrations"."id";


--
-- Name: v_activity; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_activity" (
    "id" character(26) NOT NULL,
    "tenant_id" character(26),
    "storage_id" character(26),
    "actor_id" character(26),
    "actor_type" character varying(255),
    "verb" character varying(255) NOT NULL,
    "target_id" character(26),
    "target_type" character varying(255),
    "context" "jsonb",
    "ip" "inet",
    "user_agent" "text",
    "request_id" "uuid",
    "extra_data" "json",
    "created_at" timestamp(0) without time zone
);


--
-- Name: v_blobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_blobs" (
    "id" character(26) NOT NULL,
    "tenant_id" character(26),
    "storage_id" character(26),
    "hash_algo" character varying(16) DEFAULT 'sha256'::character varying NOT NULL,
    "sha256" character(64) NOT NULL,
    "size_bytes" bigint NOT NULL,
    "disk" character varying(64) DEFAULT 'default'::character varying NOT NULL,
    "storage_key" "text" NOT NULL,
    "status" character varying(12) DEFAULT 'pending'::character varying NOT NULL,
    "ref_count" bigint DEFAULT '0'::bigint NOT NULL,
    "dedup_scope" character varying(8) DEFAULT 'tenant'::character varying NOT NULL,
    "etag" character varying(64),
    "md5" character varying(64),
    "crc32c" character varying(64),
    "chunked" boolean DEFAULT false NOT NULL,
    "encryption_key_id" character(26),
    "first_seen_at" timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "committed_at" timestamp(0) without time zone,
    "delete_after_at" timestamp(0) without time zone,
    "extra_data" "json"
);


--
-- Name: v_file_version_blobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_file_version_blobs" (
    "file_version_id" character(26) NOT NULL,
    "blob_id" character(26) NOT NULL,
    "role" character varying(12) DEFAULT 'data'::character varying NOT NULL,
    "ordinal" integer DEFAULT 0 NOT NULL,
    "offset_bytes" bigint,
    "length_bytes" bigint
);


--
-- Name: v_file_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_file_versions" (
    "id" character(26) NOT NULL,
    "tenant_id" character(26),
    "file_id" character(26) NOT NULL,
    "version_no" integer NOT NULL,
    "blob_id" character(26),
    "manifest_blob_id" character(26),
    "content_sha256" character(64) NOT NULL,
    "size_bytes" bigint NOT NULL,
    "mime_type" character varying(191),
    "original_name" character varying(255) NOT NULL,
    "status" character varying(12) DEFAULT 'pending'::character varying NOT NULL,
    "keep_forever" boolean DEFAULT false NOT NULL,
    "extra_data" "json",
    "created_by" character(26),
    "created_at" timestamp(0) without time zone,
    "committed_at" timestamp(0) without time zone
);


--
-- Name: v_files; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_files" (
    "id" character(26) NOT NULL,
    "tenant_id" character(26),
    "node_id" character(26) NOT NULL,
    "current_version_id" character(26),
    "mime_type" character varying(191),
    "size_bytes" bigint DEFAULT '0'::bigint NOT NULL,
    "version_count" integer DEFAULT 0 NOT NULL,
    "extra_data" "json",
    "created_by" character(26),
    "deleted_at" timestamp(0) without time zone,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


--
-- Name: v_node_comments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_node_comments" (
    "id" character(26) NOT NULL,
    "tenant_id" character(26),
    "node_id" character(26) NOT NULL,
    "parent_id" character(26),
    "author_id" character(26) NOT NULL,
    "author_type" character varying(255) NOT NULL,
    "body" "text" NOT NULL,
    "anchor" "jsonb",
    "resolved_at" timestamp(0) without time zone,
    "resolved_by" character(26),
    "extra_data" "json",
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


--
-- Name: v_node_document_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_node_document_versions" (
    "id" character(26) NOT NULL,
    "tenant_id" character(26),
    "node_id" character(26) NOT NULL,
    "version_no" integer NOT NULL,
    "content" "jsonb",
    "yjs_state" "bytea",
    "yjs_state_size" integer,
    "format" character varying(16) NOT NULL,
    "content_hash" character(64),
    "snapshot_label" character varying(255),
    "is_autosave" boolean DEFAULT false NOT NULL,
    "status" character varying(12) DEFAULT 'committed'::character varying NOT NULL,
    "keep_forever" boolean DEFAULT false NOT NULL,
    "extra_data" "json",
    "created_by" character(26),
    "created_at" timestamp(0) without time zone
);


--
-- Name: v_node_grants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_node_grants" (
    "id" character(26) NOT NULL,
    "tenant_id" character(26),
    "node_id" character(26) NOT NULL,
    "subject_id" character(26) NOT NULL,
    "subject_type" character varying(255) NOT NULL,
    "level" character varying(16) NOT NULL,
    "permissions" integer DEFAULT 0 NOT NULL,
    "effect" character varying(5) DEFAULT 'allow'::character varying NOT NULL,
    "scope" character varying(8) DEFAULT 'subtree'::character varying NOT NULL,
    "inherited" boolean DEFAULT false NOT NULL,
    "inherited_from_node_id" character(26),
    "expires_at" timestamp(0) without time zone,
    "granted_by" character(26),
    "extra_data" "json",
    "deleted_at" timestamp(0) without time zone,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


--
-- Name: v_node_shares; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_node_shares" (
    "id" character(26) NOT NULL,
    "tenant_id" character(26),
    "node_id" character(26) NOT NULL,
    "token_hash" character(64) NOT NULL,
    "token_prefix" character varying(16) NOT NULL,
    "permissions" integer DEFAULT 1 NOT NULL,
    "level" character varying(16) DEFAULT 'reader'::character varying NOT NULL,
    "scope" character varying(8) DEFAULT 'item'::character varying NOT NULL,
    "password_hash" character varying(255),
    "require_login" boolean DEFAULT false NOT NULL,
    "allow_discovery" boolean DEFAULT false NOT NULL,
    "expires_at" timestamp(0) without time zone,
    "max_uses" integer,
    "use_count" integer DEFAULT 0 NOT NULL,
    "revoked_at" timestamp(0) without time zone,
    "granted_by" character(26),
    "extra_data" "json",
    "deleted_at" timestamp(0) without time zone,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


--
-- Name: v_nodes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_nodes" (
    "id" character(26) NOT NULL,
    "panel" character varying(255) DEFAULT 'default'::character varying NOT NULL,
    "tenant_id" character(26),
    "storage_id" character(26) NOT NULL,
    "parent_id" character(26),
    "kind" character varying(16) NOT NULL,
    "name" character varying(255) NOT NULL,
    "mime_type" character varying(191),
    "extension" character varying(32),
    "size_bytes" bigint DEFAULT '0'::bigint NOT NULL,
    "current_version_id" character(26),
    "file_id" character(26),
    "position" integer DEFAULT 0 NOT NULL,
    "is_pinned" boolean DEFAULT false NOT NULL,
    "inherit_grants" boolean DEFAULT true NOT NULL,
    "shortcut_target_id" character(26),
    "owner_id" character(26),
    "extra_data" "json",
    "trashed_at" timestamp(0) without time zone,
    "trashed_by" character(26),
    "trashed_from_parent_id" character(26),
    "created_by" character(26),
    "updated_by" character(26),
    "deleted_at" timestamp(0) without time zone,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


--
-- Name: v_outbox; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_outbox" (
    "id" character(26) NOT NULL,
    "event_id" character varying(255) NOT NULL,
    "panel" character varying(255) DEFAULT 'default'::character varying NOT NULL,
    "topic" character varying(255),
    "event" character varying(255) NOT NULL,
    "payload" "json" NOT NULL,
    "available_at" timestamp(0) without time zone NOT NULL,
    "dispatched_at" timestamp(0) without time zone,
    "attempts" integer DEFAULT 0 NOT NULL,
    "status" character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


--
-- Name: v_storages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_storages" (
    "id" character(26) NOT NULL,
    "panel" character varying(255) DEFAULT 'default'::character varying NOT NULL,
    "tenant_id" character(26),
    "owner_id" character(26) NOT NULL,
    "owner_type" character varying(255) NOT NULL,
    "name" character varying(255) NOT NULL,
    "slug" character varying(160),
    "root_node_id" character(26),
    "disk" character varying(64) DEFAULT 'default'::character varying NOT NULL,
    "storage_type" character varying(32) DEFAULT 'private'::character varying NOT NULL,
    "quota_bytes" bigint,
    "used_bytes" bigint DEFAULT '0'::bigint NOT NULL,
    "status" character varying(16) DEFAULT 'active'::character varying NOT NULL,
    "default_node_permissions" integer DEFAULT 31 NOT NULL,
    "extra_data" "json",
    "created_by" character(26),
    "deleted_at" timestamp(0) without time zone,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


--
-- Name: v_upload_parts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_upload_parts" (
    "upload_session_id" character(26) NOT NULL,
    "part_no" integer NOT NULL,
    "byte_offset" bigint DEFAULT '0'::bigint NOT NULL,
    "size_bytes" bigint DEFAULT '0'::bigint NOT NULL,
    "part_sha256" character(64),
    "etag" character varying(255),
    "temp_blob_id" character(26),
    "received_at" timestamp(0) without time zone
);


--
-- Name: v_upload_sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_upload_sessions" (
    "id" character(26) NOT NULL,
    "tenant_id" character(26),
    "storage_id" character(26) NOT NULL,
    "upload_id" "uuid" NOT NULL,
    "node_id" character(26),
    "target_parent_id" character(26),
    "target_name" character varying(255),
    "protocol" character varying(16) DEFAULT 'tus'::character varying NOT NULL,
    "status" character varying(16) DEFAULT 'initiated'::character varying NOT NULL,
    "expected_sha256" character(64),
    "total_size_bytes" bigint,
    "received_bytes" bigint DEFAULT '0'::bigint NOT NULL,
    "chunk_size" bigint,
    "mime_type" character varying(191),
    "parts" "json",
    "assembled_blob_id" character(26),
    "metadata" "json",
    "extra_data" "json",
    "expires_at" timestamp(0) without time zone,
    "created_by" character(26),
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


--
-- Name: v_webhook_deliveries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_webhook_deliveries" (
    "id" character(26) NOT NULL,
    "endpoint_id" character(26) NOT NULL,
    "event" character varying(255) NOT NULL,
    "payload" "json" NOT NULL,
    "status" character varying(255) NOT NULL,
    "attempts" integer DEFAULT 0 NOT NULL,
    "next_retry_at" timestamp(0) without time zone,
    "last_error" "text",
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


--
-- Name: v_webhook_endpoints; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_webhook_endpoints" (
    "id" character(26) NOT NULL,
    "url" character varying(255) NOT NULL,
    "events" "json" NOT NULL,
    "signing_secret" "text" NOT NULL,
    "is_active" boolean DEFAULT true NOT NULL,
    "last_triggered_at" timestamp(0) without time zone,
    "failure_count" integer DEFAULT 0 NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."migrations" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."migrations_id_seq"'::"regclass");


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."migrations" VALUES
	(1, '2026_06_13_000007_create_v_storages_table', 1),
	(2, '2026_06_13_000008_create_v_nodes_table', 1),
	(3, '2026_06_13_000009_create_v_blobs_table', 1),
	(4, '2026_06_13_000010_create_v_files_table', 1),
	(5, '2026_06_13_000011_create_v_file_versions_table', 1),
	(6, '2026_06_13_000012_create_v_file_version_blobs_table', 1),
	(7, '2026_06_13_000013_create_v_node_grants_table', 1),
	(8, '2026_06_13_000014_create_v_node_shares_table', 1),
	(9, '2026_06_13_000015_create_v_activity_table', 1),
	(10, '2026_06_13_000016_create_v_upload_sessions_table', 1),
	(11, '2026_06_13_000017_create_v_upload_parts_table', 1),
	(12, '2026_06_13_000018_create_v_outbox_table', 1),
	(13, '2026_06_13_000019_create_v_webhook_endpoints_table', 1),
	(14, '2026_06_13_000020_create_v_webhook_deliveries_table', 1),
	(15, '2026_06_13_000021_create_v_node_document_versions_table', 1),
	(16, '2026_06_13_000022_create_v_node_comments_table', 1),
	(17, '2026_06_13_000023_drop_legacy_docvault_tables', 1),
	(18, '2026_07_10_000001_encrypt_webhook_signing_secret', 1),
	(19, '2026_07_11_000001_optimize_hot_path_indexes', 1);


--
-- Data for Name: v_activity; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_activity" VALUES
	('01KDX6KQ000000000000000003', NULL, '01KDX6KQ000000000000000001', NULL, NULL, 'storage.created', '01KDX6KQ000000000000000002', 'Vaulter\Models\Drive\Node', '{"name": "FixtureV020Host storage", "owner_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV", "owner_type": "fixture_host", "storage_id": "01KDX6KQ000000000000000001"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:00:00'),
	('01KDX6KQ000000000000000006', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.created', '01KDX6KQ000000000000000005', 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Projects", "parent_id": "01KDX6KQ000000000000000002"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:01:00'),
	('01KDX6KQ000000000000000009', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.created', '01KDX6KQ000000000000000008', 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Reports", "parent_id": "01KDX6KQ000000000000000005"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:02:00'),
	('01KDX6KQ00000000000000000C', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.created', '01KDX6KQ00000000000000000B', 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Archive", "parent_id": "01KDX6KQ000000000000000002"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:03:00'),
	('01KDX6KQ00000000000000000F', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.created', '01KDX6KQ00000000000000000E', 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "2025", "parent_id": "01KDX6KQ00000000000000000B"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:04:00'),
	('01KDX6KQ00000000000000000N', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'file.uploaded', '01KDX6KQ00000000000000000J', 'Vaulter\Models\Drive\Node', '{"name": "brief.txt", "mime_type": "text/plain", "size_bytes": 19, "version_id": "01KDX6KQ00000000000000000M"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:05:00'),
	('01KDX6KQ00000000000000000V', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'file.uploaded', '01KDX6KQ00000000000000000R', 'Vaulter\Models\Drive\Node', '{"name": "q1.csv", "mime_type": "text/csv", "size_bytes": 16, "version_id": "01KDX6KQ00000000000000000T"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:06:00'),
	('01KDX6KQ00000000000000000Z', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'version.created', '01KDX6KQ00000000000000000R', 'Vaulter\Models\Drive\Node', '{"size_bytes": 16, "version_id": "01KDX6KQ00000000000000000Y", "version_no": 2}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:07:00'),
	('01KDX6KQ000000000000000013', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'version.created', '01KDX6KQ00000000000000000R', 'Vaulter\Models\Drive\Node', '{"size_bytes": 16, "version_id": "01KDX6KQ000000000000000012", "version_no": 3}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:08:00'),
	('01KDX6KQ000000000000000019', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'file.uploaded', '01KDX6KQ000000000000000016', 'Vaulter\Models\Drive\Node', '{"name": "old.txt", "mime_type": "text/plain", "size_bytes": 17, "version_id": "01KDX6KQ000000000000000018"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:09:00'),
	('01KDX6KQ00000000000000001C', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.created', '01KDX6KQ00000000000000001B', 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Temp", "parent_id": "01KDX6KQ000000000000000002"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:10:00'),
	('01KDX6KQ00000000000000001J', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'file.uploaded', '01KDX6KQ00000000000000001F', 'Vaulter\Models\Drive\Node', '{"name": "note.txt", "mime_type": "text/plain", "size_bytes": 21, "version_id": "01KDX6KQ00000000000000001H"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:11:00'),
	('01KDX6KQ00000000000000001M', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.renamed', '01KDX6KQ000000000000000008', 'Vaulter\Models\Drive\Node', '{"to": "Reports 2025", "from": "Reports"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:12:00'),
	('01KDX6KQ00000000000000001P', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.moved', '01KDX6KQ00000000000000000E', 'Vaulter\Models\Drive\Node', '{"to_parent_id": "01KDX6KQ000000000000000005", "from_parent_id": "01KDX6KQ00000000000000000B"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:13:00'),
	('01KDX6KQ00000000000000001R', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.trashed', '01KDX6KQ000000000000000016', 'Vaulter\Models\Drive\Node', '{"name": "old.txt"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:14:00'),
	('01KDX6KQ00000000000000001T', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.restored', '01KDX6KQ000000000000000016', 'Vaulter\Models\Drive\Node', '{"name": "old.txt", "parent_id": "01KDX6KQ00000000000000000B"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:15:00'),
	('01KDX6KQ00000000000000001W', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.trashed', '01KDX6KQ000000000000000016', 'Vaulter\Models\Drive\Node', '{"name": "old.txt"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:16:00'),
	('01KDX6KQ00000000000000001X', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.trashed', '01KDX6KQ00000000000000001B', 'Vaulter\Models\Drive\Node', '{"name": "Temp"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:17:00'),
	('01KDX6KQ000000000000000021', NULL, '01KDX6KQ00000000000000001Z', NULL, NULL, 'storage.created', '01KDX6KQ000000000000000020', 'Vaulter\Models\Drive\Node', '{"name": "FixtureV020Host storage", "owner_id": "01ARZ3NDEKTSV4RRFFQ69G5FAW", "owner_type": "fixture_host", "storage_id": "01KDX6KQ00000000000000001Z"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:17:00'),
	('01KDX6KQ000000000000000024', NULL, '01KDX6KQ00000000000000001Z', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.created', '01KDX6KQ000000000000000023', 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Projects", "parent_id": "01KDX6KQ000000000000000020"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:18:00'),
	('01KDX6KQ000000000000000027', NULL, '01KDX6KQ00000000000000001Z', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.created', '01KDX6KQ000000000000000026', 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Reports", "parent_id": "01KDX6KQ000000000000000023"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:19:00'),
	('01KDX6KQ00000000000000002A', NULL, '01KDX6KQ00000000000000001Z', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.created', '01KDX6KQ000000000000000029', 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Archive", "parent_id": "01KDX6KQ000000000000000020"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:20:00'),
	('01KDX6KQ00000000000000002D', NULL, '01KDX6KQ00000000000000001Z', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.created', '01KDX6KQ00000000000000002C', 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "2025", "parent_id": "01KDX6KQ000000000000000029"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:21:00'),
	('01KDX6KQ00000000000000002K', NULL, '01KDX6KQ00000000000000001Z', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'file.uploaded', '01KDX6KQ00000000000000002G', 'Vaulter\Models\Drive\Node', '{"name": "brief.txt", "mime_type": "text/plain", "size_bytes": 19, "version_id": "01KDX6KQ00000000000000002J"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:22:00'),
	('01KDX6KQ00000000000000002S', NULL, '01KDX6KQ00000000000000001Z', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'file.uploaded', '01KDX6KQ00000000000000002P', 'Vaulter\Models\Drive\Node', '{"name": "q1.csv", "mime_type": "text/csv", "size_bytes": 16, "version_id": "01KDX6KQ00000000000000002R"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:23:00'),
	('01KDX6KQ00000000000000002X', NULL, '01KDX6KQ00000000000000001Z', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'version.created', '01KDX6KQ00000000000000002P', 'Vaulter\Models\Drive\Node', '{"size_bytes": 16, "version_id": "01KDX6KQ00000000000000002W", "version_no": 2}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:24:00'),
	('01KDX6KQ000000000000000031', NULL, '01KDX6KQ00000000000000001Z', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'version.created', '01KDX6KQ00000000000000002P', 'Vaulter\Models\Drive\Node', '{"size_bytes": 16, "version_id": "01KDX6KQ000000000000000030", "version_no": 3}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:25:00'),
	('01KDX6KQ000000000000000037', NULL, '01KDX6KQ00000000000000001Z', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'file.uploaded', '01KDX6KQ000000000000000034', 'Vaulter\Models\Drive\Node', '{"name": "old.txt", "mime_type": "text/plain", "size_bytes": 17, "version_id": "01KDX6KQ000000000000000036"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:26:00'),
	('01KDX6KQ00000000000000003A', NULL, '01KDX6KQ00000000000000001Z', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.created', '01KDX6KQ000000000000000039', 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Temp", "parent_id": "01KDX6KQ000000000000000020"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:27:00'),
	('01KDX6KQ00000000000000003G', NULL, '01KDX6KQ00000000000000001Z', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'file.uploaded', '01KDX6KQ00000000000000003D', 'Vaulter\Models\Drive\Node', '{"name": "note.txt", "mime_type": "text/plain", "size_bytes": 21, "version_id": "01KDX6KQ00000000000000003F"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:28:00'),
	('01KDX6KQ00000000000000003J', NULL, '01KDX6KQ00000000000000001Z', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.renamed', '01KDX6KQ000000000000000026', 'Vaulter\Models\Drive\Node', '{"to": "Reports 2025", "from": "Reports"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:29:00'),
	('01KDX6KQ00000000000000003M', NULL, '01KDX6KQ00000000000000001Z', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.moved', '01KDX6KQ00000000000000002C', 'Vaulter\Models\Drive\Node', '{"to_parent_id": "01KDX6KQ000000000000000023", "from_parent_id": "01KDX6KQ000000000000000029"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:30:00'),
	('01KDX6KQ00000000000000003P', NULL, '01KDX6KQ00000000000000001Z', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.trashed', '01KDX6KQ000000000000000034', 'Vaulter\Models\Drive\Node', '{"name": "old.txt"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:31:00'),
	('01KDX6KQ00000000000000003R', NULL, '01KDX6KQ00000000000000001Z', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.restored', '01KDX6KQ000000000000000034', 'Vaulter\Models\Drive\Node', '{"name": "old.txt", "parent_id": "01KDX6KQ000000000000000029"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:32:00'),
	('01KDX6KQ00000000000000003T', NULL, '01KDX6KQ00000000000000001Z', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.trashed', '01KDX6KQ000000000000000034', 'Vaulter\Models\Drive\Node', '{"name": "old.txt"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:33:00'),
	('01KDX6KQ00000000000000003V', NULL, '01KDX6KQ00000000000000001Z', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.trashed', '01KDX6KQ000000000000000039', 'Vaulter\Models\Drive\Node', '{"name": "Temp"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:34:00'),
	('01KDX6KQ00000000000000003Z', NULL, '01KDX6KQ00000000000000003X', NULL, NULL, 'storage.created', '01KDX6KQ00000000000000003Y', 'Vaulter\Models\Drive\Node', '{"name": "FixtureV020Host storage", "owner_id": "01ARZ3NDEKTSV4RRFFQ69G5FAX", "owner_type": "fixture_host", "storage_id": "01KDX6KQ00000000000000003X"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:34:00'),
	('01KDX6KQ000000000000000042', NULL, '01KDX6KQ00000000000000003X', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.created', '01KDX6KQ000000000000000041', 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Projects", "parent_id": "01KDX6KQ00000000000000003Y"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:35:00'),
	('01KDX6KQ000000000000000045', NULL, '01KDX6KQ00000000000000003X', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.created', '01KDX6KQ000000000000000044', 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Reports", "parent_id": "01KDX6KQ000000000000000041"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:36:00'),
	('01KDX6KQ000000000000000048', NULL, '01KDX6KQ00000000000000003X', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.created', '01KDX6KQ000000000000000047', 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Archive", "parent_id": "01KDX6KQ00000000000000003Y"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:37:00'),
	('01KDX6KQ00000000000000004B', NULL, '01KDX6KQ00000000000000003X', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.created', '01KDX6KQ00000000000000004A', 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "2025", "parent_id": "01KDX6KQ000000000000000047"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:38:00'),
	('01KDX6KQ00000000000000004H', NULL, '01KDX6KQ00000000000000003X', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'file.uploaded', '01KDX6KQ00000000000000004E', 'Vaulter\Models\Drive\Node', '{"name": "brief.txt", "mime_type": "text/plain", "size_bytes": 19, "version_id": "01KDX6KQ00000000000000004G"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:39:00'),
	('01KDX6KQ00000000000000004Q', NULL, '01KDX6KQ00000000000000003X', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'file.uploaded', '01KDX6KQ00000000000000004M', 'Vaulter\Models\Drive\Node', '{"name": "q1.csv", "mime_type": "text/csv", "size_bytes": 16, "version_id": "01KDX6KQ00000000000000004P"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:40:00'),
	('01KDX6KQ00000000000000004V', NULL, '01KDX6KQ00000000000000003X', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'version.created', '01KDX6KQ00000000000000004M', 'Vaulter\Models\Drive\Node', '{"size_bytes": 16, "version_id": "01KDX6KQ00000000000000004T", "version_no": 2}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:41:00'),
	('01KDX6KQ00000000000000004Z', NULL, '01KDX6KQ00000000000000003X', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'version.created', '01KDX6KQ00000000000000004M', 'Vaulter\Models\Drive\Node', '{"size_bytes": 16, "version_id": "01KDX6KQ00000000000000004Y", "version_no": 3}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:42:00'),
	('01KDX6KQ000000000000000055', NULL, '01KDX6KQ00000000000000003X', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'file.uploaded', '01KDX6KQ000000000000000052', 'Vaulter\Models\Drive\Node', '{"name": "old.txt", "mime_type": "text/plain", "size_bytes": 17, "version_id": "01KDX6KQ000000000000000054"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:43:00'),
	('01KDX6KQ000000000000000058', NULL, '01KDX6KQ00000000000000003X', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.created', '01KDX6KQ000000000000000057', 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Temp", "parent_id": "01KDX6KQ00000000000000003Y"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:44:00'),
	('01KDX6KQ00000000000000005E', NULL, '01KDX6KQ00000000000000003X', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'file.uploaded', '01KDX6KQ00000000000000005B', 'Vaulter\Models\Drive\Node', '{"name": "note.txt", "mime_type": "text/plain", "size_bytes": 21, "version_id": "01KDX6KQ00000000000000005D"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:45:00'),
	('01KDX6KQ00000000000000005G', NULL, '01KDX6KQ00000000000000003X', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.renamed', '01KDX6KQ000000000000000044', 'Vaulter\Models\Drive\Node', '{"to": "Reports 2025", "from": "Reports"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:46:00'),
	('01KDX6KQ00000000000000005J', NULL, '01KDX6KQ00000000000000003X', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.moved', '01KDX6KQ00000000000000004A', 'Vaulter\Models\Drive\Node', '{"to_parent_id": "01KDX6KQ000000000000000041", "from_parent_id": "01KDX6KQ000000000000000047"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:47:00'),
	('01KDX6KQ00000000000000005M', NULL, '01KDX6KQ00000000000000003X', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.trashed', '01KDX6KQ000000000000000052', 'Vaulter\Models\Drive\Node', '{"name": "old.txt"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:48:00'),
	('01KDX6KQ00000000000000005P', NULL, '01KDX6KQ00000000000000003X', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.restored', '01KDX6KQ000000000000000052', 'Vaulter\Models\Drive\Node', '{"name": "old.txt", "parent_id": "01KDX6KQ000000000000000047"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:49:00'),
	('01KDX6KQ00000000000000005R', NULL, '01KDX6KQ00000000000000003X', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.trashed', '01KDX6KQ000000000000000052', 'Vaulter\Models\Drive\Node', '{"name": "old.txt"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:50:00'),
	('01KDX6KQ00000000000000005S', NULL, '01KDX6KQ00000000000000003X', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'node.trashed', '01KDX6KQ000000000000000057', 'Vaulter\Models\Drive\Node', '{"name": "Temp"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:51:00'),
	('01KDX6KQ00000000000000005Z', NULL, '01KDX6KQ00000000000000001Z', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'file.uploaded', '01KDX6KQ00000000000000005W', 'Vaulter\Models\Drive\Node', '{"name": "shared.bin", "mime_type": "application/octet-stream", "size_bytes": 29, "version_id": "01KDX6KQ00000000000000005Y"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:52:00'),
	('01KDX6KQ000000000000000064', NULL, '01KDX6KQ00000000000000003X', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'file.uploaded', '01KDX6KQ000000000000000061', 'Vaulter\Models\Drive\Node', '{"name": "shared.bin", "mime_type": "application/octet-stream", "size_bytes": 29, "version_id": "01KDX6KQ000000000000000063"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:53:00'),
	('01KDX6KQ00000000000000006A', NULL, '01KDX6KQ00000000000000003X', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'file.uploaded', '01KDX6KQ000000000000000067', 'Vaulter\Models\Drive\Node', '{"name": "tenant.bin", "mime_type": "application/octet-stream", "size_bytes": 33, "version_id": "01KDX6KQ000000000000000069"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:54:00'),
	('01KDX6KQ00000000000000006W', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'grant.created', '01KDX6KQ000000000000000005', 'Vaulter\Models\Drive\Node', '{"level": "writer", "scope": "subtree", "effect": "allow", "grant_id": "01KDX6KQ00000000000000006V", "subject_id": "01ARZ3NDEKTSV4RRFFQ69G5FB1", "subject_type": "user"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:04:00'),
	('01KDX6KQ00000000000000006Z', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'grant.created', '01KDX6KQ000000000000000005', 'Vaulter\Models\Drive\Node', '{"level": "writer", "scope": "subtree", "effect": "allow", "grant_id": "01KDX6KQ00000000000000006Y", "subject_id": "01ARZ3NDEKTSV4RRFFQ69G5FB3", "subject_type": "role"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:05:00'),
	('01KDX6KQ000000000000000072', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'grant.created', '01KDX6KQ000000000000000005', 'Vaulter\Models\Drive\Node', '{"level": "writer", "scope": "subtree", "effect": "allow", "grant_id": "01KDX6KQ000000000000000071", "subject_id": "01ARZ3NDEKTSV4RRFFQ69G5FB2", "subject_type": "user"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:06:00'),
	('01KDX6KQ000000000000000075', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'grant.created', '01KDX6KQ000000000000000005', 'Vaulter\Models\Drive\Node', '{"level": "writer", "scope": "subtree", "effect": "allow", "grant_id": "01KDX6KQ000000000000000074", "subject_id": "01ARZ3NDEKTSV4RRFFQ69G5FB4", "subject_type": "role"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:07:00'),
	('01KDX6KQ00000000000000007B', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'grant.revoked', '01KDX6KQ000000000000000005', 'Vaulter\Models\Drive\Node', '{"revoked": 1, "subject_id": "01ARZ3NDEKTSV4RRFFQ69G5FB2", "subject_type": "user"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:09:00'),
	('01KDX6KQ00000000000000007D', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'grant.revoked', '01KDX6KQ000000000000000005', 'Vaulter\Models\Drive\Node', '{"revoked": 1, "subject_id": "01ARZ3NDEKTSV4RRFFQ69G5FB4", "subject_type": "role"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:10:00'),
	('01KDX6KQ00000000000000007G', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'share.created', '01KDX6KQ00000000000000000J', 'Vaulter\Models\Drive\Node', '{"level": "reader", "scope": "item", "share_id": "01KDX6KQ00000000000000007F", "permissions": 1, "token_prefix": "fx000003"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:11:00'),
	('01KDX6KQ00000000000000007K', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'share.created', '01KDX6KQ00000000000000000J', 'Vaulter\Models\Drive\Node', '{"level": "reader", "scope": "item", "share_id": "01KDX6KQ00000000000000007J", "permissions": 1, "token_prefix": "fx000004"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:12:00'),
	('01KDX6KQ00000000000000007P', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'share.created', '01KDX6KQ00000000000000000J', 'Vaulter\Models\Drive\Node', '{"level": "reader", "scope": "item", "share_id": "01KDX6KQ00000000000000007N", "permissions": 1, "token_prefix": "fx000005"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:13:00'),
	('01KDX6KQ00000000000000007R', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'share.revoked', '01KDX6KQ00000000000000000J', 'Vaulter\Models\Drive\Node', '{"share_id": "01KDX6KQ00000000000000007J", "token_prefix": "fx000004"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:14:00'),
	('01KDX6KQ00000000000000007V', NULL, '01KDX6KQ000000000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, 'upload_session.initiated', NULL, NULL, '{"protocol": "tus", "upload_id": "00000000-0000-4000-8000-000000000001", "target_name": "large.bin"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:16:00');


--
-- Data for Name: v_blobs; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_blobs" VALUES
	('01KDX6KQ00000000000000000Q', NULL, NULL, 'sha256', 'f400d6b970764284311640397f4c438064aca96028ebdebaa8d840c2ceba0c55', 16, 'local', 'snull/f4/00/f400d6b970764284311640397f4c438064aca96028ebdebaa8d840c2ceba0c55', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:06:00', '2026-01-01 00:06:00', NULL, NULL),
	('01KDX6KQ00000000000000000X', NULL, NULL, 'sha256', '740312e7cc4336ce5e74fee427747611908b8183a7288694ed173a551888eec7', 16, 'local', 'snull/74/03/740312e7cc4336ce5e74fee427747611908b8183a7288694ed173a551888eec7', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:07:00', '2026-01-01 00:07:00', NULL, NULL),
	('01KDX6KQ000000000000000011', NULL, NULL, 'sha256', 'c4d0686d6e7feb53f3bd7d33abe43208ff2b11b8da00541000253699b2956370', 16, 'local', 'snull/c4/d0/c4d0686d6e7feb53f3bd7d33abe43208ff2b11b8da00541000253699b2956370', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:08:00', '2026-01-01 00:08:00', NULL, NULL),
	('01KDX6KQ000000000000000015', NULL, NULL, 'sha256', '5213ee530d7fedcdbf09e3c35032d25e9615a1504cb9d16638e21a1b52ca7c4d', 17, 'local', 'snull/52/13/5213ee530d7fedcdbf09e3c35032d25e9615a1504cb9d16638e21a1b52ca7c4d', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:09:00', '2026-01-01 00:09:00', NULL, NULL),
	('01KDX6KQ00000000000000001E', NULL, NULL, 'sha256', '77ee30304b5e58719e60c82f3266df23490ceb995de1bec53ce869ce93fa2c68', 21, 'local', 'snull/77/ee/77ee30304b5e58719e60c82f3266df23490ceb995de1bec53ce869ce93fa2c68', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:11:00', '2026-01-01 00:11:00', NULL, NULL),
	('01KDX6KQ00000000000000002F', NULL, NULL, 'sha256', '54eb55f3b784afa49b52cb67c214482344b37b9468fbb0f030289cc26790a10e', 19, 'local', 'snull/54/eb/54eb55f3b784afa49b52cb67c214482344b37b9468fbb0f030289cc26790a10e', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:22:00', '2026-01-01 00:22:00', NULL, NULL),
	('01KDX6KQ00000000000000002N', NULL, NULL, 'sha256', '01710ad00516dc63c0a3a0a40513d27e28775b2b8634b678660c276e9d7cbeb6', 16, 'local', 'snull/01/71/01710ad00516dc63c0a3a0a40513d27e28775b2b8634b678660c276e9d7cbeb6', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:23:00', '2026-01-01 00:23:00', NULL, NULL),
	('01KDX6KQ00000000000000002V', NULL, NULL, 'sha256', '05a984b99bf5bc2c1d150a8594f3534ebf8e15ec0b1d5c75efea9b4872b4b0fa', 16, 'local', 'snull/05/a9/05a984b99bf5bc2c1d150a8594f3534ebf8e15ec0b1d5c75efea9b4872b4b0fa', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:24:00', '2026-01-01 00:24:00', NULL, NULL),
	('01KDX6KQ00000000000000002Z', NULL, NULL, 'sha256', '2dbb81cf80e92982dc3776653d46a2940f349b3bfd8c72f2a6e62a901c6b6567', 16, 'local', 'snull/2d/bb/2dbb81cf80e92982dc3776653d46a2940f349b3bfd8c72f2a6e62a901c6b6567', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:25:00', '2026-01-01 00:25:00', NULL, NULL),
	('01KDX6KQ000000000000000033', NULL, NULL, 'sha256', '862d2f069920fd45f2cbc79c1f80f91a296b1badbb16c6f8cdc45ba3d0ff67a6', 17, 'local', 'snull/86/2d/862d2f069920fd45f2cbc79c1f80f91a296b1badbb16c6f8cdc45ba3d0ff67a6', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:26:00', '2026-01-01 00:26:00', NULL, NULL),
	('01KDX6KQ00000000000000003C', NULL, NULL, 'sha256', '5f002e563dde8f8e90e25c2323a54c049842847ff16a081c7e796b27694dad02', 21, 'local', 'snull/5f/00/5f002e563dde8f8e90e25c2323a54c049842847ff16a081c7e796b27694dad02', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:28:00', '2026-01-01 00:28:00', NULL, NULL),
	('01KDX6KQ00000000000000004D', NULL, NULL, 'sha256', 'a351bd4c010c5fc3d5bd1d608946dd733c5a75ca8c0b70045d013ff401d85891', 19, 'local', 'snull/a3/51/a351bd4c010c5fc3d5bd1d608946dd733c5a75ca8c0b70045d013ff401d85891', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:39:00', '2026-01-01 00:39:00', NULL, NULL),
	('01KDX6KQ00000000000000004K', NULL, NULL, 'sha256', '24226fe8df109276008435a9835f77db119559ba7ac97406743a4283d785361d', 16, 'local', 'snull/24/22/24226fe8df109276008435a9835f77db119559ba7ac97406743a4283d785361d', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:40:00', '2026-01-01 00:40:00', NULL, NULL),
	('01KDX6KQ00000000000000004S', NULL, NULL, 'sha256', 'fe52cce6d9ff851348ac4a56c0aeaf7c56ec01995f4aee50dd1fbe4049c230ec', 16, 'local', 'snull/fe/52/fe52cce6d9ff851348ac4a56c0aeaf7c56ec01995f4aee50dd1fbe4049c230ec', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:41:00', '2026-01-01 00:41:00', NULL, NULL),
	('01KDX6KQ00000000000000004X', NULL, NULL, 'sha256', '7787ed77e8eb0975aa39afef85ddf6b1e1aaa91446cd7779e40ee2a651e47df8', 16, 'local', 'snull/77/87/7787ed77e8eb0975aa39afef85ddf6b1e1aaa91446cd7779e40ee2a651e47df8', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:42:00', '2026-01-01 00:42:00', NULL, NULL),
	('01KDX6KQ000000000000000051', NULL, NULL, 'sha256', 'db2ecfae131b0fdd5fad941bd35c249e7a9523559737765d3e73cd91183dd9d5', 17, 'local', 'snull/db/2e/db2ecfae131b0fdd5fad941bd35c249e7a9523559737765d3e73cd91183dd9d5', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:43:00', '2026-01-01 00:43:00', NULL, NULL),
	('01KDX6KQ00000000000000005A', NULL, NULL, 'sha256', '6411a3531c9f8239119d6f341cdbd87b57a8a6b2316825454cadb2e80c6603a8', 21, 'local', 'snull/64/11/6411a3531c9f8239119d6f341cdbd87b57a8a6b2316825454cadb2e80c6603a8', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:45:00', '2026-01-01 00:45:00', NULL, NULL),
	('01KDX6KQ00000000000000000H', NULL, NULL, 'sha256', '17fd007b1eae89012f85996449b99ab04057b97183729e08ac1c2fcb7bb813e5', 19, 'local', 'snull/17/fd/17fd007b1eae89012f85996449b99ab04057b97183729e08ac1c2fcb7bb813e5', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:05:00', '2026-01-01 00:05:00', NULL, NULL),
	('01KDX6KQ00000000000000005V', NULL, NULL, 'sha256', '94364e27e6e12dbfc6ccf67281bd396596ffb68d5e76723e4f2f2c90eb9c1d8d', 29, 'local', 'tnull/94/36/94364e27e6e12dbfc6ccf67281bd396596ffb68d5e76723e4f2f2c90eb9c1d8d', 'committed', 0, 'tenant', NULL, NULL, NULL, false, NULL, '2026-01-01 00:52:00', '2026-01-01 00:52:00', NULL, NULL),
	('01KDX6KQ000000000000000066', NULL, NULL, 'sha256', '42416976a60a7a8a85a0efb8d41e755a44df6dc739160b61fbe5b16cf8b0755a', 33, 'local', 'tnull/42/41/42416976a60a7a8a85a0efb8d41e755a44df6dc739160b61fbe5b16cf8b0755a', 'committed', 0, 'tenant', NULL, NULL, NULL, false, NULL, '2026-01-01 00:54:00', '2026-01-01 00:54:00', NULL, NULL);


--
-- Data for Name: v_file_version_blobs; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_file_version_blobs" VALUES
	('01KDX6KQ00000000000000000M', '01KDX6KQ00000000000000000H', 'data', 0, NULL, NULL),
	('01KDX6KQ00000000000000000T', '01KDX6KQ00000000000000000Q', 'data', 0, NULL, NULL),
	('01KDX6KQ00000000000000000Y', '01KDX6KQ00000000000000000X', 'data', 0, NULL, NULL),
	('01KDX6KQ000000000000000012', '01KDX6KQ000000000000000011', 'data', 0, NULL, NULL),
	('01KDX6KQ000000000000000018', '01KDX6KQ000000000000000015', 'data', 0, NULL, NULL),
	('01KDX6KQ00000000000000001H', '01KDX6KQ00000000000000001E', 'data', 0, NULL, NULL),
	('01KDX6KQ00000000000000002J', '01KDX6KQ00000000000000002F', 'data', 0, NULL, NULL),
	('01KDX6KQ00000000000000002R', '01KDX6KQ00000000000000002N', 'data', 0, NULL, NULL),
	('01KDX6KQ00000000000000002W', '01KDX6KQ00000000000000002V', 'data', 0, NULL, NULL),
	('01KDX6KQ000000000000000030', '01KDX6KQ00000000000000002Z', 'data', 0, NULL, NULL),
	('01KDX6KQ000000000000000036', '01KDX6KQ000000000000000033', 'data', 0, NULL, NULL),
	('01KDX6KQ00000000000000003F', '01KDX6KQ00000000000000003C', 'data', 0, NULL, NULL),
	('01KDX6KQ00000000000000004G', '01KDX6KQ00000000000000004D', 'data', 0, NULL, NULL),
	('01KDX6KQ00000000000000004P', '01KDX6KQ00000000000000004K', 'data', 0, NULL, NULL),
	('01KDX6KQ00000000000000004T', '01KDX6KQ00000000000000004S', 'data', 0, NULL, NULL),
	('01KDX6KQ00000000000000004Y', '01KDX6KQ00000000000000004X', 'data', 0, NULL, NULL),
	('01KDX6KQ000000000000000054', '01KDX6KQ000000000000000051', 'data', 0, NULL, NULL),
	('01KDX6KQ00000000000000005D', '01KDX6KQ00000000000000005A', 'data', 0, NULL, NULL),
	('01KDX6KQ00000000000000005Y', '01KDX6KQ00000000000000005V', 'data', 0, NULL, NULL),
	('01KDX6KQ000000000000000063', '01KDX6KQ00000000000000005V', 'data', 0, NULL, NULL),
	('01KDX6KQ000000000000000069', '01KDX6KQ000000000000000066', 'data', 0, NULL, NULL);


--
-- Data for Name: v_file_versions; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_file_versions" VALUES
	('01KDX6KQ00000000000000000M', NULL, '01KDX6KQ00000000000000000K', 1, '01KDX6KQ00000000000000000H', NULL, '17fd007b1eae89012f85996449b99ab04057b97183729e08ac1c2fcb7bb813e5', 19, 'text/plain', 'brief.txt', 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:05:00', '2026-01-01 00:05:00'),
	('01KDX6KQ00000000000000000T', NULL, '01KDX6KQ00000000000000000S', 1, '01KDX6KQ00000000000000000Q', NULL, 'f400d6b970764284311640397f4c438064aca96028ebdebaa8d840c2ceba0c55', 16, 'text/csv', 'q1.csv', 'superseded', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:06:00', '2026-01-01 00:06:00'),
	('01KDX6KQ000000000000000012', NULL, '01KDX6KQ00000000000000000S', 3, '01KDX6KQ000000000000000011', NULL, 'c4d0686d6e7feb53f3bd7d33abe43208ff2b11b8da00541000253699b2956370', 16, 'text/csv', 'q1.csv', 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:08:00', '2026-01-01 00:08:00'),
	('01KDX6KQ00000000000000000Y', NULL, '01KDX6KQ00000000000000000S', 2, '01KDX6KQ00000000000000000X', NULL, '740312e7cc4336ce5e74fee427747611908b8183a7288694ed173a551888eec7', 16, 'text/csv', 'q1.csv', 'superseded', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:07:00', '2026-01-01 00:07:00'),
	('01KDX6KQ000000000000000018', NULL, '01KDX6KQ000000000000000017', 1, '01KDX6KQ000000000000000015', NULL, '5213ee530d7fedcdbf09e3c35032d25e9615a1504cb9d16638e21a1b52ca7c4d', 17, 'text/plain', 'old.txt', 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:09:00', '2026-01-01 00:09:00'),
	('01KDX6KQ00000000000000001H', NULL, '01KDX6KQ00000000000000001G', 1, '01KDX6KQ00000000000000001E', NULL, '77ee30304b5e58719e60c82f3266df23490ceb995de1bec53ce869ce93fa2c68', 21, 'text/plain', 'note.txt', 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:11:00', '2026-01-01 00:11:00'),
	('01KDX6KQ00000000000000002J', NULL, '01KDX6KQ00000000000000002H', 1, '01KDX6KQ00000000000000002F', NULL, '54eb55f3b784afa49b52cb67c214482344b37b9468fbb0f030289cc26790a10e', 19, 'text/plain', 'brief.txt', 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:22:00', '2026-01-01 00:22:00'),
	('01KDX6KQ00000000000000002R', NULL, '01KDX6KQ00000000000000002Q', 1, '01KDX6KQ00000000000000002N', NULL, '01710ad00516dc63c0a3a0a40513d27e28775b2b8634b678660c276e9d7cbeb6', 16, 'text/csv', 'q1.csv', 'superseded', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:23:00', '2026-01-01 00:23:00'),
	('01KDX6KQ000000000000000030', NULL, '01KDX6KQ00000000000000002Q', 3, '01KDX6KQ00000000000000002Z', NULL, '2dbb81cf80e92982dc3776653d46a2940f349b3bfd8c72f2a6e62a901c6b6567', 16, 'text/csv', 'q1.csv', 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:25:00', '2026-01-01 00:25:00'),
	('01KDX6KQ00000000000000002W', NULL, '01KDX6KQ00000000000000002Q', 2, '01KDX6KQ00000000000000002V', NULL, '05a984b99bf5bc2c1d150a8594f3534ebf8e15ec0b1d5c75efea9b4872b4b0fa', 16, 'text/csv', 'q1.csv', 'superseded', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:24:00', '2026-01-01 00:24:00'),
	('01KDX6KQ000000000000000036', NULL, '01KDX6KQ000000000000000035', 1, '01KDX6KQ000000000000000033', NULL, '862d2f069920fd45f2cbc79c1f80f91a296b1badbb16c6f8cdc45ba3d0ff67a6', 17, 'text/plain', 'old.txt', 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:26:00', '2026-01-01 00:26:00'),
	('01KDX6KQ00000000000000003F', NULL, '01KDX6KQ00000000000000003E', 1, '01KDX6KQ00000000000000003C', NULL, '5f002e563dde8f8e90e25c2323a54c049842847ff16a081c7e796b27694dad02', 21, 'text/plain', 'note.txt', 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:28:00', '2026-01-01 00:28:00'),
	('01KDX6KQ00000000000000004G', NULL, '01KDX6KQ00000000000000004F', 1, '01KDX6KQ00000000000000004D', NULL, 'a351bd4c010c5fc3d5bd1d608946dd733c5a75ca8c0b70045d013ff401d85891', 19, 'text/plain', 'brief.txt', 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:39:00', '2026-01-01 00:39:00'),
	('01KDX6KQ00000000000000004P', NULL, '01KDX6KQ00000000000000004N', 1, '01KDX6KQ00000000000000004K', NULL, '24226fe8df109276008435a9835f77db119559ba7ac97406743a4283d785361d', 16, 'text/csv', 'q1.csv', 'superseded', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:40:00', '2026-01-01 00:40:00'),
	('01KDX6KQ00000000000000004Y', NULL, '01KDX6KQ00000000000000004N', 3, '01KDX6KQ00000000000000004X', NULL, '7787ed77e8eb0975aa39afef85ddf6b1e1aaa91446cd7779e40ee2a651e47df8', 16, 'text/csv', 'q1.csv', 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:42:00', '2026-01-01 00:42:00'),
	('01KDX6KQ00000000000000004T', NULL, '01KDX6KQ00000000000000004N', 2, '01KDX6KQ00000000000000004S', NULL, 'fe52cce6d9ff851348ac4a56c0aeaf7c56ec01995f4aee50dd1fbe4049c230ec', 16, 'text/csv', 'q1.csv', 'superseded', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:41:00', '2026-01-01 00:41:00'),
	('01KDX6KQ000000000000000054', NULL, '01KDX6KQ000000000000000053', 1, '01KDX6KQ000000000000000051', NULL, 'db2ecfae131b0fdd5fad941bd35c249e7a9523559737765d3e73cd91183dd9d5', 17, 'text/plain', 'old.txt', 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:43:00', '2026-01-01 00:43:00'),
	('01KDX6KQ00000000000000005D', NULL, '01KDX6KQ00000000000000005C', 1, '01KDX6KQ00000000000000005A', NULL, '6411a3531c9f8239119d6f341cdbd87b57a8a6b2316825454cadb2e80c6603a8', 21, 'text/plain', 'note.txt', 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:45:00', '2026-01-01 00:45:00'),
	('01KDX6KQ00000000000000005Y', NULL, '01KDX6KQ00000000000000005X', 1, '01KDX6KQ00000000000000005V', NULL, '94364e27e6e12dbfc6ccf67281bd396596ffb68d5e76723e4f2f2c90eb9c1d8d', 29, 'application/octet-stream', 'shared.bin', 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:52:00', '2026-01-01 00:52:00'),
	('01KDX6KQ000000000000000063', NULL, '01KDX6KQ000000000000000062', 1, '01KDX6KQ00000000000000005V', NULL, '94364e27e6e12dbfc6ccf67281bd396596ffb68d5e76723e4f2f2c90eb9c1d8d', 29, 'application/octet-stream', 'shared.bin', 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:53:00', '2026-01-01 00:53:00'),
	('01KDX6KQ000000000000000069', NULL, '01KDX6KQ000000000000000068', 1, '01KDX6KQ000000000000000066', NULL, '42416976a60a7a8a85a0efb8d41e755a44df6dc739160b61fbe5b16cf8b0755a', 33, 'application/octet-stream', 'tenant.bin', 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:54:00', '2026-01-01 00:54:00');


--
-- Data for Name: v_files; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_files" VALUES
	('01KDX6KQ00000000000000000K', NULL, '01KDX6KQ00000000000000000J', '01KDX6KQ00000000000000000M', 'text/plain', 19, 1, NULL, NULL, NULL, '2026-01-01 00:05:00', '2026-01-01 00:05:00'),
	('01KDX6KQ00000000000000000S', NULL, '01KDX6KQ00000000000000000R', '01KDX6KQ000000000000000012', 'text/csv', 16, 3, NULL, NULL, NULL, '2026-01-01 00:06:00', '2026-01-01 00:08:00'),
	('01KDX6KQ000000000000000017', NULL, '01KDX6KQ000000000000000016', '01KDX6KQ000000000000000018', 'text/plain', 17, 1, NULL, NULL, NULL, '2026-01-01 00:09:00', '2026-01-01 00:09:00'),
	('01KDX6KQ00000000000000001G', NULL, '01KDX6KQ00000000000000001F', '01KDX6KQ00000000000000001H', 'text/plain', 21, 1, NULL, NULL, NULL, '2026-01-01 00:11:00', '2026-01-01 00:11:00'),
	('01KDX6KQ00000000000000002H', NULL, '01KDX6KQ00000000000000002G', '01KDX6KQ00000000000000002J', 'text/plain', 19, 1, NULL, NULL, NULL, '2026-01-01 00:22:00', '2026-01-01 00:22:00'),
	('01KDX6KQ00000000000000002Q', NULL, '01KDX6KQ00000000000000002P', '01KDX6KQ000000000000000030', 'text/csv', 16, 3, NULL, NULL, NULL, '2026-01-01 00:23:00', '2026-01-01 00:25:00'),
	('01KDX6KQ000000000000000035', NULL, '01KDX6KQ000000000000000034', '01KDX6KQ000000000000000036', 'text/plain', 17, 1, NULL, NULL, NULL, '2026-01-01 00:26:00', '2026-01-01 00:26:00'),
	('01KDX6KQ00000000000000003E', NULL, '01KDX6KQ00000000000000003D', '01KDX6KQ00000000000000003F', 'text/plain', 21, 1, NULL, NULL, NULL, '2026-01-01 00:28:00', '2026-01-01 00:28:00'),
	('01KDX6KQ00000000000000004F', NULL, '01KDX6KQ00000000000000004E', '01KDX6KQ00000000000000004G', 'text/plain', 19, 1, NULL, NULL, NULL, '2026-01-01 00:39:00', '2026-01-01 00:39:00'),
	('01KDX6KQ00000000000000004N', NULL, '01KDX6KQ00000000000000004M', '01KDX6KQ00000000000000004Y', 'text/csv', 16, 3, NULL, NULL, NULL, '2026-01-01 00:40:00', '2026-01-01 00:42:00'),
	('01KDX6KQ000000000000000053', NULL, '01KDX6KQ000000000000000052', '01KDX6KQ000000000000000054', 'text/plain', 17, 1, NULL, NULL, NULL, '2026-01-01 00:43:00', '2026-01-01 00:43:00'),
	('01KDX6KQ00000000000000005C', NULL, '01KDX6KQ00000000000000005B', '01KDX6KQ00000000000000005D', 'text/plain', 21, 1, NULL, NULL, NULL, '2026-01-01 00:45:00', '2026-01-01 00:45:00'),
	('01KDX6KQ00000000000000005X', NULL, '01KDX6KQ00000000000000005W', '01KDX6KQ00000000000000005Y', 'application/octet-stream', 29, 1, NULL, NULL, NULL, '2026-01-01 00:52:00', '2026-01-01 00:52:00'),
	('01KDX6KQ000000000000000062', NULL, '01KDX6KQ000000000000000061', '01KDX6KQ000000000000000063', 'application/octet-stream', 29, 1, NULL, NULL, NULL, '2026-01-01 00:53:00', '2026-01-01 00:53:00'),
	('01KDX6KQ000000000000000068', NULL, '01KDX6KQ000000000000000067', '01KDX6KQ000000000000000069', 'application/octet-stream', 33, 1, NULL, NULL, NULL, '2026-01-01 00:54:00', '2026-01-01 00:54:00');


--
-- Data for Name: v_node_comments; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: v_node_document_versions; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_node_document_versions" VALUES
	('01KDX6KQ00000000000000006D', NULL, '01KDX6KQ00000000000000006C', 1, '{"type": "doc", "content": [{"type": "paragraph", "content": [{"text": "Notes v1", "type": "text"}]}]}', NULL, NULL, 'richtext', '2cb2e79dc80ba1aef0e7a77f8aeb9d05e3c5274111dcec241052753146eb197f', NULL, false, 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:55:00'),
	('01KDX6KQ00000000000000006F', NULL, '01KDX6KQ00000000000000006E', 1, '{"type": "doc", "content": [{"type": "paragraph", "content": [{"text": "Budget v1", "type": "text"}]}]}', NULL, NULL, 'table', '4c887b035155bc2c9c92c17776c363c4fc644a92ccd6eee550d0e41e4acf66f6', NULL, false, 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:56:00'),
	('01KDX6KQ00000000000000006H', NULL, '01KDX6KQ00000000000000006G', 1, '{"type": "doc", "content": [{"type": "paragraph", "content": [{"text": "Readme v1", "type": "text"}]}]}', NULL, NULL, 'markdown', '8190d674e2d3ee38163fd067499b6570bf2ffb4a84c8261ae26efb9959e5c556', NULL, false, 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:57:00'),
	('01KDX6KQ00000000000000006K', NULL, '01KDX6KQ00000000000000006J', 1, '{"type": "doc", "content": [{"type": "paragraph", "content": [{"text": "Letter template v1", "type": "text"}]}]}', NULL, NULL, 'template', 'f84f6800101d3eb5ac5e0110cf4ffca24a2936181901f6621291552fb7c65cac', NULL, false, 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 00:58:00'),
	('01KDX6KQ00000000000000006M', NULL, '01KDX6KQ00000000000000006C', 2, '{"type": "doc", "content": [{"type": "paragraph", "content": [{"text": "richtext v2", "type": "text"}]}]}', NULL, NULL, 'richtext', '417d10efa92642f3bd057d96bab2cc619f4af852a7d4f008237c616f61b44308', NULL, true, 'committed', false, NULL, NULL, '2026-01-01 00:59:00'),
	('01KDX6KQ00000000000000006N', NULL, '01KDX6KQ00000000000000006G', 2, '{"type": "doc", "content": [{"type": "paragraph", "content": [{"text": "markdown v2", "type": "text"}]}]}', NULL, NULL, 'markdown', '9ea6a2f5567068885dd689bd31f9c43534d09a7a8b76db76d78e4c2f7b7ffda4', NULL, true, 'committed', false, NULL, NULL, '2026-01-01 01:00:00'),
	('01KDX6KQ00000000000000006Q', NULL, '01KDX6KQ00000000000000006P', 1, '{"type": "doc", "content": [{"type": "paragraph", "content": [{"text": "loose", "type": "text"}]}]}', NULL, NULL, 'richtext', 'f47fb0ea0f7abc7ee4a56cbd76bfa564c111a8e3fbc7df833b65136ac74116ff', NULL, false, 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 01:01:00'),
	('01KDX6KQ00000000000000006S', NULL, '01KDX6KQ00000000000000006R', 1, '{"type": "doc", "content": [{"type": "paragraph", "content": [{"text": "name clash with the root folder", "type": "text"}]}]}', NULL, NULL, 'richtext', '0cae7f5294201c02bc97ae0d1f64cdc91f8b0237fc6e25de066b5630b9805666', NULL, false, 'committed', false, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 01:02:00');


--
-- Data for Name: v_node_grants; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_node_grants" VALUES
	('01KDX6KQ00000000000000006V', NULL, '01KDX6KQ000000000000000005', '01ARZ3NDEKTSV4RRFFQ69G5FB1', 'user', 'writer', 0, 'allow', 'subtree', false, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 01:04:00', '2026-01-01 01:04:00'),
	('01KDX6KQ00000000000000006Y', NULL, '01KDX6KQ000000000000000005', '01ARZ3NDEKTSV4RRFFQ69G5FB3', 'role', 'writer', 0, 'allow', 'subtree', false, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 01:05:00', '2026-01-01 01:05:00'),
	('01KDX6KQ000000000000000071', NULL, '01KDX6KQ000000000000000005', '01ARZ3NDEKTSV4RRFFQ69G5FB2', 'user', 'writer', 0, 'allow', 'subtree', false, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, '2026-01-01 01:09:00', '2026-01-01 01:06:00', '2026-01-01 01:09:00'),
	('01KDX6KQ000000000000000074', NULL, '01KDX6KQ000000000000000005', '01ARZ3NDEKTSV4RRFFQ69G5FB4', 'role', 'writer', 0, 'allow', 'subtree', false, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, '2026-01-01 01:10:00', '2026-01-01 01:07:00', '2026-01-01 01:10:00');


--
-- Data for Name: v_node_shares; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_node_shares" VALUES
	('01KDX6KQ00000000000000007F', NULL, '01KDX6KQ00000000000000000J', 'cc699e2456918a67d078db4e5e8655bc40f73146ca40f5fffc137f5901c832a9', 'fx000003', 1, 'reader', 'item', NULL, false, false, '2027-01-01 01:11:00', NULL, 0, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 01:11:00', '2026-01-01 01:11:00'),
	('01KDX6KQ00000000000000007J', NULL, '01KDX6KQ00000000000000000J', 'd80d249f3ee6d3d1c0fdc62ffda8f30e94ca3c340e38dc2cbd628b491fc657fb', 'fx000004', 1, 'reader', 'item', NULL, false, false, '2027-01-01 01:12:00', NULL, 0, '2026-01-01 01:14:00', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 01:12:00', '2026-01-01 01:14:00'),
	('01KDX6KQ00000000000000007N', NULL, '01KDX6KQ00000000000000000J', 'e3f840e240e14db43f7a3265c640d988eb82e5ffd1a7f45efb0536047abaf0ea', 'fx000005', 1, 'reader', 'item', NULL, false, false, '2027-01-01 01:13:00', NULL, 0, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, '2026-01-01 01:15:00', '2026-01-01 01:13:00', '2026-01-01 01:15:00');


--
-- Data for Name: v_nodes; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_nodes" VALUES
	('01KDX6KQ000000000000000002', 'default', NULL, '01KDX6KQ000000000000000001', NULL, 'folder', '', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
	('01KDX6KQ000000000000000005', 'default', NULL, '01KDX6KQ000000000000000001', '01KDX6KQ000000000000000002', 'folder', 'Projects', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:01:00', '2026-01-01 00:01:00'),
	('01KDX6KQ00000000000000000B', 'default', NULL, '01KDX6KQ000000000000000001', '01KDX6KQ000000000000000002', 'folder', 'Archive', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:03:00', '2026-01-01 00:03:00'),
	('01KDX6KQ00000000000000000J', 'default', NULL, '01KDX6KQ000000000000000001', '01KDX6KQ000000000000000005', 'file', 'brief.txt', 'text/plain', 'txt', 19, '01KDX6KQ00000000000000000M', '01KDX6KQ00000000000000000K', 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:05:00', '2026-01-01 00:05:00'),
	('01KDX6KQ00000000000000000R', 'default', NULL, '01KDX6KQ000000000000000001', '01KDX6KQ000000000000000008', 'file', 'q1.csv', 'text/csv', 'csv', 16, '01KDX6KQ000000000000000012', '01KDX6KQ00000000000000000S', 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:06:00', '2026-01-01 00:08:00'),
	('01KDX6KQ000000000000000008', 'default', NULL, '01KDX6KQ000000000000000001', '01KDX6KQ000000000000000005', 'folder', 'Reports 2025', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:02:00', '2026-01-01 00:12:00'),
	('01KDX6KQ00000000000000000E', 'default', NULL, '01KDX6KQ000000000000000001', '01KDX6KQ000000000000000005', 'folder', '2025', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:04:00', '2026-01-01 00:13:00'),
	('01KDX6KQ000000000000000016', 'default', NULL, '01KDX6KQ000000000000000001', '01KDX6KQ00000000000000000B', 'file', 'old.txt', 'text/plain', 'txt', 17, '01KDX6KQ000000000000000018', '01KDX6KQ000000000000000017', 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, '2026-01-01 00:16:00', '01ARZ3NDEKTSV4RRFFQ69G5FB0', '01KDX6KQ00000000000000000B', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:09:00', '2026-01-01 00:16:00'),
	('01KDX6KQ00000000000000001F', 'default', NULL, '01KDX6KQ000000000000000001', '01KDX6KQ00000000000000001B', 'file', 'note.txt', 'text/plain', 'txt', 21, '01KDX6KQ00000000000000001H', '01KDX6KQ00000000000000001G', 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, '2026-01-01 00:17:00', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:11:00', '2026-01-01 00:17:00'),
	('01KDX6KQ00000000000000001B', 'default', NULL, '01KDX6KQ000000000000000001', '01KDX6KQ000000000000000002', 'folder', 'Temp', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, '2026-01-01 00:17:00', '01ARZ3NDEKTSV4RRFFQ69G5FB0', '01KDX6KQ000000000000000002', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:10:00', '2026-01-01 00:17:00'),
	('01KDX6KQ000000000000000020', 'default', NULL, '01KDX6KQ00000000000000001Z', NULL, 'folder', '', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-01-01 00:17:00', '2026-01-01 00:17:00'),
	('01KDX6KQ000000000000000023', 'default', NULL, '01KDX6KQ00000000000000001Z', '01KDX6KQ000000000000000020', 'folder', 'Projects', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:18:00', '2026-01-01 00:18:00'),
	('01KDX6KQ000000000000000029', 'default', NULL, '01KDX6KQ00000000000000001Z', '01KDX6KQ000000000000000020', 'folder', 'Archive', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:20:00', '2026-01-01 00:20:00'),
	('01KDX6KQ00000000000000002G', 'default', NULL, '01KDX6KQ00000000000000001Z', '01KDX6KQ000000000000000023', 'file', 'brief.txt', 'text/plain', 'txt', 19, '01KDX6KQ00000000000000002J', '01KDX6KQ00000000000000002H', 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:22:00', '2026-01-01 00:22:00'),
	('01KDX6KQ00000000000000002P', 'default', NULL, '01KDX6KQ00000000000000001Z', '01KDX6KQ000000000000000026', 'file', 'q1.csv', 'text/csv', 'csv', 16, '01KDX6KQ000000000000000030', '01KDX6KQ00000000000000002Q', 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:23:00', '2026-01-01 00:25:00'),
	('01KDX6KQ000000000000000026', 'default', NULL, '01KDX6KQ00000000000000001Z', '01KDX6KQ000000000000000023', 'folder', 'Reports 2025', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:19:00', '2026-01-01 00:29:00'),
	('01KDX6KQ00000000000000002C', 'default', NULL, '01KDX6KQ00000000000000001Z', '01KDX6KQ000000000000000023', 'folder', '2025', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:21:00', '2026-01-01 00:30:00'),
	('01KDX6KQ000000000000000034', 'default', NULL, '01KDX6KQ00000000000000001Z', '01KDX6KQ000000000000000029', 'file', 'old.txt', 'text/plain', 'txt', 17, '01KDX6KQ000000000000000036', '01KDX6KQ000000000000000035', 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, '2026-01-01 00:33:00', '01ARZ3NDEKTSV4RRFFQ69G5FB0', '01KDX6KQ000000000000000029', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:26:00', '2026-01-01 00:33:00'),
	('01KDX6KQ00000000000000003D', 'default', NULL, '01KDX6KQ00000000000000001Z', '01KDX6KQ000000000000000039', 'file', 'note.txt', 'text/plain', 'txt', 21, '01KDX6KQ00000000000000003F', '01KDX6KQ00000000000000003E', 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, '2026-01-01 00:34:00', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:28:00', '2026-01-01 00:34:00'),
	('01KDX6KQ000000000000000039', 'default', NULL, '01KDX6KQ00000000000000001Z', '01KDX6KQ000000000000000020', 'folder', 'Temp', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, '2026-01-01 00:34:00', '01ARZ3NDEKTSV4RRFFQ69G5FB0', '01KDX6KQ000000000000000020', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:27:00', '2026-01-01 00:34:00'),
	('01KDX6KQ00000000000000003Y', 'default', NULL, '01KDX6KQ00000000000000003X', NULL, 'folder', '', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-01-01 00:34:00', '2026-01-01 00:34:00'),
	('01KDX6KQ000000000000000041', 'default', NULL, '01KDX6KQ00000000000000003X', '01KDX6KQ00000000000000003Y', 'folder', 'Projects', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:35:00', '2026-01-01 00:35:00'),
	('01KDX6KQ000000000000000047', 'default', NULL, '01KDX6KQ00000000000000003X', '01KDX6KQ00000000000000003Y', 'folder', 'Archive', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:37:00', '2026-01-01 00:37:00'),
	('01KDX6KQ00000000000000004E', 'default', NULL, '01KDX6KQ00000000000000003X', '01KDX6KQ000000000000000041', 'file', 'brief.txt', 'text/plain', 'txt', 19, '01KDX6KQ00000000000000004G', '01KDX6KQ00000000000000004F', 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:39:00', '2026-01-01 00:39:00'),
	('01KDX6KQ00000000000000004M', 'default', NULL, '01KDX6KQ00000000000000003X', '01KDX6KQ000000000000000044', 'file', 'q1.csv', 'text/csv', 'csv', 16, '01KDX6KQ00000000000000004Y', '01KDX6KQ00000000000000004N', 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:40:00', '2026-01-01 00:42:00'),
	('01KDX6KQ000000000000000044', 'default', NULL, '01KDX6KQ00000000000000003X', '01KDX6KQ000000000000000041', 'folder', 'Reports 2025', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:36:00', '2026-01-01 00:46:00'),
	('01KDX6KQ00000000000000004A', 'default', NULL, '01KDX6KQ00000000000000003X', '01KDX6KQ000000000000000041', 'folder', '2025', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:38:00', '2026-01-01 00:47:00'),
	('01KDX6KQ000000000000000057', 'default', NULL, '01KDX6KQ00000000000000003X', '01KDX6KQ00000000000000003Y', 'folder', 'Temp', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, '2026-01-01 00:51:00', '01ARZ3NDEKTSV4RRFFQ69G5FB0', '01KDX6KQ00000000000000003Y', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:44:00', '2026-01-01 00:51:00'),
	('01KDX6KQ000000000000000052', 'default', NULL, '01KDX6KQ00000000000000003X', '01KDX6KQ000000000000000047', 'file', 'old.txt', 'text/plain', 'txt', 17, '01KDX6KQ000000000000000054', '01KDX6KQ000000000000000053', 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, '2026-01-01 00:50:00', '01ARZ3NDEKTSV4RRFFQ69G5FB0', '01KDX6KQ000000000000000047', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:43:00', '2026-01-01 00:50:00'),
	('01KDX6KQ00000000000000005B', 'default', NULL, '01KDX6KQ00000000000000003X', '01KDX6KQ000000000000000057', 'file', 'note.txt', 'text/plain', 'txt', 21, '01KDX6KQ00000000000000005D', '01KDX6KQ00000000000000005C', 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, '2026-01-01 00:51:00', '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:45:00', '2026-01-01 00:51:00'),
	('01KDX6KQ00000000000000005W', 'default', NULL, '01KDX6KQ00000000000000001Z', '01KDX6KQ000000000000000020', 'file', 'shared.bin', 'application/octet-stream', 'bin', 29, '01KDX6KQ00000000000000005Y', '01KDX6KQ00000000000000005X', 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:52:00', '2026-01-01 00:52:00'),
	('01KDX6KQ000000000000000061', 'default', NULL, '01KDX6KQ00000000000000003X', '01KDX6KQ00000000000000003Y', 'file', 'shared.bin', 'application/octet-stream', 'bin', 29, '01KDX6KQ000000000000000063', '01KDX6KQ000000000000000062', 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:53:00', '2026-01-01 00:53:00'),
	('01KDX6KQ000000000000000067', 'default', NULL, '01KDX6KQ00000000000000003X', '01KDX6KQ00000000000000003Y', 'file', 'tenant.bin', 'application/octet-stream', 'bin', 33, '01KDX6KQ000000000000000069', '01KDX6KQ000000000000000068', 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:54:00', '2026-01-01 00:54:00'),
	('01KDX6KQ00000000000000006E', 'default', NULL, '01KDX6KQ000000000000000001', '01KDX6KQ000000000000000005', 'document', 'Budget', 'application/vnd.vaulter.document+json', NULL, 94, '01KDX6KQ00000000000000006F', NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:56:00', '2026-01-01 00:56:00'),
	('01KDX6KQ00000000000000006J', 'default', NULL, '01KDX6KQ000000000000000001', '01KDX6KQ000000000000000005', 'document', 'Letter template', 'application/vnd.vaulter.document+json', NULL, 103, '01KDX6KQ00000000000000006K', NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:58:00', '2026-01-01 00:58:00'),
	('01KDX6KQ00000000000000006C', 'default', NULL, '01KDX6KQ000000000000000001', '01KDX6KQ000000000000000005', 'document', 'Notes', 'application/vnd.vaulter.document+json', NULL, 93, '01KDX6KQ00000000000000006M', NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:55:00', '2026-01-01 00:59:00'),
	('01KDX6KQ00000000000000006G', 'default', NULL, '01KDX6KQ000000000000000001', '01KDX6KQ000000000000000005', 'document', 'Readme', 'application/vnd.vaulter.document+json', NULL, 94, '01KDX6KQ00000000000000006N', NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 00:57:00', '2026-01-01 01:00:00'),
	('01KDX6KQ00000000000000006P', 'default', NULL, '01KDX6KQ000000000000000001', NULL, 'document', 'Loose note', 'application/vnd.vaulter.document+json', NULL, 90, '01KDX6KQ00000000000000006Q', NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 01:01:00', '2026-01-01 01:01:00'),
	('01KDX6KQ00000000000000006R', 'default', NULL, '01KDX6KQ000000000000000001', NULL, 'document', 'Projects', 'application/vnd.vaulter.document+json', NULL, 116, '01KDX6KQ00000000000000006S', NULL, 0, false, true, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, NULL, NULL, '01ARZ3NDEKTSV4RRFFQ69G5FB0', NULL, NULL, '2026-01-01 01:02:00', '2026-01-01 01:02:00');


--
-- Data for Name: v_outbox; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_outbox" VALUES
	('01KDX6KQ000000000000000004', 'storage.created:01KDX6KQ000000000000000001', 'default', '01KDX6KQ000000000000000001', 'storage.created', '{"storage_id":"01KDX6KQ000000000000000001","root_node_id":"01KDX6KQ000000000000000002","name":"FixtureV020Host storage","event_id":"storage.created:01KDX6KQ000000000000000001","actor_id":null}', '2026-01-01 00:00:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:00:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000007', 'node.created:01KDX6KQ000000000000000005', 'default', '01KDX6KQ000000000000000005', 'node.created', '{"node_id":"01KDX6KQ000000000000000005","storage_id":"01KDX6KQ000000000000000001","parent_id":"01KDX6KQ000000000000000002","kind":"folder","name":"Projects","event_id":"node.created:01KDX6KQ000000000000000005","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:01:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:01:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000000A', 'node.created:01KDX6KQ000000000000000008', 'default', '01KDX6KQ000000000000000008', 'node.created', '{"node_id":"01KDX6KQ000000000000000008","storage_id":"01KDX6KQ000000000000000001","parent_id":"01KDX6KQ000000000000000005","kind":"folder","name":"Reports","event_id":"node.created:01KDX6KQ000000000000000008","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:02:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:02:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000000D', 'node.created:01KDX6KQ00000000000000000B', 'default', '01KDX6KQ00000000000000000B', 'node.created', '{"node_id":"01KDX6KQ00000000000000000B","storage_id":"01KDX6KQ000000000000000001","parent_id":"01KDX6KQ000000000000000002","kind":"folder","name":"Archive","event_id":"node.created:01KDX6KQ00000000000000000B","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:03:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:03:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000000G', 'node.created:01KDX6KQ00000000000000000E', 'default', '01KDX6KQ00000000000000000E', 'node.created', '{"node_id":"01KDX6KQ00000000000000000E","storage_id":"01KDX6KQ000000000000000001","parent_id":"01KDX6KQ00000000000000000B","kind":"folder","name":"2025","event_id":"node.created:01KDX6KQ00000000000000000E","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:04:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:04:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000000P', 'file.uploaded:01KDX6KQ00000000000000000M', 'default', '01KDX6KQ00000000000000000J', 'file.uploaded', '{"node_id":"01KDX6KQ00000000000000000J","storage_id":"01KDX6KQ000000000000000001","version_id":"01KDX6KQ00000000000000000M","name":"brief.txt","size_bytes":19,"mime_type":"text\/plain","event_id":"file.uploaded:01KDX6KQ00000000000000000M","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:05:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:05:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000000W', 'file.uploaded:01KDX6KQ00000000000000000T', 'default', '01KDX6KQ00000000000000000R', 'file.uploaded', '{"node_id":"01KDX6KQ00000000000000000R","storage_id":"01KDX6KQ000000000000000001","version_id":"01KDX6KQ00000000000000000T","name":"q1.csv","size_bytes":16,"mime_type":"text\/csv","event_id":"file.uploaded:01KDX6KQ00000000000000000T","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:06:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:06:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000010', 'version.created:01KDX6KQ00000000000000000Y', 'default', '01KDX6KQ00000000000000000R', 'version.created', '{"node_id":"01KDX6KQ00000000000000000R","storage_id":"01KDX6KQ000000000000000001","version_id":"01KDX6KQ00000000000000000Y","version_no":2,"size_bytes":16,"event_id":"version.created:01KDX6KQ00000000000000000Y","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:07:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:07:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000014', 'version.created:01KDX6KQ000000000000000012', 'default', '01KDX6KQ00000000000000000R', 'version.created', '{"node_id":"01KDX6KQ00000000000000000R","storage_id":"01KDX6KQ000000000000000001","version_id":"01KDX6KQ000000000000000012","version_no":3,"size_bytes":16,"event_id":"version.created:01KDX6KQ000000000000000012","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:08:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:08:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000001A', 'file.uploaded:01KDX6KQ000000000000000018', 'default', '01KDX6KQ000000000000000016', 'file.uploaded', '{"node_id":"01KDX6KQ000000000000000016","storage_id":"01KDX6KQ000000000000000001","version_id":"01KDX6KQ000000000000000018","name":"old.txt","size_bytes":17,"mime_type":"text\/plain","event_id":"file.uploaded:01KDX6KQ000000000000000018","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:09:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:09:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000001D', 'node.created:01KDX6KQ00000000000000001B', 'default', '01KDX6KQ00000000000000001B', 'node.created', '{"node_id":"01KDX6KQ00000000000000001B","storage_id":"01KDX6KQ000000000000000001","parent_id":"01KDX6KQ000000000000000002","kind":"folder","name":"Temp","event_id":"node.created:01KDX6KQ00000000000000001B","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:10:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:10:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000001K', 'file.uploaded:01KDX6KQ00000000000000001H', 'default', '01KDX6KQ00000000000000001F', 'file.uploaded', '{"node_id":"01KDX6KQ00000000000000001F","storage_id":"01KDX6KQ000000000000000001","version_id":"01KDX6KQ00000000000000001H","name":"note.txt","size_bytes":21,"mime_type":"text\/plain","event_id":"file.uploaded:01KDX6KQ00000000000000001H","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:11:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:11:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000001N', 'node.renamed:01KDX6KQ000000000000000008:Reports 2025', 'default', '01KDX6KQ000000000000000008', 'node.renamed', '{"node_id":"01KDX6KQ000000000000000008","storage_id":"01KDX6KQ000000000000000001","from":"Reports","to":"Reports 2025","event_id":"node.renamed:01KDX6KQ000000000000000008:Reports 2025","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:12:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:12:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000001Q', 'node.moved:01KDX6KQ00000000000000000E:01KDX6KQ000000000000000005', 'default', '01KDX6KQ00000000000000000E', 'node.moved', '{"node_id":"01KDX6KQ00000000000000000E","storage_id":"01KDX6KQ000000000000000001","from_parent_id":"01KDX6KQ00000000000000000B","to_parent_id":"01KDX6KQ000000000000000005","event_id":"node.moved:01KDX6KQ00000000000000000E:01KDX6KQ000000000000000005","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:13:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:13:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000001S', 'node.trashed:01KDX6KQ000000000000000016', 'default', '01KDX6KQ000000000000000016', 'node.trashed', '{"node_id":"01KDX6KQ000000000000000016","storage_id":"01KDX6KQ000000000000000001","name":"old.txt","event_id":"node.trashed:01KDX6KQ000000000000000016","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:14:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:14:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000001V', 'node.restored:01KDX6KQ000000000000000016', 'default', '01KDX6KQ000000000000000016', 'node.restored', '{"node_id":"01KDX6KQ000000000000000016","storage_id":"01KDX6KQ000000000000000001","parent_id":"01KDX6KQ00000000000000000B","name":"old.txt","event_id":"node.restored:01KDX6KQ000000000000000016","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:15:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:15:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000001Y', 'node.trashed:01KDX6KQ00000000000000001B', 'default', '01KDX6KQ00000000000000001B', 'node.trashed', '{"node_id":"01KDX6KQ00000000000000001B","storage_id":"01KDX6KQ000000000000000001","name":"Temp","event_id":"node.trashed:01KDX6KQ00000000000000001B","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:17:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:17:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000022', 'storage.created:01KDX6KQ00000000000000001Z', 'default', '01KDX6KQ00000000000000001Z', 'storage.created', '{"storage_id":"01KDX6KQ00000000000000001Z","root_node_id":"01KDX6KQ000000000000000020","name":"FixtureV020Host storage","event_id":"storage.created:01KDX6KQ00000000000000001Z","actor_id":null}', '2026-01-01 00:17:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:17:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000025', 'node.created:01KDX6KQ000000000000000023', 'default', '01KDX6KQ000000000000000023', 'node.created', '{"node_id":"01KDX6KQ000000000000000023","storage_id":"01KDX6KQ00000000000000001Z","parent_id":"01KDX6KQ000000000000000020","kind":"folder","name":"Projects","event_id":"node.created:01KDX6KQ000000000000000023","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:18:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:18:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000028', 'node.created:01KDX6KQ000000000000000026', 'default', '01KDX6KQ000000000000000026', 'node.created', '{"node_id":"01KDX6KQ000000000000000026","storage_id":"01KDX6KQ00000000000000001Z","parent_id":"01KDX6KQ000000000000000023","kind":"folder","name":"Reports","event_id":"node.created:01KDX6KQ000000000000000026","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:19:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:19:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000002B', 'node.created:01KDX6KQ000000000000000029', 'default', '01KDX6KQ000000000000000029', 'node.created', '{"node_id":"01KDX6KQ000000000000000029","storage_id":"01KDX6KQ00000000000000001Z","parent_id":"01KDX6KQ000000000000000020","kind":"folder","name":"Archive","event_id":"node.created:01KDX6KQ000000000000000029","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:20:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:20:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000002E', 'node.created:01KDX6KQ00000000000000002C', 'default', '01KDX6KQ00000000000000002C', 'node.created', '{"node_id":"01KDX6KQ00000000000000002C","storage_id":"01KDX6KQ00000000000000001Z","parent_id":"01KDX6KQ000000000000000029","kind":"folder","name":"2025","event_id":"node.created:01KDX6KQ00000000000000002C","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:21:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:21:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000002M', 'file.uploaded:01KDX6KQ00000000000000002J', 'default', '01KDX6KQ00000000000000002G', 'file.uploaded', '{"node_id":"01KDX6KQ00000000000000002G","storage_id":"01KDX6KQ00000000000000001Z","version_id":"01KDX6KQ00000000000000002J","name":"brief.txt","size_bytes":19,"mime_type":"text\/plain","event_id":"file.uploaded:01KDX6KQ00000000000000002J","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:22:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:22:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000002T', 'file.uploaded:01KDX6KQ00000000000000002R', 'default', '01KDX6KQ00000000000000002P', 'file.uploaded', '{"node_id":"01KDX6KQ00000000000000002P","storage_id":"01KDX6KQ00000000000000001Z","version_id":"01KDX6KQ00000000000000002R","name":"q1.csv","size_bytes":16,"mime_type":"text\/csv","event_id":"file.uploaded:01KDX6KQ00000000000000002R","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:23:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:23:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000002Y', 'version.created:01KDX6KQ00000000000000002W', 'default', '01KDX6KQ00000000000000002P', 'version.created', '{"node_id":"01KDX6KQ00000000000000002P","storage_id":"01KDX6KQ00000000000000001Z","version_id":"01KDX6KQ00000000000000002W","version_no":2,"size_bytes":16,"event_id":"version.created:01KDX6KQ00000000000000002W","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:24:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:24:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000032', 'version.created:01KDX6KQ000000000000000030', 'default', '01KDX6KQ00000000000000002P', 'version.created', '{"node_id":"01KDX6KQ00000000000000002P","storage_id":"01KDX6KQ00000000000000001Z","version_id":"01KDX6KQ000000000000000030","version_no":3,"size_bytes":16,"event_id":"version.created:01KDX6KQ000000000000000030","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:25:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:25:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000038', 'file.uploaded:01KDX6KQ000000000000000036', 'default', '01KDX6KQ000000000000000034', 'file.uploaded', '{"node_id":"01KDX6KQ000000000000000034","storage_id":"01KDX6KQ00000000000000001Z","version_id":"01KDX6KQ000000000000000036","name":"old.txt","size_bytes":17,"mime_type":"text\/plain","event_id":"file.uploaded:01KDX6KQ000000000000000036","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:26:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:26:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000003B', 'node.created:01KDX6KQ000000000000000039', 'default', '01KDX6KQ000000000000000039', 'node.created', '{"node_id":"01KDX6KQ000000000000000039","storage_id":"01KDX6KQ00000000000000001Z","parent_id":"01KDX6KQ000000000000000020","kind":"folder","name":"Temp","event_id":"node.created:01KDX6KQ000000000000000039","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:27:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:27:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000003H', 'file.uploaded:01KDX6KQ00000000000000003F', 'default', '01KDX6KQ00000000000000003D', 'file.uploaded', '{"node_id":"01KDX6KQ00000000000000003D","storage_id":"01KDX6KQ00000000000000001Z","version_id":"01KDX6KQ00000000000000003F","name":"note.txt","size_bytes":21,"mime_type":"text\/plain","event_id":"file.uploaded:01KDX6KQ00000000000000003F","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:28:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:28:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000003K', 'node.renamed:01KDX6KQ000000000000000026:Reports 2025', 'default', '01KDX6KQ000000000000000026', 'node.renamed', '{"node_id":"01KDX6KQ000000000000000026","storage_id":"01KDX6KQ00000000000000001Z","from":"Reports","to":"Reports 2025","event_id":"node.renamed:01KDX6KQ000000000000000026:Reports 2025","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:29:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:29:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000003N', 'node.moved:01KDX6KQ00000000000000002C:01KDX6KQ000000000000000023', 'default', '01KDX6KQ00000000000000002C', 'node.moved', '{"node_id":"01KDX6KQ00000000000000002C","storage_id":"01KDX6KQ00000000000000001Z","from_parent_id":"01KDX6KQ000000000000000029","to_parent_id":"01KDX6KQ000000000000000023","event_id":"node.moved:01KDX6KQ00000000000000002C:01KDX6KQ000000000000000023","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:30:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:30:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000003Q', 'node.trashed:01KDX6KQ000000000000000034', 'default', '01KDX6KQ000000000000000034', 'node.trashed', '{"node_id":"01KDX6KQ000000000000000034","storage_id":"01KDX6KQ00000000000000001Z","name":"old.txt","event_id":"node.trashed:01KDX6KQ000000000000000034","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:31:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:31:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000003S', 'node.restored:01KDX6KQ000000000000000034', 'default', '01KDX6KQ000000000000000034', 'node.restored', '{"node_id":"01KDX6KQ000000000000000034","storage_id":"01KDX6KQ00000000000000001Z","parent_id":"01KDX6KQ000000000000000029","name":"old.txt","event_id":"node.restored:01KDX6KQ000000000000000034","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:32:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:32:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000003W', 'node.trashed:01KDX6KQ000000000000000039', 'default', '01KDX6KQ000000000000000039', 'node.trashed', '{"node_id":"01KDX6KQ000000000000000039","storage_id":"01KDX6KQ00000000000000001Z","name":"Temp","event_id":"node.trashed:01KDX6KQ000000000000000039","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:34:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:34:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000040', 'storage.created:01KDX6KQ00000000000000003X', 'default', '01KDX6KQ00000000000000003X', 'storage.created', '{"storage_id":"01KDX6KQ00000000000000003X","root_node_id":"01KDX6KQ00000000000000003Y","name":"FixtureV020Host storage","event_id":"storage.created:01KDX6KQ00000000000000003X","actor_id":null}', '2026-01-01 00:34:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:34:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000043', 'node.created:01KDX6KQ000000000000000041', 'default', '01KDX6KQ000000000000000041', 'node.created', '{"node_id":"01KDX6KQ000000000000000041","storage_id":"01KDX6KQ00000000000000003X","parent_id":"01KDX6KQ00000000000000003Y","kind":"folder","name":"Projects","event_id":"node.created:01KDX6KQ000000000000000041","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:35:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:35:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000046', 'node.created:01KDX6KQ000000000000000044', 'default', '01KDX6KQ000000000000000044', 'node.created', '{"node_id":"01KDX6KQ000000000000000044","storage_id":"01KDX6KQ00000000000000003X","parent_id":"01KDX6KQ000000000000000041","kind":"folder","name":"Reports","event_id":"node.created:01KDX6KQ000000000000000044","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:36:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:36:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000049', 'node.created:01KDX6KQ000000000000000047', 'default', '01KDX6KQ000000000000000047', 'node.created', '{"node_id":"01KDX6KQ000000000000000047","storage_id":"01KDX6KQ00000000000000003X","parent_id":"01KDX6KQ00000000000000003Y","kind":"folder","name":"Archive","event_id":"node.created:01KDX6KQ000000000000000047","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:37:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:37:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000004C', 'node.created:01KDX6KQ00000000000000004A', 'default', '01KDX6KQ00000000000000004A', 'node.created', '{"node_id":"01KDX6KQ00000000000000004A","storage_id":"01KDX6KQ00000000000000003X","parent_id":"01KDX6KQ000000000000000047","kind":"folder","name":"2025","event_id":"node.created:01KDX6KQ00000000000000004A","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:38:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:38:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000004J', 'file.uploaded:01KDX6KQ00000000000000004G', 'default', '01KDX6KQ00000000000000004E', 'file.uploaded', '{"node_id":"01KDX6KQ00000000000000004E","storage_id":"01KDX6KQ00000000000000003X","version_id":"01KDX6KQ00000000000000004G","name":"brief.txt","size_bytes":19,"mime_type":"text\/plain","event_id":"file.uploaded:01KDX6KQ00000000000000004G","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:39:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:39:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000004R', 'file.uploaded:01KDX6KQ00000000000000004P', 'default', '01KDX6KQ00000000000000004M', 'file.uploaded', '{"node_id":"01KDX6KQ00000000000000004M","storage_id":"01KDX6KQ00000000000000003X","version_id":"01KDX6KQ00000000000000004P","name":"q1.csv","size_bytes":16,"mime_type":"text\/csv","event_id":"file.uploaded:01KDX6KQ00000000000000004P","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:40:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:40:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000004W', 'version.created:01KDX6KQ00000000000000004T', 'default', '01KDX6KQ00000000000000004M', 'version.created', '{"node_id":"01KDX6KQ00000000000000004M","storage_id":"01KDX6KQ00000000000000003X","version_id":"01KDX6KQ00000000000000004T","version_no":2,"size_bytes":16,"event_id":"version.created:01KDX6KQ00000000000000004T","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:41:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:41:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000050', 'version.created:01KDX6KQ00000000000000004Y', 'default', '01KDX6KQ00000000000000004M', 'version.created', '{"node_id":"01KDX6KQ00000000000000004M","storage_id":"01KDX6KQ00000000000000003X","version_id":"01KDX6KQ00000000000000004Y","version_no":3,"size_bytes":16,"event_id":"version.created:01KDX6KQ00000000000000004Y","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:42:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:42:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000056', 'file.uploaded:01KDX6KQ000000000000000054', 'default', '01KDX6KQ000000000000000052', 'file.uploaded', '{"node_id":"01KDX6KQ000000000000000052","storage_id":"01KDX6KQ00000000000000003X","version_id":"01KDX6KQ000000000000000054","name":"old.txt","size_bytes":17,"mime_type":"text\/plain","event_id":"file.uploaded:01KDX6KQ000000000000000054","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:43:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:43:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000059', 'node.created:01KDX6KQ000000000000000057', 'default', '01KDX6KQ000000000000000057', 'node.created', '{"node_id":"01KDX6KQ000000000000000057","storage_id":"01KDX6KQ00000000000000003X","parent_id":"01KDX6KQ00000000000000003Y","kind":"folder","name":"Temp","event_id":"node.created:01KDX6KQ000000000000000057","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:44:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:44:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000005F', 'file.uploaded:01KDX6KQ00000000000000005D', 'default', '01KDX6KQ00000000000000005B', 'file.uploaded', '{"node_id":"01KDX6KQ00000000000000005B","storage_id":"01KDX6KQ00000000000000003X","version_id":"01KDX6KQ00000000000000005D","name":"note.txt","size_bytes":21,"mime_type":"text\/plain","event_id":"file.uploaded:01KDX6KQ00000000000000005D","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:45:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:45:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000005H', 'node.renamed:01KDX6KQ000000000000000044:Reports 2025', 'default', '01KDX6KQ000000000000000044', 'node.renamed', '{"node_id":"01KDX6KQ000000000000000044","storage_id":"01KDX6KQ00000000000000003X","from":"Reports","to":"Reports 2025","event_id":"node.renamed:01KDX6KQ000000000000000044:Reports 2025","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:46:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:46:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000005K', 'node.moved:01KDX6KQ00000000000000004A:01KDX6KQ000000000000000041', 'default', '01KDX6KQ00000000000000004A', 'node.moved', '{"node_id":"01KDX6KQ00000000000000004A","storage_id":"01KDX6KQ00000000000000003X","from_parent_id":"01KDX6KQ000000000000000047","to_parent_id":"01KDX6KQ000000000000000041","event_id":"node.moved:01KDX6KQ00000000000000004A:01KDX6KQ000000000000000041","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:47:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:47:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000005N', 'node.trashed:01KDX6KQ000000000000000052', 'default', '01KDX6KQ000000000000000052', 'node.trashed', '{"node_id":"01KDX6KQ000000000000000052","storage_id":"01KDX6KQ00000000000000003X","name":"old.txt","event_id":"node.trashed:01KDX6KQ000000000000000052","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:48:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:48:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000005Q', 'node.restored:01KDX6KQ000000000000000052', 'default', '01KDX6KQ000000000000000052', 'node.restored', '{"node_id":"01KDX6KQ000000000000000052","storage_id":"01KDX6KQ00000000000000003X","parent_id":"01KDX6KQ000000000000000047","name":"old.txt","event_id":"node.restored:01KDX6KQ000000000000000052","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:49:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:49:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000005T', 'node.trashed:01KDX6KQ000000000000000057', 'default', '01KDX6KQ000000000000000057', 'node.trashed', '{"node_id":"01KDX6KQ000000000000000057","storage_id":"01KDX6KQ00000000000000003X","name":"Temp","event_id":"node.trashed:01KDX6KQ000000000000000057","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:51:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:51:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000060', 'file.uploaded:01KDX6KQ00000000000000005Y', 'default', '01KDX6KQ00000000000000005W', 'file.uploaded', '{"node_id":"01KDX6KQ00000000000000005W","storage_id":"01KDX6KQ00000000000000001Z","version_id":"01KDX6KQ00000000000000005Y","name":"shared.bin","size_bytes":29,"mime_type":"application\/octet-stream","event_id":"file.uploaded:01KDX6KQ00000000000000005Y","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:52:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:52:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000065', 'file.uploaded:01KDX6KQ000000000000000063', 'default', '01KDX6KQ000000000000000061', 'file.uploaded', '{"node_id":"01KDX6KQ000000000000000061","storage_id":"01KDX6KQ00000000000000003X","version_id":"01KDX6KQ000000000000000063","name":"shared.bin","size_bytes":29,"mime_type":"application\/octet-stream","event_id":"file.uploaded:01KDX6KQ000000000000000063","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:53:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:53:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000006B', 'file.uploaded:01KDX6KQ000000000000000069', 'default', '01KDX6KQ000000000000000067', 'file.uploaded', '{"node_id":"01KDX6KQ000000000000000067","storage_id":"01KDX6KQ00000000000000003X","version_id":"01KDX6KQ000000000000000069","name":"tenant.bin","size_bytes":33,"mime_type":"application\/octet-stream","event_id":"file.uploaded:01KDX6KQ000000000000000069","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 00:54:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 00:54:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000006X', 'grant.created:01KDX6KQ00000000000000006V', 'default', '01KDX6KQ000000000000000005', 'grant.created', '{"grant_id":"01KDX6KQ00000000000000006V","node_id":"01KDX6KQ000000000000000005","storage_id":"01KDX6KQ000000000000000001","subject_type":"user","subject_id":"01ARZ3NDEKTSV4RRFFQ69G5FB1","level":"writer","effect":"allow","scope":"subtree","event_id":"grant.created:01KDX6KQ00000000000000006V","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 01:04:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 01:04:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000070', 'grant.created:01KDX6KQ00000000000000006Y', 'default', '01KDX6KQ000000000000000005', 'grant.created', '{"grant_id":"01KDX6KQ00000000000000006Y","node_id":"01KDX6KQ000000000000000005","storage_id":"01KDX6KQ000000000000000001","subject_type":"role","subject_id":"01ARZ3NDEKTSV4RRFFQ69G5FB3","level":"writer","effect":"allow","scope":"subtree","event_id":"grant.created:01KDX6KQ00000000000000006Y","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 01:05:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 01:05:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000073', 'grant.created:01KDX6KQ000000000000000071', 'default', '01KDX6KQ000000000000000005', 'grant.created', '{"grant_id":"01KDX6KQ000000000000000071","node_id":"01KDX6KQ000000000000000005","storage_id":"01KDX6KQ000000000000000001","subject_type":"user","subject_id":"01ARZ3NDEKTSV4RRFFQ69G5FB2","level":"writer","effect":"allow","scope":"subtree","event_id":"grant.created:01KDX6KQ000000000000000071","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 01:06:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 01:06:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000076', 'grant.created:01KDX6KQ000000000000000074', 'default', '01KDX6KQ000000000000000005', 'grant.created', '{"grant_id":"01KDX6KQ000000000000000074","node_id":"01KDX6KQ000000000000000005","storage_id":"01KDX6KQ000000000000000001","subject_type":"role","subject_id":"01ARZ3NDEKTSV4RRFFQ69G5FB4","level":"writer","effect":"allow","scope":"subtree","event_id":"grant.created:01KDX6KQ000000000000000074","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 01:07:00', '2026-01-01 01:08:00', 0, 'dispatched', '2026-01-01 01:07:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000007C', 'grant.revoked:01KDX6KQ000000000000000005:user:01ARZ3NDEKTSV4RRFFQ69G5FB2', 'default', '01KDX6KQ000000000000000005', 'grant.revoked', '{"node_id":"01KDX6KQ000000000000000005","storage_id":"01KDX6KQ000000000000000001","subject_type":"user","subject_id":"01ARZ3NDEKTSV4RRFFQ69G5FB2","revoked":1,"event_id":"grant.revoked:01KDX6KQ000000000000000005:user:01ARZ3NDEKTSV4RRFFQ69G5FB2","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 01:09:00', NULL, 0, 'pending', '2026-01-01 01:09:00', '2026-01-01 01:09:00'),
	('01KDX6KQ00000000000000007E', 'grant.revoked:01KDX6KQ000000000000000005:role:01ARZ3NDEKTSV4RRFFQ69G5FB4', 'default', '01KDX6KQ000000000000000005', 'grant.revoked', '{"node_id":"01KDX6KQ000000000000000005","storage_id":"01KDX6KQ000000000000000001","subject_type":"role","subject_id":"01ARZ3NDEKTSV4RRFFQ69G5FB4","revoked":1,"event_id":"grant.revoked:01KDX6KQ000000000000000005:role:01ARZ3NDEKTSV4RRFFQ69G5FB4","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 01:10:00', NULL, 0, 'pending', '2026-01-01 01:10:00', '2026-01-01 01:10:00'),
	('01KDX6KQ00000000000000007H', 'share.created:01KDX6KQ00000000000000007F', 'default', '01KDX6KQ00000000000000000J', 'share.created', '{"share_id":"01KDX6KQ00000000000000007F","node_id":"01KDX6KQ00000000000000000J","storage_id":"01KDX6KQ000000000000000001","token_prefix":"fx000003","level":"reader","scope":"item","permissions":1,"event_id":"share.created:01KDX6KQ00000000000000007F","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 01:11:00', NULL, 0, 'pending', '2026-01-01 01:11:00', '2026-01-01 01:11:00'),
	('01KDX6KQ00000000000000007M', 'share.created:01KDX6KQ00000000000000007J', 'default', '01KDX6KQ00000000000000000J', 'share.created', '{"share_id":"01KDX6KQ00000000000000007J","node_id":"01KDX6KQ00000000000000000J","storage_id":"01KDX6KQ000000000000000001","token_prefix":"fx000004","level":"reader","scope":"item","permissions":1,"event_id":"share.created:01KDX6KQ00000000000000007J","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 01:12:00', NULL, 0, 'pending', '2026-01-01 01:12:00', '2026-01-01 01:12:00'),
	('01KDX6KQ00000000000000007Q', 'share.created:01KDX6KQ00000000000000007N', 'default', '01KDX6KQ00000000000000000J', 'share.created', '{"share_id":"01KDX6KQ00000000000000007N","node_id":"01KDX6KQ00000000000000000J","storage_id":"01KDX6KQ000000000000000001","token_prefix":"fx000005","level":"reader","scope":"item","permissions":1,"event_id":"share.created:01KDX6KQ00000000000000007N","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 01:13:00', NULL, 0, 'pending', '2026-01-01 01:13:00', '2026-01-01 01:13:00'),
	('01KDX6KQ00000000000000007S', 'share.revoked:01KDX6KQ00000000000000007J', 'default', '01KDX6KQ00000000000000000J', 'share.revoked', '{"share_id":"01KDX6KQ00000000000000007J","node_id":"01KDX6KQ00000000000000000J","storage_id":"01KDX6KQ000000000000000001","token_prefix":"fx000004","event_id":"share.revoked:01KDX6KQ00000000000000007J","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', '2026-01-01 01:14:00', NULL, 0, 'pending', '2026-01-01 01:14:00', '2026-01-01 01:14:00');


--
-- Data for Name: v_storages; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_storages" VALUES
	('01KDX6KQ000000000000000001', 'default', NULL, '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'fixture_host', 'FixtureV020Host storage', NULL, '01KDX6KQ000000000000000002', 'local', 'private', NULL, 73, 'active', 31, NULL, NULL, NULL, '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
	('01KDX6KQ00000000000000001Z', 'default', NULL, '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'fixture_host', 'FixtureV020Host storage', NULL, '01KDX6KQ000000000000000020', 'local', 'private', NULL, 102, 'active', 31, NULL, NULL, NULL, '2026-01-01 00:17:00', '2026-01-01 00:17:00'),
	('01KDX6KQ00000000000000003X', 'default', NULL, '01ARZ3NDEKTSV4RRFFQ69G5FAX', 'fixture_host', 'FixtureV020Host storage', NULL, '01KDX6KQ00000000000000003Y', 'local', 'private', NULL, 135, 'active', 31, NULL, NULL, NULL, '2026-01-01 00:34:00', '2026-01-01 00:34:00');


--
-- Data for Name: v_upload_parts; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: v_upload_sessions; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_upload_sessions" VALUES
	('01KDX6KQ00000000000000007T', NULL, '01KDX6KQ000000000000000001', '00000000-0000-4000-8000-000000000001', NULL, '01KDX6KQ000000000000000005', 'large.bin', 'tus', 'uploading', NULL, 10, 4, 8388608, 'application/octet-stream', NULL, NULL, NULL, NULL, '2026-01-02 01:16:00', '01ARZ3NDEKTSV4RRFFQ69G5FB0', '2026-01-01 01:16:00', '2026-01-01 01:17:00');


--
-- Data for Name: v_webhook_deliveries; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_webhook_deliveries" VALUES
	('01KDX6KQ000000000000000077', '01KDX6KQ00000000000000006T', 'grant.created', '{"grant_id":"01KDX6KQ00000000000000006V","node_id":"01KDX6KQ000000000000000005","storage_id":"01KDX6KQ000000000000000001","subject_type":"user","subject_id":"01ARZ3NDEKTSV4RRFFQ69G5FB1","level":"writer","effect":"allow","scope":"subtree","event_id":"grant.created:01KDX6KQ00000000000000006V","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', 'delivered', 1, NULL, NULL, '2026-01-01 01:08:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000078', '01KDX6KQ00000000000000006T', 'grant.created', '{"grant_id":"01KDX6KQ00000000000000006Y","node_id":"01KDX6KQ000000000000000005","storage_id":"01KDX6KQ000000000000000001","subject_type":"role","subject_id":"01ARZ3NDEKTSV4RRFFQ69G5FB3","level":"writer","effect":"allow","scope":"subtree","event_id":"grant.created:01KDX6KQ00000000000000006Y","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', 'delivered', 1, NULL, NULL, '2026-01-01 01:08:00', '2026-01-01 01:08:00'),
	('01KDX6KQ000000000000000079', '01KDX6KQ00000000000000006T', 'grant.created', '{"grant_id":"01KDX6KQ000000000000000071","node_id":"01KDX6KQ000000000000000005","storage_id":"01KDX6KQ000000000000000001","subject_type":"user","subject_id":"01ARZ3NDEKTSV4RRFFQ69G5FB2","level":"writer","effect":"allow","scope":"subtree","event_id":"grant.created:01KDX6KQ000000000000000071","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', 'delivered', 1, NULL, NULL, '2026-01-01 01:08:00', '2026-01-01 01:08:00'),
	('01KDX6KQ00000000000000007A', '01KDX6KQ00000000000000006T', 'grant.created', '{"grant_id":"01KDX6KQ000000000000000074","node_id":"01KDX6KQ000000000000000005","storage_id":"01KDX6KQ000000000000000001","subject_type":"role","subject_id":"01ARZ3NDEKTSV4RRFFQ69G5FB4","level":"writer","effect":"allow","scope":"subtree","event_id":"grant.created:01KDX6KQ000000000000000074","actor_id":"01ARZ3NDEKTSV4RRFFQ69G5FB0"}', 'delivered', 1, NULL, NULL, '2026-01-01 01:08:00', '2026-01-01 01:08:00');


--
-- Data for Name: v_webhook_endpoints; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_webhook_endpoints" VALUES
	('01KDX6KQ00000000000000006T', 'https://hooks.fixture.test/vaulter', '["grant.created","grant.revoked","share.created"]', 'eyJpdiI6IlV2QXpXTnpDVjhBQTNsQ2wrR2ZDWGc9PSIsInZhbHVlIjoiTmVkblQ0WmxGU2VQL1Jocmd1YzF3ald3TlZaZnRFVWNTUkpQRkh1S2tndXlzY2tTd2RHOTFRVENsK3VLSmpXdk5kZFVYd1YybXFJbWVGSnVBSWRvY0xsSkx0VnpOb3BnY3p2eXlqNXBINXc9IiwibWFjIjoiMWM4OTRhMDA4NjI5NzFmZTQyMzUzMWQyMTI0ZDU0YzI5ODYxNDg4YWVjNWQwYjY0MTFlZmI2NTJjNzJjNWMxZiIsInRhZyI6IiJ9', true, '2026-01-01 01:08:00', 0, '2026-01-01 01:03:00', '2026-01-01 01:08:00');


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."migrations_id_seq"', 19, true);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."migrations"
    ADD CONSTRAINT "migrations_pkey" PRIMARY KEY ("id");


--
-- Name: v_activity v_activity_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_activity"
    ADD CONSTRAINT "v_activity_pkey" PRIMARY KEY ("id");


--
-- Name: v_blobs v_blobs_dedup_uniq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_blobs"
    ADD CONSTRAINT "v_blobs_dedup_uniq" UNIQUE ("storage_id", "sha256");


--
-- Name: v_blobs v_blobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_blobs"
    ADD CONSTRAINT "v_blobs_pkey" PRIMARY KEY ("id");


--
-- Name: v_blobs v_blobs_storage_key_uniq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_blobs"
    ADD CONSTRAINT "v_blobs_storage_key_uniq" UNIQUE ("storage_key");


--
-- Name: v_file_version_blobs v_file_version_blobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_file_version_blobs"
    ADD CONSTRAINT "v_file_version_blobs_pkey" PRIMARY KEY ("file_version_id", "blob_id", "role", "ordinal");


--
-- Name: v_file_versions v_file_versions_no_uniq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_file_versions"
    ADD CONSTRAINT "v_file_versions_no_uniq" UNIQUE ("file_id", "version_no");


--
-- Name: v_file_versions v_file_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_file_versions"
    ADD CONSTRAINT "v_file_versions_pkey" PRIMARY KEY ("id");


--
-- Name: v_files v_files_node_uniq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_files"
    ADD CONSTRAINT "v_files_node_uniq" UNIQUE ("node_id");


--
-- Name: v_files v_files_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_files"
    ADD CONSTRAINT "v_files_pkey" PRIMARY KEY ("id");


--
-- Name: v_node_comments v_node_comments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_node_comments"
    ADD CONSTRAINT "v_node_comments_pkey" PRIMARY KEY ("id");


--
-- Name: v_node_document_versions v_node_document_versions_no_uniq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_node_document_versions"
    ADD CONSTRAINT "v_node_document_versions_no_uniq" UNIQUE ("node_id", "version_no");


--
-- Name: v_node_document_versions v_node_document_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_node_document_versions"
    ADD CONSTRAINT "v_node_document_versions_pkey" PRIMARY KEY ("id");


--
-- Name: v_node_grants v_node_grants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_node_grants"
    ADD CONSTRAINT "v_node_grants_pkey" PRIMARY KEY ("id");


--
-- Name: v_node_shares v_node_shares_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_node_shares"
    ADD CONSTRAINT "v_node_shares_pkey" PRIMARY KEY ("id");


--
-- Name: v_node_shares v_node_shares_token_hash_uniq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_node_shares"
    ADD CONSTRAINT "v_node_shares_token_hash_uniq" UNIQUE ("token_hash");


--
-- Name: v_nodes v_nodes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_nodes"
    ADD CONSTRAINT "v_nodes_pkey" PRIMARY KEY ("id");


--
-- Name: v_outbox v_outbox_event_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_outbox"
    ADD CONSTRAINT "v_outbox_event_id_unique" UNIQUE ("event_id");


--
-- Name: v_outbox v_outbox_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_outbox"
    ADD CONSTRAINT "v_outbox_pkey" PRIMARY KEY ("id");


--
-- Name: v_storages v_storages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_storages"
    ADD CONSTRAINT "v_storages_pkey" PRIMARY KEY ("id");


--
-- Name: v_upload_parts v_upload_parts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_upload_parts"
    ADD CONSTRAINT "v_upload_parts_pkey" PRIMARY KEY ("upload_session_id", "part_no");


--
-- Name: v_upload_sessions v_upload_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_upload_sessions"
    ADD CONSTRAINT "v_upload_sessions_pkey" PRIMARY KEY ("id");


--
-- Name: v_upload_sessions v_upload_sessions_upload_id_uniq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_upload_sessions"
    ADD CONSTRAINT "v_upload_sessions_upload_id_uniq" UNIQUE ("upload_id");


--
-- Name: v_webhook_deliveries v_webhook_deliveries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_webhook_deliveries"
    ADD CONSTRAINT "v_webhook_deliveries_pkey" PRIMARY KEY ("id");


--
-- Name: v_webhook_endpoints v_webhook_endpoints_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_webhook_endpoints"
    ADD CONSTRAINT "v_webhook_endpoints_pkey" PRIMARY KEY ("id");


--
-- Name: v_activity_actor_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_activity_actor_idx" ON "public"."v_activity" USING "btree" ("actor_type", "actor_id", "created_at");


--
-- Name: v_activity_context_gin; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_activity_context_gin" ON "public"."v_activity" USING "gin" ("context" "jsonb_path_ops");


--
-- Name: v_activity_created_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_activity_created_idx" ON "public"."v_activity" USING "btree" ("created_at");


--
-- Name: v_activity_storage_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_activity_storage_id_index" ON "public"."v_activity" USING "btree" ("storage_id");


--
-- Name: v_activity_storage_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_activity_storage_idx" ON "public"."v_activity" USING "btree" ("storage_id", "created_at");


--
-- Name: v_activity_target_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_activity_target_id_index" ON "public"."v_activity" USING "btree" ("target_id");


--
-- Name: v_activity_target_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_activity_target_idx" ON "public"."v_activity" USING "btree" ("target_type", "target_id", "created_at");


--
-- Name: v_activity_tenant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_activity_tenant_id_index" ON "public"."v_activity" USING "btree" ("tenant_id");


--
-- Name: v_activity_verb_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_activity_verb_idx" ON "public"."v_activity" USING "btree" ("verb", "created_at");


--
-- Name: v_activity_verb_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_activity_verb_index" ON "public"."v_activity" USING "btree" ("verb");


--
-- Name: v_blobs_encryption_key_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_blobs_encryption_key_id_index" ON "public"."v_blobs" USING "btree" ("encryption_key_id");


--
-- Name: v_blobs_gc_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_blobs_gc_idx" ON "public"."v_blobs" USING "btree" ("status", "ref_count", "delete_after_at");


--
-- Name: v_blobs_size_bytes_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_blobs_size_bytes_index" ON "public"."v_blobs" USING "btree" ("size_bytes");


--
-- Name: v_blobs_storage_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_blobs_storage_id_index" ON "public"."v_blobs" USING "btree" ("storage_id");


--
-- Name: v_blobs_tenant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_blobs_tenant_id_index" ON "public"."v_blobs" USING "btree" ("tenant_id");


--
-- Name: v_file_version_blobs_blob_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_file_version_blobs_blob_id_index" ON "public"."v_file_version_blobs" USING "btree" ("blob_id");


--
-- Name: v_file_version_blobs_file_version_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_file_version_blobs_file_version_id_index" ON "public"."v_file_version_blobs" USING "btree" ("file_version_id");


--
-- Name: v_file_versions_blob_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_file_versions_blob_id_index" ON "public"."v_file_versions" USING "btree" ("blob_id");


--
-- Name: v_file_versions_content_sha256_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_file_versions_content_sha256_index" ON "public"."v_file_versions" USING "btree" ("content_sha256");


--
-- Name: v_file_versions_file_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_file_versions_file_id_index" ON "public"."v_file_versions" USING "btree" ("file_id");


--
-- Name: v_file_versions_file_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_file_versions_file_id_status_index" ON "public"."v_file_versions" USING "btree" ("file_id", "status");


--
-- Name: v_file_versions_manifest_blob_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_file_versions_manifest_blob_id_index" ON "public"."v_file_versions" USING "btree" ("manifest_blob_id");


--
-- Name: v_file_versions_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_file_versions_status_index" ON "public"."v_file_versions" USING "btree" ("status");


--
-- Name: v_file_versions_tenant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_file_versions_tenant_id_index" ON "public"."v_file_versions" USING "btree" ("tenant_id");


--
-- Name: v_files_current_version_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_files_current_version_id_index" ON "public"."v_files" USING "btree" ("current_version_id");


--
-- Name: v_files_node_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_files_node_id_index" ON "public"."v_files" USING "btree" ("node_id");


--
-- Name: v_files_tenant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_files_tenant_id_index" ON "public"."v_files" USING "btree" ("tenant_id");


--
-- Name: v_node_comments_author_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_comments_author_id_index" ON "public"."v_node_comments" USING "btree" ("author_id");


--
-- Name: v_node_comments_node_created_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_comments_node_created_idx" ON "public"."v_node_comments" USING "btree" ("node_id", "created_at");


--
-- Name: v_node_comments_node_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_comments_node_id_index" ON "public"."v_node_comments" USING "btree" ("node_id");


--
-- Name: v_node_comments_parent_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_comments_parent_id_index" ON "public"."v_node_comments" USING "btree" ("parent_id");


--
-- Name: v_node_comments_parent_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_comments_parent_idx" ON "public"."v_node_comments" USING "btree" ("parent_id");


--
-- Name: v_node_comments_tenant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_comments_tenant_id_index" ON "public"."v_node_comments" USING "btree" ("tenant_id");


--
-- Name: v_node_document_versions_content_gin; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_document_versions_content_gin" ON "public"."v_node_document_versions" USING "gin" ("content" "jsonb_path_ops");


--
-- Name: v_node_document_versions_history_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_document_versions_history_idx" ON "public"."v_node_document_versions" USING "btree" ("node_id", "is_autosave", "created_at");


--
-- Name: v_node_document_versions_node_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_document_versions_node_id_index" ON "public"."v_node_document_versions" USING "btree" ("node_id");


--
-- Name: v_node_document_versions_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_document_versions_status_index" ON "public"."v_node_document_versions" USING "btree" ("status");


--
-- Name: v_node_document_versions_tenant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_document_versions_tenant_id_index" ON "public"."v_node_document_versions" USING "btree" ("tenant_id");


--
-- Name: v_node_grants_effect_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_grants_effect_index" ON "public"."v_node_grants" USING "btree" ("effect");


--
-- Name: v_node_grants_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_grants_expires_at_index" ON "public"."v_node_grants" USING "btree" ("expires_at");


--
-- Name: v_node_grants_grant_uniq; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX "v_node_grants_grant_uniq" ON "public"."v_node_grants" USING "btree" ("node_id", "subject_type", "subject_id", "scope", "effect") WHERE ("deleted_at" IS NULL);


--
-- Name: v_node_grants_inherited_from_node_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_grants_inherited_from_node_id_index" ON "public"."v_node_grants" USING "btree" ("inherited_from_node_id");


--
-- Name: v_node_grants_inherited_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_grants_inherited_index" ON "public"."v_node_grants" USING "btree" ("inherited");


--
-- Name: v_node_grants_level_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_grants_level_index" ON "public"."v_node_grants" USING "btree" ("level");


--
-- Name: v_node_grants_node_effect_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_grants_node_effect_idx" ON "public"."v_node_grants" USING "btree" ("node_id", "effect");


--
-- Name: v_node_grants_node_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_grants_node_id_index" ON "public"."v_node_grants" USING "btree" ("node_id");


--
-- Name: v_node_grants_subject_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_grants_subject_id_index" ON "public"."v_node_grants" USING "btree" ("subject_id");


--
-- Name: v_node_grants_subject_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_grants_subject_idx" ON "public"."v_node_grants" USING "btree" ("subject_type", "subject_id");


--
-- Name: v_node_grants_tenant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_grants_tenant_id_index" ON "public"."v_node_grants" USING "btree" ("tenant_id");


--
-- Name: v_node_shares_expiry_live_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_shares_expiry_live_idx" ON "public"."v_node_shares" USING "btree" ("expires_at") WHERE ("revoked_at" IS NULL);


--
-- Name: v_node_shares_node_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_shares_node_id_index" ON "public"."v_node_shares" USING "btree" ("node_id");


--
-- Name: v_node_shares_node_revoked_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_shares_node_revoked_idx" ON "public"."v_node_shares" USING "btree" ("node_id", "revoked_at");


--
-- Name: v_node_shares_revoked_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_shares_revoked_at_index" ON "public"."v_node_shares" USING "btree" ("revoked_at");


--
-- Name: v_node_shares_tenant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_shares_tenant_id_index" ON "public"."v_node_shares" USING "btree" ("tenant_id");


--
-- Name: v_node_shares_token_prefix_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_node_shares_token_prefix_index" ON "public"."v_node_shares" USING "btree" ("token_prefix");


--
-- Name: v_nodes_current_version_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_nodes_current_version_id_index" ON "public"."v_nodes" USING "btree" ("current_version_id");


--
-- Name: v_nodes_file_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_nodes_file_id_index" ON "public"."v_nodes" USING "btree" ("file_id");


--
-- Name: v_nodes_kind_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_nodes_kind_index" ON "public"."v_nodes" USING "btree" ("kind");


--
-- Name: v_nodes_mime_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_nodes_mime_type_index" ON "public"."v_nodes" USING "btree" ("mime_type");


--
-- Name: v_nodes_panel_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_nodes_panel_index" ON "public"."v_nodes" USING "btree" ("panel");


--
-- Name: v_nodes_parent_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_nodes_parent_id_index" ON "public"."v_nodes" USING "btree" ("parent_id");


--
-- Name: v_nodes_shortcut_target_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_nodes_shortcut_target_id_index" ON "public"."v_nodes" USING "btree" ("shortcut_target_id");


--
-- Name: v_nodes_sibling_uniq; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX "v_nodes_sibling_uniq" ON "public"."v_nodes" USING "btree" ("parent_id", "lower"(("name")::"text")) WHERE (("deleted_at" IS NULL) AND ("trashed_at" IS NULL));


--
-- Name: v_nodes_storage_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_nodes_storage_id_index" ON "public"."v_nodes" USING "btree" ("storage_id");


--
-- Name: v_nodes_storage_id_kind_trashed_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_nodes_storage_id_kind_trashed_at_index" ON "public"."v_nodes" USING "btree" ("storage_id", "kind", "trashed_at");


--
-- Name: v_nodes_storage_id_parent_id_position_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_nodes_storage_id_parent_id_position_index" ON "public"."v_nodes" USING "btree" ("storage_id", "parent_id", "position");


--
-- Name: v_nodes_tenant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_nodes_tenant_id_index" ON "public"."v_nodes" USING "btree" ("tenant_id");


--
-- Name: v_nodes_trashed_from_parent_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_nodes_trashed_from_parent_id_index" ON "public"."v_nodes" USING "btree" ("trashed_from_parent_id");


--
-- Name: v_nodes_trashed_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_nodes_trashed_idx" ON "public"."v_nodes" USING "btree" ("trashed_at") WHERE ("trashed_at" IS NOT NULL);


--
-- Name: v_outbox_drain_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_outbox_drain_idx" ON "public"."v_outbox" USING "btree" ("status", "available_at");


--
-- Name: v_outbox_panel_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_outbox_panel_index" ON "public"."v_outbox" USING "btree" ("panel");


--
-- Name: v_outbox_prune_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_outbox_prune_idx" ON "public"."v_outbox" USING "btree" ("status", "dispatched_at");


--
-- Name: v_storages_owner_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_storages_owner_id_index" ON "public"."v_storages" USING "btree" ("owner_id");


--
-- Name: v_storages_owner_panel_uniq; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX "v_storages_owner_panel_uniq" ON "public"."v_storages" USING "btree" ("owner_type", "owner_id", "panel") WHERE ("deleted_at" IS NULL);


--
-- Name: v_storages_owner_type_owner_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_storages_owner_type_owner_id_index" ON "public"."v_storages" USING "btree" ("owner_type", "owner_id");


--
-- Name: v_storages_panel_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_storages_panel_index" ON "public"."v_storages" USING "btree" ("panel");


--
-- Name: v_storages_panel_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_storages_panel_status_index" ON "public"."v_storages" USING "btree" ("panel", "status");


--
-- Name: v_storages_root_node_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_storages_root_node_id_index" ON "public"."v_storages" USING "btree" ("root_node_id");


--
-- Name: v_storages_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_storages_status_index" ON "public"."v_storages" USING "btree" ("status");


--
-- Name: v_storages_storage_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_storages_storage_type_index" ON "public"."v_storages" USING "btree" ("storage_type");


--
-- Name: v_storages_tenant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_storages_tenant_id_index" ON "public"."v_storages" USING "btree" ("tenant_id");


--
-- Name: v_upload_parts_temp_blob_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_upload_parts_temp_blob_id_index" ON "public"."v_upload_parts" USING "btree" ("temp_blob_id");


--
-- Name: v_upload_parts_upload_session_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_upload_parts_upload_session_id_index" ON "public"."v_upload_parts" USING "btree" ("upload_session_id");


--
-- Name: v_upload_sessions_assembled_blob_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_upload_sessions_assembled_blob_id_index" ON "public"."v_upload_sessions" USING "btree" ("assembled_blob_id");


--
-- Name: v_upload_sessions_node_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_upload_sessions_node_id_index" ON "public"."v_upload_sessions" USING "btree" ("node_id");


--
-- Name: v_upload_sessions_storage_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_upload_sessions_storage_id_index" ON "public"."v_upload_sessions" USING "btree" ("storage_id");


--
-- Name: v_upload_sessions_storage_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_upload_sessions_storage_idx" ON "public"."v_upload_sessions" USING "btree" ("storage_id");


--
-- Name: v_upload_sessions_sweeper_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_upload_sessions_sweeper_idx" ON "public"."v_upload_sessions" USING "btree" ("status", "expires_at");


--
-- Name: v_upload_sessions_target_parent_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_upload_sessions_target_parent_id_index" ON "public"."v_upload_sessions" USING "btree" ("target_parent_id");


--
-- Name: v_upload_sessions_tenant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_upload_sessions_tenant_id_index" ON "public"."v_upload_sessions" USING "btree" ("tenant_id");


--
-- Name: v_webhook_deliveries_endpoint_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_webhook_deliveries_endpoint_id_index" ON "public"."v_webhook_deliveries" USING "btree" ("endpoint_id");


--
-- Name: v_webhook_deliveries_prune_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_webhook_deliveries_prune_idx" ON "public"."v_webhook_deliveries" USING "btree" ("status", "updated_at");


--
-- Name: v_webhook_deliveries_retry_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_webhook_deliveries_retry_idx" ON "public"."v_webhook_deliveries" USING "btree" ("status", "next_retry_at");


--
-- Name: v_webhook_endpoints_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "v_webhook_endpoints_is_active_index" ON "public"."v_webhook_endpoints" USING "btree" ("is_active");


--
-- Name: v_file_version_blobs v_file_version_blobs_blob_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_file_version_blobs"
    ADD CONSTRAINT "v_file_version_blobs_blob_id_foreign" FOREIGN KEY ("blob_id") REFERENCES "public"."v_blobs"("id") ON DELETE CASCADE;


--
-- Name: v_file_version_blobs v_file_version_blobs_file_version_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_file_version_blobs"
    ADD CONSTRAINT "v_file_version_blobs_file_version_id_foreign" FOREIGN KEY ("file_version_id") REFERENCES "public"."v_file_versions"("id") ON DELETE CASCADE;


--
-- Name: v_file_versions v_file_versions_file_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_file_versions"
    ADD CONSTRAINT "v_file_versions_file_id_foreign" FOREIGN KEY ("file_id") REFERENCES "public"."v_files"("id") ON DELETE CASCADE;


--
-- Name: v_files v_files_node_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_files"
    ADD CONSTRAINT "v_files_node_id_foreign" FOREIGN KEY ("node_id") REFERENCES "public"."v_nodes"("id") ON DELETE CASCADE;


--
-- Name: v_node_comments v_node_comments_node_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_node_comments"
    ADD CONSTRAINT "v_node_comments_node_id_foreign" FOREIGN KEY ("node_id") REFERENCES "public"."v_nodes"("id") ON DELETE CASCADE;


--
-- Name: v_node_comments v_node_comments_parent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_node_comments"
    ADD CONSTRAINT "v_node_comments_parent_id_foreign" FOREIGN KEY ("parent_id") REFERENCES "public"."v_node_comments"("id") ON DELETE CASCADE;


--
-- Name: v_node_document_versions v_node_document_versions_node_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_node_document_versions"
    ADD CONSTRAINT "v_node_document_versions_node_id_foreign" FOREIGN KEY ("node_id") REFERENCES "public"."v_nodes"("id") ON DELETE CASCADE;


--
-- Name: v_node_grants v_node_grants_node_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_node_grants"
    ADD CONSTRAINT "v_node_grants_node_id_foreign" FOREIGN KEY ("node_id") REFERENCES "public"."v_nodes"("id") ON DELETE CASCADE;


--
-- Name: v_node_shares v_node_shares_node_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_node_shares"
    ADD CONSTRAINT "v_node_shares_node_id_foreign" FOREIGN KEY ("node_id") REFERENCES "public"."v_nodes"("id") ON DELETE CASCADE;


--
-- Name: v_nodes v_nodes_parent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_nodes"
    ADD CONSTRAINT "v_nodes_parent_id_foreign" FOREIGN KEY ("parent_id") REFERENCES "public"."v_nodes"("id") ON DELETE CASCADE;


--
-- Name: v_nodes v_nodes_storage_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_nodes"
    ADD CONSTRAINT "v_nodes_storage_id_foreign" FOREIGN KEY ("storage_id") REFERENCES "public"."v_storages"("id") ON DELETE CASCADE;


--
-- Name: v_upload_parts v_upload_parts_upload_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_upload_parts"
    ADD CONSTRAINT "v_upload_parts_upload_session_id_foreign" FOREIGN KEY ("upload_session_id") REFERENCES "public"."v_upload_sessions"("id") ON DELETE CASCADE;


--
-- Name: v_upload_sessions v_upload_sessions_storage_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_upload_sessions"
    ADD CONSTRAINT "v_upload_sessions_storage_id_foreign" FOREIGN KEY ("storage_id") REFERENCES "public"."v_storages"("id") ON DELETE CASCADE;


--
-- Name: v_webhook_deliveries v_webhook_deliveries_endpoint_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_webhook_deliveries"
    ADD CONSTRAINT "v_webhook_deliveries_endpoint_id_foreign" FOREIGN KEY ("endpoint_id") REFERENCES "public"."v_webhook_endpoints"("id") ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict vaulterfixturev020

