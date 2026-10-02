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
    "id" bigint NOT NULL,
    "tenant_id" bigint,
    "storage_id" bigint,
    "actor_id" bigint,
    "actor_type" character varying(255),
    "verb" character varying(255) NOT NULL,
    "target_id" bigint,
    "target_type" character varying(255),
    "context" "jsonb",
    "ip" "inet",
    "user_agent" "text",
    "request_id" "uuid",
    "extra_data" "json",
    "created_at" timestamp(0) without time zone
);


--
-- Name: v_activity_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE "public"."v_activity_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: v_activity_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE "public"."v_activity_id_seq" OWNED BY "public"."v_activity"."id";


--
-- Name: v_blobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_blobs" (
    "id" bigint NOT NULL,
    "tenant_id" bigint,
    "storage_id" bigint,
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
    "encryption_key_id" bigint,
    "first_seen_at" timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "committed_at" timestamp(0) without time zone,
    "delete_after_at" timestamp(0) without time zone,
    "extra_data" "json"
);


--
-- Name: v_blobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE "public"."v_blobs_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: v_blobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE "public"."v_blobs_id_seq" OWNED BY "public"."v_blobs"."id";


--
-- Name: v_file_version_blobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_file_version_blobs" (
    "file_version_id" bigint NOT NULL,
    "blob_id" bigint NOT NULL,
    "role" character varying(12) DEFAULT 'data'::character varying NOT NULL,
    "ordinal" integer DEFAULT 0 NOT NULL,
    "offset_bytes" bigint,
    "length_bytes" bigint
);


--
-- Name: v_file_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_file_versions" (
    "id" bigint NOT NULL,
    "tenant_id" bigint,
    "file_id" bigint NOT NULL,
    "version_no" integer NOT NULL,
    "blob_id" bigint,
    "manifest_blob_id" bigint,
    "content_sha256" character(64) NOT NULL,
    "size_bytes" bigint NOT NULL,
    "mime_type" character varying(191),
    "original_name" character varying(255) NOT NULL,
    "status" character varying(12) DEFAULT 'pending'::character varying NOT NULL,
    "keep_forever" boolean DEFAULT false NOT NULL,
    "extra_data" "json",
    "created_by" bigint,
    "created_at" timestamp(0) without time zone,
    "committed_at" timestamp(0) without time zone
);


--
-- Name: v_file_versions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE "public"."v_file_versions_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: v_file_versions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE "public"."v_file_versions_id_seq" OWNED BY "public"."v_file_versions"."id";


--
-- Name: v_files; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_files" (
    "id" bigint NOT NULL,
    "tenant_id" bigint,
    "node_id" bigint NOT NULL,
    "current_version_id" bigint,
    "mime_type" character varying(191),
    "size_bytes" bigint DEFAULT '0'::bigint NOT NULL,
    "version_count" integer DEFAULT 0 NOT NULL,
    "extra_data" "json",
    "created_by" bigint,
    "deleted_at" timestamp(0) without time zone,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


--
-- Name: v_files_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE "public"."v_files_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: v_files_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE "public"."v_files_id_seq" OWNED BY "public"."v_files"."id";


--
-- Name: v_node_comments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_node_comments" (
    "id" bigint NOT NULL,
    "tenant_id" bigint,
    "node_id" bigint NOT NULL,
    "parent_id" bigint,
    "author_id" bigint NOT NULL,
    "author_type" character varying(255) NOT NULL,
    "body" "text" NOT NULL,
    "anchor" "jsonb",
    "resolved_at" timestamp(0) without time zone,
    "resolved_by" bigint,
    "extra_data" "json",
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


--
-- Name: v_node_comments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE "public"."v_node_comments_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: v_node_comments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE "public"."v_node_comments_id_seq" OWNED BY "public"."v_node_comments"."id";


--
-- Name: v_node_document_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_node_document_versions" (
    "id" bigint NOT NULL,
    "tenant_id" bigint,
    "node_id" bigint NOT NULL,
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
    "created_by" bigint,
    "created_at" timestamp(0) without time zone
);


--
-- Name: v_node_document_versions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE "public"."v_node_document_versions_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: v_node_document_versions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE "public"."v_node_document_versions_id_seq" OWNED BY "public"."v_node_document_versions"."id";


--
-- Name: v_node_grants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_node_grants" (
    "id" bigint NOT NULL,
    "tenant_id" bigint,
    "node_id" bigint NOT NULL,
    "subject_id" bigint NOT NULL,
    "subject_type" character varying(255) NOT NULL,
    "level" character varying(16) NOT NULL,
    "permissions" integer DEFAULT 0 NOT NULL,
    "effect" character varying(5) DEFAULT 'allow'::character varying NOT NULL,
    "scope" character varying(8) DEFAULT 'subtree'::character varying NOT NULL,
    "inherited" boolean DEFAULT false NOT NULL,
    "inherited_from_node_id" bigint,
    "expires_at" timestamp(0) without time zone,
    "granted_by" bigint,
    "extra_data" "json",
    "deleted_at" timestamp(0) without time zone,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


--
-- Name: v_node_grants_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE "public"."v_node_grants_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: v_node_grants_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE "public"."v_node_grants_id_seq" OWNED BY "public"."v_node_grants"."id";


--
-- Name: v_node_shares; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_node_shares" (
    "id" bigint NOT NULL,
    "tenant_id" bigint,
    "node_id" bigint NOT NULL,
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
    "granted_by" bigint,
    "extra_data" "json",
    "deleted_at" timestamp(0) without time zone,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


--
-- Name: v_node_shares_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE "public"."v_node_shares_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: v_node_shares_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE "public"."v_node_shares_id_seq" OWNED BY "public"."v_node_shares"."id";


--
-- Name: v_nodes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_nodes" (
    "id" bigint NOT NULL,
    "panel" character varying(255) DEFAULT 'default'::character varying NOT NULL,
    "tenant_id" bigint,
    "storage_id" bigint NOT NULL,
    "parent_id" bigint,
    "kind" character varying(16) NOT NULL,
    "name" character varying(255) NOT NULL,
    "mime_type" character varying(191),
    "extension" character varying(32),
    "size_bytes" bigint DEFAULT '0'::bigint NOT NULL,
    "current_version_id" bigint,
    "file_id" bigint,
    "position" integer DEFAULT 0 NOT NULL,
    "is_pinned" boolean DEFAULT false NOT NULL,
    "inherit_grants" boolean DEFAULT true NOT NULL,
    "shortcut_target_id" bigint,
    "owner_id" bigint,
    "extra_data" "json",
    "trashed_at" timestamp(0) without time zone,
    "trashed_by" bigint,
    "trashed_from_parent_id" bigint,
    "created_by" bigint,
    "updated_by" bigint,
    "deleted_at" timestamp(0) without time zone,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


--
-- Name: v_nodes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE "public"."v_nodes_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: v_nodes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE "public"."v_nodes_id_seq" OWNED BY "public"."v_nodes"."id";


--
-- Name: v_outbox; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_outbox" (
    "id" bigint NOT NULL,
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
-- Name: v_outbox_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE "public"."v_outbox_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: v_outbox_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE "public"."v_outbox_id_seq" OWNED BY "public"."v_outbox"."id";


--
-- Name: v_storages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_storages" (
    "id" bigint NOT NULL,
    "panel" character varying(255) DEFAULT 'default'::character varying NOT NULL,
    "tenant_id" bigint,
    "owner_id" bigint NOT NULL,
    "owner_type" character varying(255) NOT NULL,
    "name" character varying(255) NOT NULL,
    "slug" character varying(160),
    "root_node_id" bigint,
    "disk" character varying(64) DEFAULT 'default'::character varying NOT NULL,
    "storage_type" character varying(32) DEFAULT 'private'::character varying NOT NULL,
    "quota_bytes" bigint,
    "used_bytes" bigint DEFAULT '0'::bigint NOT NULL,
    "status" character varying(16) DEFAULT 'active'::character varying NOT NULL,
    "default_node_permissions" integer DEFAULT 31 NOT NULL,
    "extra_data" "json",
    "created_by" bigint,
    "deleted_at" timestamp(0) without time zone,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


--
-- Name: v_storages_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE "public"."v_storages_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: v_storages_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE "public"."v_storages_id_seq" OWNED BY "public"."v_storages"."id";


--
-- Name: v_upload_parts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_upload_parts" (
    "upload_session_id" bigint NOT NULL,
    "part_no" integer NOT NULL,
    "byte_offset" bigint DEFAULT '0'::bigint NOT NULL,
    "size_bytes" bigint DEFAULT '0'::bigint NOT NULL,
    "part_sha256" character(64),
    "etag" character varying(255),
    "temp_blob_id" bigint,
    "received_at" timestamp(0) without time zone
);


--
-- Name: v_upload_sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_upload_sessions" (
    "id" bigint NOT NULL,
    "tenant_id" bigint,
    "storage_id" bigint NOT NULL,
    "upload_id" "uuid" NOT NULL,
    "node_id" bigint,
    "target_parent_id" bigint,
    "target_name" character varying(255),
    "protocol" character varying(16) DEFAULT 'tus'::character varying NOT NULL,
    "status" character varying(16) DEFAULT 'initiated'::character varying NOT NULL,
    "expected_sha256" character(64),
    "total_size_bytes" bigint,
    "received_bytes" bigint DEFAULT '0'::bigint NOT NULL,
    "chunk_size" bigint,
    "mime_type" character varying(191),
    "parts" "json",
    "assembled_blob_id" bigint,
    "metadata" "json",
    "extra_data" "json",
    "expires_at" timestamp(0) without time zone,
    "created_by" bigint,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


--
-- Name: v_upload_sessions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE "public"."v_upload_sessions_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: v_upload_sessions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE "public"."v_upload_sessions_id_seq" OWNED BY "public"."v_upload_sessions"."id";


--
-- Name: v_webhook_deliveries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_webhook_deliveries" (
    "id" bigint NOT NULL,
    "endpoint_id" bigint NOT NULL,
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
-- Name: v_webhook_deliveries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE "public"."v_webhook_deliveries_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: v_webhook_deliveries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE "public"."v_webhook_deliveries_id_seq" OWNED BY "public"."v_webhook_deliveries"."id";


--
-- Name: v_webhook_endpoints; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."v_webhook_endpoints" (
    "id" bigint NOT NULL,
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
-- Name: v_webhook_endpoints_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE "public"."v_webhook_endpoints_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: v_webhook_endpoints_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE "public"."v_webhook_endpoints_id_seq" OWNED BY "public"."v_webhook_endpoints"."id";


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."migrations" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."migrations_id_seq"'::"regclass");


--
-- Name: v_activity id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_activity" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."v_activity_id_seq"'::"regclass");


--
-- Name: v_blobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_blobs" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."v_blobs_id_seq"'::"regclass");


--
-- Name: v_file_versions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_file_versions" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."v_file_versions_id_seq"'::"regclass");


--
-- Name: v_files id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_files" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."v_files_id_seq"'::"regclass");


--
-- Name: v_node_comments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_node_comments" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."v_node_comments_id_seq"'::"regclass");


--
-- Name: v_node_document_versions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_node_document_versions" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."v_node_document_versions_id_seq"'::"regclass");


--
-- Name: v_node_grants id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_node_grants" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."v_node_grants_id_seq"'::"regclass");


--
-- Name: v_node_shares id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_node_shares" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."v_node_shares_id_seq"'::"regclass");


--
-- Name: v_nodes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_nodes" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."v_nodes_id_seq"'::"regclass");


--
-- Name: v_outbox id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_outbox" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."v_outbox_id_seq"'::"regclass");


--
-- Name: v_storages id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_storages" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."v_storages_id_seq"'::"regclass");


--
-- Name: v_upload_sessions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_upload_sessions" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."v_upload_sessions_id_seq"'::"regclass");


--
-- Name: v_webhook_deliveries id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_webhook_deliveries" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."v_webhook_deliveries_id_seq"'::"regclass");


--
-- Name: v_webhook_endpoints id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."v_webhook_endpoints" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."v_webhook_endpoints_id_seq"'::"regclass");


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
	(1, NULL, 1, NULL, NULL, 'storage.created', 1, 'Vaulter\Models\Drive\Node', '{"name": "FixtureV020Host storage", "owner_id": "1", "owner_type": "fixture_host", "storage_id": "1"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:00:00'),
	(2, NULL, 1, 1001, NULL, 'node.created', 2, 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Projects", "parent_id": "1"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:01:00'),
	(3, NULL, 1, 1001, NULL, 'node.created', 3, 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Reports", "parent_id": "2"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:02:00'),
	(4, NULL, 1, 1001, NULL, 'node.created', 4, 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Archive", "parent_id": "1"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:03:00'),
	(5, NULL, 1, 1001, NULL, 'node.created', 5, 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "2025", "parent_id": "4"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:04:00'),
	(6, NULL, 1, 1001, NULL, 'file.uploaded', 6, 'Vaulter\Models\Drive\Node', '{"name": "brief.txt", "mime_type": "text/plain", "size_bytes": 19, "version_id": "1"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:05:00'),
	(7, NULL, 1, 1001, NULL, 'file.uploaded', 7, 'Vaulter\Models\Drive\Node', '{"name": "q1.csv", "mime_type": "text/csv", "size_bytes": 16, "version_id": "2"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:06:00'),
	(8, NULL, 1, 1001, NULL, 'version.created', 7, 'Vaulter\Models\Drive\Node', '{"size_bytes": 16, "version_id": "3", "version_no": 2}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:07:00'),
	(9, NULL, 1, 1001, NULL, 'version.created', 7, 'Vaulter\Models\Drive\Node', '{"size_bytes": 16, "version_id": "4", "version_no": 3}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:08:00'),
	(10, NULL, 1, 1001, NULL, 'file.uploaded', 8, 'Vaulter\Models\Drive\Node', '{"name": "old.txt", "mime_type": "text/plain", "size_bytes": 17, "version_id": "5"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:09:00'),
	(11, NULL, 1, 1001, NULL, 'node.created', 9, 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Temp", "parent_id": "1"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:10:00'),
	(12, NULL, 1, 1001, NULL, 'file.uploaded', 10, 'Vaulter\Models\Drive\Node', '{"name": "note.txt", "mime_type": "text/plain", "size_bytes": 21, "version_id": "6"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:11:00'),
	(13, NULL, 1, 1001, NULL, 'node.renamed', 3, 'Vaulter\Models\Drive\Node', '{"to": "Reports 2025", "from": "Reports"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:12:00'),
	(14, NULL, 1, 1001, NULL, 'node.moved', 5, 'Vaulter\Models\Drive\Node', '{"to_parent_id": "2", "from_parent_id": "4"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:13:00'),
	(15, NULL, 1, 1001, NULL, 'node.trashed', 8, 'Vaulter\Models\Drive\Node', '{"name": "old.txt"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:14:00'),
	(16, NULL, 1, 1001, NULL, 'node.restored', 8, 'Vaulter\Models\Drive\Node', '{"name": "old.txt", "parent_id": "4"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:15:00'),
	(17, NULL, 1, 1001, NULL, 'node.trashed', 8, 'Vaulter\Models\Drive\Node', '{"name": "old.txt"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:16:00'),
	(18, NULL, 1, 1001, NULL, 'node.trashed', 9, 'Vaulter\Models\Drive\Node', '{"name": "Temp"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:17:00'),
	(19, NULL, 2, NULL, NULL, 'storage.created', 11, 'Vaulter\Models\Drive\Node', '{"name": "FixtureV020Host storage", "owner_id": "2", "owner_type": "fixture_host", "storage_id": "2"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:17:00'),
	(20, NULL, 2, 1001, NULL, 'node.created', 12, 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Projects", "parent_id": "11"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:18:00'),
	(21, NULL, 2, 1001, NULL, 'node.created', 13, 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Reports", "parent_id": "12"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:19:00'),
	(22, NULL, 2, 1001, NULL, 'node.created', 14, 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Archive", "parent_id": "11"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:20:00'),
	(23, NULL, 2, 1001, NULL, 'node.created', 15, 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "2025", "parent_id": "14"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:21:00'),
	(24, NULL, 2, 1001, NULL, 'file.uploaded', 16, 'Vaulter\Models\Drive\Node', '{"name": "brief.txt", "mime_type": "text/plain", "size_bytes": 19, "version_id": "7"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:22:00'),
	(25, NULL, 2, 1001, NULL, 'file.uploaded', 17, 'Vaulter\Models\Drive\Node', '{"name": "q1.csv", "mime_type": "text/csv", "size_bytes": 16, "version_id": "8"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:23:00'),
	(26, NULL, 2, 1001, NULL, 'version.created', 17, 'Vaulter\Models\Drive\Node', '{"size_bytes": 16, "version_id": "9", "version_no": 2}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:24:00'),
	(27, NULL, 2, 1001, NULL, 'version.created', 17, 'Vaulter\Models\Drive\Node', '{"size_bytes": 16, "version_id": "10", "version_no": 3}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:25:00'),
	(28, NULL, 2, 1001, NULL, 'file.uploaded', 18, 'Vaulter\Models\Drive\Node', '{"name": "old.txt", "mime_type": "text/plain", "size_bytes": 17, "version_id": "11"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:26:00'),
	(29, NULL, 2, 1001, NULL, 'node.created', 19, 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Temp", "parent_id": "11"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:27:00'),
	(30, NULL, 2, 1001, NULL, 'file.uploaded', 20, 'Vaulter\Models\Drive\Node', '{"name": "note.txt", "mime_type": "text/plain", "size_bytes": 21, "version_id": "12"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:28:00'),
	(31, NULL, 2, 1001, NULL, 'node.renamed', 13, 'Vaulter\Models\Drive\Node', '{"to": "Reports 2025", "from": "Reports"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:29:00'),
	(32, NULL, 2, 1001, NULL, 'node.moved', 15, 'Vaulter\Models\Drive\Node', '{"to_parent_id": "12", "from_parent_id": "14"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:30:00'),
	(33, NULL, 2, 1001, NULL, 'node.trashed', 18, 'Vaulter\Models\Drive\Node', '{"name": "old.txt"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:31:00'),
	(34, NULL, 2, 1001, NULL, 'node.restored', 18, 'Vaulter\Models\Drive\Node', '{"name": "old.txt", "parent_id": "14"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:32:00'),
	(35, NULL, 2, 1001, NULL, 'node.trashed', 18, 'Vaulter\Models\Drive\Node', '{"name": "old.txt"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:33:00'),
	(36, NULL, 2, 1001, NULL, 'node.trashed', 19, 'Vaulter\Models\Drive\Node', '{"name": "Temp"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:34:00'),
	(37, NULL, 3, NULL, NULL, 'storage.created', 21, 'Vaulter\Models\Drive\Node', '{"name": "FixtureV020Host storage", "owner_id": "3", "owner_type": "fixture_host", "storage_id": "3"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:34:00'),
	(38, NULL, 3, 1001, NULL, 'node.created', 22, 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Projects", "parent_id": "21"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:35:00'),
	(39, NULL, 3, 1001, NULL, 'node.created', 23, 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Reports", "parent_id": "22"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:36:00'),
	(40, NULL, 3, 1001, NULL, 'node.created', 24, 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Archive", "parent_id": "21"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:37:00'),
	(41, NULL, 3, 1001, NULL, 'node.created', 25, 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "2025", "parent_id": "24"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:38:00'),
	(42, NULL, 3, 1001, NULL, 'file.uploaded', 26, 'Vaulter\Models\Drive\Node', '{"name": "brief.txt", "mime_type": "text/plain", "size_bytes": 19, "version_id": "13"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:39:00'),
	(43, NULL, 3, 1001, NULL, 'file.uploaded', 27, 'Vaulter\Models\Drive\Node', '{"name": "q1.csv", "mime_type": "text/csv", "size_bytes": 16, "version_id": "14"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:40:00'),
	(44, NULL, 3, 1001, NULL, 'version.created', 27, 'Vaulter\Models\Drive\Node', '{"size_bytes": 16, "version_id": "15", "version_no": 2}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:41:00'),
	(45, NULL, 3, 1001, NULL, 'version.created', 27, 'Vaulter\Models\Drive\Node', '{"size_bytes": 16, "version_id": "16", "version_no": 3}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:42:00'),
	(46, NULL, 3, 1001, NULL, 'file.uploaded', 28, 'Vaulter\Models\Drive\Node', '{"name": "old.txt", "mime_type": "text/plain", "size_bytes": 17, "version_id": "17"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:43:00'),
	(47, NULL, 3, 1001, NULL, 'node.created', 29, 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Temp", "parent_id": "21"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:44:00'),
	(48, NULL, 3, 1001, NULL, 'file.uploaded', 30, 'Vaulter\Models\Drive\Node', '{"name": "note.txt", "mime_type": "text/plain", "size_bytes": 21, "version_id": "18"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:45:00'),
	(49, NULL, 3, 1001, NULL, 'node.renamed', 23, 'Vaulter\Models\Drive\Node', '{"to": "Reports 2025", "from": "Reports"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:46:00'),
	(50, NULL, 3, 1001, NULL, 'node.moved', 25, 'Vaulter\Models\Drive\Node', '{"to_parent_id": "22", "from_parent_id": "24"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:47:00'),
	(51, NULL, 3, 1001, NULL, 'node.trashed', 28, 'Vaulter\Models\Drive\Node', '{"name": "old.txt"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:48:00'),
	(52, NULL, 3, 1001, NULL, 'node.restored', 28, 'Vaulter\Models\Drive\Node', '{"name": "old.txt", "parent_id": "24"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:49:00'),
	(53, NULL, 3, 1001, NULL, 'node.trashed', 28, 'Vaulter\Models\Drive\Node', '{"name": "old.txt"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:50:00'),
	(54, NULL, 3, 1001, NULL, 'node.trashed', 29, 'Vaulter\Models\Drive\Node', '{"name": "Temp"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:51:00'),
	(55, NULL, 2, 1001, NULL, 'file.uploaded', 31, 'Vaulter\Models\Drive\Node', '{"name": "shared.bin", "mime_type": "application/octet-stream", "size_bytes": 29, "version_id": "19"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:52:00'),
	(56, NULL, 3, 1001, NULL, 'file.uploaded', 32, 'Vaulter\Models\Drive\Node', '{"name": "shared.bin", "mime_type": "application/octet-stream", "size_bytes": 29, "version_id": "20"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:53:00'),
	(57, NULL, 3, 1001, NULL, 'file.uploaded', 33, 'Vaulter\Models\Drive\Node', '{"name": "tenant.bin", "mime_type": "application/octet-stream", "size_bytes": 33, "version_id": "21"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:54:00'),
	(58, NULL, 1, 1001, NULL, 'file.uploaded', 34, 'Vaulter\Models\Drive\Node', '{"name": "global.bin", "mime_type": "application/octet-stream", "size_bytes": 28, "version_id": "22"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:55:00'),
	(59, NULL, 1, 1001, NULL, 'grant.created', 2, 'Vaulter\Models\Drive\Node', '{"level": "writer", "scope": "subtree", "effect": "allow", "grant_id": "1", "subject_id": "101", "subject_type": "user"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:05:00'),
	(60, NULL, 1, 1001, NULL, 'grant.created', 2, 'Vaulter\Models\Drive\Node', '{"level": "writer", "scope": "subtree", "effect": "allow", "grant_id": "2", "subject_id": "201", "subject_type": "role"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:06:00'),
	(61, NULL, 1, 1001, NULL, 'grant.created', 2, 'Vaulter\Models\Drive\Node', '{"level": "writer", "scope": "subtree", "effect": "allow", "grant_id": "3", "subject_id": "102", "subject_type": "user"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:07:00'),
	(62, NULL, 1, 1001, NULL, 'grant.created', 2, 'Vaulter\Models\Drive\Node', '{"level": "writer", "scope": "subtree", "effect": "allow", "grant_id": "4", "subject_id": "202", "subject_type": "role"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:08:00'),
	(63, NULL, 1, 1001, NULL, 'grant.revoked', 2, 'Vaulter\Models\Drive\Node', '{"revoked": 1, "subject_id": "102", "subject_type": "user"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:10:00'),
	(64, NULL, 1, 1001, NULL, 'grant.revoked', 2, 'Vaulter\Models\Drive\Node', '{"revoked": 1, "subject_id": "202", "subject_type": "role"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:11:00'),
	(65, NULL, 1, 1001, NULL, 'grant.created', 2, 'Vaulter\Models\Drive\Node', '{"level": "reader", "scope": "subtree", "effect": "allow", "grant_id": "5", "subject_id": "102", "subject_type": "user"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:12:00'),
	(66, NULL, 1, 1001, NULL, 'share.created', 6, 'Vaulter\Models\Drive\Node', '{"level": "reader", "scope": "item", "share_id": "1", "permissions": 1, "token_prefix": "fx000003"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:13:00'),
	(67, NULL, 1, 1001, NULL, 'share.created', 6, 'Vaulter\Models\Drive\Node', '{"level": "reader", "scope": "item", "share_id": "2", "permissions": 1, "token_prefix": "fx000004"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:14:00'),
	(68, NULL, 1, 1001, NULL, 'share.created', 6, 'Vaulter\Models\Drive\Node', '{"level": "reader", "scope": "item", "share_id": "3", "permissions": 1, "token_prefix": "fx000005"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:15:00'),
	(69, NULL, 1, 1001, NULL, 'share.created', 6, 'Vaulter\Models\Drive\Node', '{"level": "reader", "scope": "item", "share_id": "4", "permissions": 1, "token_prefix": "fx000006"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:16:00'),
	(70, NULL, 1, 1001, NULL, 'share.revoked', 6, 'Vaulter\Models\Drive\Node', '{"share_id": "2", "token_prefix": "fx000004"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:17:00'),
	(71, NULL, 1, 1001, NULL, 'share.revoked', 6, 'Vaulter\Models\Drive\Node', '{"share_id": "4", "token_prefix": "fx000006"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:19:00'),
	(72, NULL, 1, 1001, NULL, 'upload_session.initiated', NULL, NULL, '{"protocol": "tus", "upload_id": "00000000-0000-4000-8000-000000000001", "target_name": "large.bin"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 01:21:00');


--
-- Data for Name: v_blobs; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_blobs" VALUES
	(2, NULL, 1, 'sha256', 'f400d6b970764284311640397f4c438064aca96028ebdebaa8d840c2ceba0c55', 16, 'local', 's1/f4/00/f400d6b970764284311640397f4c438064aca96028ebdebaa8d840c2ceba0c55', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:06:00', '2026-01-01 00:06:00', NULL, NULL),
	(3, NULL, 1, 'sha256', '740312e7cc4336ce5e74fee427747611908b8183a7288694ed173a551888eec7', 16, 'local', 's1/74/03/740312e7cc4336ce5e74fee427747611908b8183a7288694ed173a551888eec7', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:07:00', '2026-01-01 00:07:00', NULL, NULL),
	(4, NULL, 1, 'sha256', 'c4d0686d6e7feb53f3bd7d33abe43208ff2b11b8da00541000253699b2956370', 16, 'local', 's1/c4/d0/c4d0686d6e7feb53f3bd7d33abe43208ff2b11b8da00541000253699b2956370', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:08:00', '2026-01-01 00:08:00', NULL, NULL),
	(5, NULL, 1, 'sha256', '5213ee530d7fedcdbf09e3c35032d25e9615a1504cb9d16638e21a1b52ca7c4d', 17, 'local', 's1/52/13/5213ee530d7fedcdbf09e3c35032d25e9615a1504cb9d16638e21a1b52ca7c4d', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:09:00', '2026-01-01 00:09:00', NULL, NULL),
	(6, NULL, 1, 'sha256', '77ee30304b5e58719e60c82f3266df23490ceb995de1bec53ce869ce93fa2c68', 21, 'local', 's1/77/ee/77ee30304b5e58719e60c82f3266df23490ceb995de1bec53ce869ce93fa2c68', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:11:00', '2026-01-01 00:11:00', NULL, NULL),
	(7, NULL, 2, 'sha256', '54eb55f3b784afa49b52cb67c214482344b37b9468fbb0f030289cc26790a10e', 19, 'local', 's2/54/eb/54eb55f3b784afa49b52cb67c214482344b37b9468fbb0f030289cc26790a10e', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:22:00', '2026-01-01 00:22:00', NULL, NULL),
	(8, NULL, 2, 'sha256', '01710ad00516dc63c0a3a0a40513d27e28775b2b8634b678660c276e9d7cbeb6', 16, 'local', 's2/01/71/01710ad00516dc63c0a3a0a40513d27e28775b2b8634b678660c276e9d7cbeb6', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:23:00', '2026-01-01 00:23:00', NULL, NULL),
	(9, NULL, 2, 'sha256', '05a984b99bf5bc2c1d150a8594f3534ebf8e15ec0b1d5c75efea9b4872b4b0fa', 16, 'local', 's2/05/a9/05a984b99bf5bc2c1d150a8594f3534ebf8e15ec0b1d5c75efea9b4872b4b0fa', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:24:00', '2026-01-01 00:24:00', NULL, NULL),
	(10, NULL, 2, 'sha256', '2dbb81cf80e92982dc3776653d46a2940f349b3bfd8c72f2a6e62a901c6b6567', 16, 'local', 's2/2d/bb/2dbb81cf80e92982dc3776653d46a2940f349b3bfd8c72f2a6e62a901c6b6567', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:25:00', '2026-01-01 00:25:00', NULL, NULL),
	(11, NULL, 2, 'sha256', '862d2f069920fd45f2cbc79c1f80f91a296b1badbb16c6f8cdc45ba3d0ff67a6', 17, 'local', 's2/86/2d/862d2f069920fd45f2cbc79c1f80f91a296b1badbb16c6f8cdc45ba3d0ff67a6', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:26:00', '2026-01-01 00:26:00', NULL, NULL),
	(12, NULL, 2, 'sha256', '5f002e563dde8f8e90e25c2323a54c049842847ff16a081c7e796b27694dad02', 21, 'local', 's2/5f/00/5f002e563dde8f8e90e25c2323a54c049842847ff16a081c7e796b27694dad02', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:28:00', '2026-01-01 00:28:00', NULL, NULL),
	(13, NULL, 3, 'sha256', 'a351bd4c010c5fc3d5bd1d608946dd733c5a75ca8c0b70045d013ff401d85891', 19, 'local', 's3/a3/51/a351bd4c010c5fc3d5bd1d608946dd733c5a75ca8c0b70045d013ff401d85891', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:39:00', '2026-01-01 00:39:00', NULL, NULL),
	(14, NULL, 3, 'sha256', '24226fe8df109276008435a9835f77db119559ba7ac97406743a4283d785361d', 16, 'local', 's3/24/22/24226fe8df109276008435a9835f77db119559ba7ac97406743a4283d785361d', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:40:00', '2026-01-01 00:40:00', NULL, NULL),
	(15, NULL, 3, 'sha256', 'fe52cce6d9ff851348ac4a56c0aeaf7c56ec01995f4aee50dd1fbe4049c230ec', 16, 'local', 's3/fe/52/fe52cce6d9ff851348ac4a56c0aeaf7c56ec01995f4aee50dd1fbe4049c230ec', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:41:00', '2026-01-01 00:41:00', NULL, NULL),
	(16, NULL, 3, 'sha256', '7787ed77e8eb0975aa39afef85ddf6b1e1aaa91446cd7779e40ee2a651e47df8', 16, 'local', 's3/77/87/7787ed77e8eb0975aa39afef85ddf6b1e1aaa91446cd7779e40ee2a651e47df8', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:42:00', '2026-01-01 00:42:00', NULL, NULL),
	(17, NULL, 3, 'sha256', 'db2ecfae131b0fdd5fad941bd35c249e7a9523559737765d3e73cd91183dd9d5', 17, 'local', 's3/db/2e/db2ecfae131b0fdd5fad941bd35c249e7a9523559737765d3e73cd91183dd9d5', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:43:00', '2026-01-01 00:43:00', NULL, NULL),
	(18, NULL, 3, 'sha256', '6411a3531c9f8239119d6f341cdbd87b57a8a6b2316825454cadb2e80c6603a8', 21, 'local', 's3/64/11/6411a3531c9f8239119d6f341cdbd87b57a8a6b2316825454cadb2e80c6603a8', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:45:00', '2026-01-01 00:45:00', NULL, NULL),
	(1, NULL, NULL, 'sha256', '17fd007b1eae89012f85996449b99ab04057b97183729e08ac1c2fcb7bb813e5', 19, 'local', 's1/17/fd/17fd007b1eae89012f85996449b99ab04057b97183729e08ac1c2fcb7bb813e5', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:05:00', '2026-01-01 00:05:00', NULL, NULL),
	(19, NULL, NULL, 'sha256', '94364e27e6e12dbfc6ccf67281bd396596ffb68d5e76723e4f2f2c90eb9c1d8d', 29, 'local', 'tnull/94/36/94364e27e6e12dbfc6ccf67281bd396596ffb68d5e76723e4f2f2c90eb9c1d8d', 'committed', 0, 'tenant', NULL, NULL, NULL, false, NULL, '2026-01-01 00:52:00', '2026-01-01 00:52:00', NULL, NULL),
	(20, NULL, NULL, 'sha256', '42416976a60a7a8a85a0efb8d41e755a44df6dc739160b61fbe5b16cf8b0755a', 33, 'local', 'tnull/42/41/42416976a60a7a8a85a0efb8d41e755a44df6dc739160b61fbe5b16cf8b0755a', 'committed', 0, 'tenant', NULL, NULL, NULL, false, NULL, '2026-01-01 00:54:00', '2026-01-01 00:54:00', NULL, NULL),
	(21, NULL, NULL, 'sha256', '15f300f99b8e3f8055bf0b9fc28475180d61a88586db1f3eec2107116ca26e57', 28, 'local', 'global/15/f3/15f300f99b8e3f8055bf0b9fc28475180d61a88586db1f3eec2107116ca26e57', 'committed', 0, 'global', NULL, NULL, NULL, false, NULL, '2026-01-01 00:55:00', '2026-01-01 00:55:00', NULL, NULL);


--
-- Data for Name: v_file_version_blobs; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_file_version_blobs" VALUES
	(1, 1, 'data', 0, NULL, NULL),
	(2, 2, 'data', 0, NULL, NULL),
	(3, 3, 'data', 0, NULL, NULL),
	(4, 4, 'data', 0, NULL, NULL),
	(5, 5, 'data', 0, NULL, NULL),
	(6, 6, 'data', 0, NULL, NULL),
	(7, 7, 'data', 0, NULL, NULL),
	(8, 8, 'data', 0, NULL, NULL),
	(9, 9, 'data', 0, NULL, NULL),
	(10, 10, 'data', 0, NULL, NULL),
	(11, 11, 'data', 0, NULL, NULL),
	(12, 12, 'data', 0, NULL, NULL),
	(13, 13, 'data', 0, NULL, NULL),
	(14, 14, 'data', 0, NULL, NULL),
	(15, 15, 'data', 0, NULL, NULL),
	(16, 16, 'data', 0, NULL, NULL),
	(17, 17, 'data', 0, NULL, NULL),
	(18, 18, 'data', 0, NULL, NULL),
	(19, 19, 'data', 0, NULL, NULL),
	(20, 19, 'data', 0, NULL, NULL),
	(21, 20, 'data', 0, NULL, NULL),
	(22, 21, 'data', 0, NULL, NULL);


--
-- Data for Name: v_file_versions; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_file_versions" VALUES
	(1, NULL, 1, 1, 1, NULL, '17fd007b1eae89012f85996449b99ab04057b97183729e08ac1c2fcb7bb813e5', 19, 'text/plain', 'brief.txt', 'committed', false, NULL, 1001, '2026-01-01 00:05:00', '2026-01-01 00:05:00'),
	(2, NULL, 2, 1, 2, NULL, 'f400d6b970764284311640397f4c438064aca96028ebdebaa8d840c2ceba0c55', 16, 'text/csv', 'q1.csv', 'superseded', false, NULL, 1001, '2026-01-01 00:06:00', '2026-01-01 00:06:00'),
	(4, NULL, 2, 3, 4, NULL, 'c4d0686d6e7feb53f3bd7d33abe43208ff2b11b8da00541000253699b2956370', 16, 'text/csv', 'q1.csv', 'committed', false, NULL, 1001, '2026-01-01 00:08:00', '2026-01-01 00:08:00'),
	(3, NULL, 2, 2, 3, NULL, '740312e7cc4336ce5e74fee427747611908b8183a7288694ed173a551888eec7', 16, 'text/csv', 'q1.csv', 'superseded', false, NULL, 1001, '2026-01-01 00:07:00', '2026-01-01 00:07:00'),
	(5, NULL, 3, 1, 5, NULL, '5213ee530d7fedcdbf09e3c35032d25e9615a1504cb9d16638e21a1b52ca7c4d', 17, 'text/plain', 'old.txt', 'committed', false, NULL, 1001, '2026-01-01 00:09:00', '2026-01-01 00:09:00'),
	(6, NULL, 4, 1, 6, NULL, '77ee30304b5e58719e60c82f3266df23490ceb995de1bec53ce869ce93fa2c68', 21, 'text/plain', 'note.txt', 'committed', false, NULL, 1001, '2026-01-01 00:11:00', '2026-01-01 00:11:00'),
	(7, NULL, 5, 1, 7, NULL, '54eb55f3b784afa49b52cb67c214482344b37b9468fbb0f030289cc26790a10e', 19, 'text/plain', 'brief.txt', 'committed', false, NULL, 1001, '2026-01-01 00:22:00', '2026-01-01 00:22:00'),
	(8, NULL, 6, 1, 8, NULL, '01710ad00516dc63c0a3a0a40513d27e28775b2b8634b678660c276e9d7cbeb6', 16, 'text/csv', 'q1.csv', 'superseded', false, NULL, 1001, '2026-01-01 00:23:00', '2026-01-01 00:23:00'),
	(10, NULL, 6, 3, 10, NULL, '2dbb81cf80e92982dc3776653d46a2940f349b3bfd8c72f2a6e62a901c6b6567', 16, 'text/csv', 'q1.csv', 'committed', false, NULL, 1001, '2026-01-01 00:25:00', '2026-01-01 00:25:00'),
	(9, NULL, 6, 2, 9, NULL, '05a984b99bf5bc2c1d150a8594f3534ebf8e15ec0b1d5c75efea9b4872b4b0fa', 16, 'text/csv', 'q1.csv', 'superseded', false, NULL, 1001, '2026-01-01 00:24:00', '2026-01-01 00:24:00'),
	(11, NULL, 7, 1, 11, NULL, '862d2f069920fd45f2cbc79c1f80f91a296b1badbb16c6f8cdc45ba3d0ff67a6', 17, 'text/plain', 'old.txt', 'committed', false, NULL, 1001, '2026-01-01 00:26:00', '2026-01-01 00:26:00'),
	(12, NULL, 8, 1, 12, NULL, '5f002e563dde8f8e90e25c2323a54c049842847ff16a081c7e796b27694dad02', 21, 'text/plain', 'note.txt', 'committed', false, NULL, 1001, '2026-01-01 00:28:00', '2026-01-01 00:28:00'),
	(13, NULL, 9, 1, 13, NULL, 'a351bd4c010c5fc3d5bd1d608946dd733c5a75ca8c0b70045d013ff401d85891', 19, 'text/plain', 'brief.txt', 'committed', false, NULL, 1001, '2026-01-01 00:39:00', '2026-01-01 00:39:00'),
	(14, NULL, 10, 1, 14, NULL, '24226fe8df109276008435a9835f77db119559ba7ac97406743a4283d785361d', 16, 'text/csv', 'q1.csv', 'superseded', false, NULL, 1001, '2026-01-01 00:40:00', '2026-01-01 00:40:00'),
	(16, NULL, 10, 3, 16, NULL, '7787ed77e8eb0975aa39afef85ddf6b1e1aaa91446cd7779e40ee2a651e47df8', 16, 'text/csv', 'q1.csv', 'committed', false, NULL, 1001, '2026-01-01 00:42:00', '2026-01-01 00:42:00'),
	(15, NULL, 10, 2, 15, NULL, 'fe52cce6d9ff851348ac4a56c0aeaf7c56ec01995f4aee50dd1fbe4049c230ec', 16, 'text/csv', 'q1.csv', 'superseded', false, NULL, 1001, '2026-01-01 00:41:00', '2026-01-01 00:41:00'),
	(17, NULL, 11, 1, 17, NULL, 'db2ecfae131b0fdd5fad941bd35c249e7a9523559737765d3e73cd91183dd9d5', 17, 'text/plain', 'old.txt', 'committed', false, NULL, 1001, '2026-01-01 00:43:00', '2026-01-01 00:43:00'),
	(18, NULL, 12, 1, 18, NULL, '6411a3531c9f8239119d6f341cdbd87b57a8a6b2316825454cadb2e80c6603a8', 21, 'text/plain', 'note.txt', 'committed', false, NULL, 1001, '2026-01-01 00:45:00', '2026-01-01 00:45:00'),
	(19, NULL, 13, 1, 19, NULL, '94364e27e6e12dbfc6ccf67281bd396596ffb68d5e76723e4f2f2c90eb9c1d8d', 29, 'application/octet-stream', 'shared.bin', 'committed', false, NULL, 1001, '2026-01-01 00:52:00', '2026-01-01 00:52:00'),
	(20, NULL, 14, 1, 19, NULL, '94364e27e6e12dbfc6ccf67281bd396596ffb68d5e76723e4f2f2c90eb9c1d8d', 29, 'application/octet-stream', 'shared.bin', 'committed', false, NULL, 1001, '2026-01-01 00:53:00', '2026-01-01 00:53:00'),
	(21, NULL, 15, 1, 20, NULL, '42416976a60a7a8a85a0efb8d41e755a44df6dc739160b61fbe5b16cf8b0755a', 33, 'application/octet-stream', 'tenant.bin', 'committed', false, NULL, 1001, '2026-01-01 00:54:00', '2026-01-01 00:54:00'),
	(22, NULL, 16, 1, 21, NULL, '15f300f99b8e3f8055bf0b9fc28475180d61a88586db1f3eec2107116ca26e57', 28, 'application/octet-stream', 'global.bin', 'committed', false, NULL, 1001, '2026-01-01 00:55:00', '2026-01-01 00:55:00');


--
-- Data for Name: v_files; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_files" VALUES
	(1, NULL, 6, 1, 'text/plain', 19, 1, NULL, NULL, NULL, '2026-01-01 00:05:00', '2026-01-01 00:05:00'),
	(2, NULL, 7, 4, 'text/csv', 16, 3, NULL, NULL, NULL, '2026-01-01 00:06:00', '2026-01-01 00:08:00'),
	(3, NULL, 8, 5, 'text/plain', 17, 1, NULL, NULL, NULL, '2026-01-01 00:09:00', '2026-01-01 00:09:00'),
	(4, NULL, 10, 6, 'text/plain', 21, 1, NULL, NULL, NULL, '2026-01-01 00:11:00', '2026-01-01 00:11:00'),
	(5, NULL, 16, 7, 'text/plain', 19, 1, NULL, NULL, NULL, '2026-01-01 00:22:00', '2026-01-01 00:22:00'),
	(6, NULL, 17, 10, 'text/csv', 16, 3, NULL, NULL, NULL, '2026-01-01 00:23:00', '2026-01-01 00:25:00'),
	(7, NULL, 18, 11, 'text/plain', 17, 1, NULL, NULL, NULL, '2026-01-01 00:26:00', '2026-01-01 00:26:00'),
	(8, NULL, 20, 12, 'text/plain', 21, 1, NULL, NULL, NULL, '2026-01-01 00:28:00', '2026-01-01 00:28:00'),
	(9, NULL, 26, 13, 'text/plain', 19, 1, NULL, NULL, NULL, '2026-01-01 00:39:00', '2026-01-01 00:39:00'),
	(10, NULL, 27, 16, 'text/csv', 16, 3, NULL, NULL, NULL, '2026-01-01 00:40:00', '2026-01-01 00:42:00'),
	(11, NULL, 28, 17, 'text/plain', 17, 1, NULL, NULL, NULL, '2026-01-01 00:43:00', '2026-01-01 00:43:00'),
	(12, NULL, 30, 18, 'text/plain', 21, 1, NULL, NULL, NULL, '2026-01-01 00:45:00', '2026-01-01 00:45:00'),
	(13, NULL, 31, 19, 'application/octet-stream', 29, 1, NULL, NULL, NULL, '2026-01-01 00:52:00', '2026-01-01 00:52:00'),
	(14, NULL, 32, 20, 'application/octet-stream', 29, 1, NULL, NULL, NULL, '2026-01-01 00:53:00', '2026-01-01 00:53:00'),
	(15, NULL, 33, 21, 'application/octet-stream', 33, 1, NULL, NULL, NULL, '2026-01-01 00:54:00', '2026-01-01 00:54:00'),
	(16, NULL, 34, 22, 'application/octet-stream', 28, 1, NULL, NULL, NULL, '2026-01-01 00:55:00', '2026-01-01 00:55:00');


--
-- Data for Name: v_node_comments; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: v_node_document_versions; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_node_document_versions" VALUES
	(1, NULL, 35, 1, '{"type": "doc", "content": [{"type": "paragraph", "content": [{"text": "Notes v1", "type": "text"}]}]}', NULL, NULL, 'richtext', '2cb2e79dc80ba1aef0e7a77f8aeb9d05e3c5274111dcec241052753146eb197f', NULL, false, 'committed', false, NULL, 1001, '2026-01-01 00:56:00'),
	(2, NULL, 36, 1, '{"type": "doc", "content": [{"type": "paragraph", "content": [{"text": "Budget v1", "type": "text"}]}]}', NULL, NULL, 'table', '4c887b035155bc2c9c92c17776c363c4fc644a92ccd6eee550d0e41e4acf66f6', NULL, false, 'committed', false, NULL, 1001, '2026-01-01 00:57:00'),
	(3, NULL, 37, 1, '{"type": "doc", "content": [{"type": "paragraph", "content": [{"text": "Readme v1", "type": "text"}]}]}', NULL, NULL, 'markdown', '8190d674e2d3ee38163fd067499b6570bf2ffb4a84c8261ae26efb9959e5c556', NULL, false, 'committed', false, NULL, 1001, '2026-01-01 00:58:00'),
	(4, NULL, 38, 1, '{"type": "doc", "content": [{"type": "paragraph", "content": [{"text": "Letter template v1", "type": "text"}]}]}', NULL, NULL, 'template', 'f84f6800101d3eb5ac5e0110cf4ffca24a2936181901f6621291552fb7c65cac', NULL, false, 'committed', false, NULL, 1001, '2026-01-01 00:59:00'),
	(5, NULL, 35, 2, '{"type": "doc", "content": [{"type": "paragraph", "content": [{"text": "richtext v2", "type": "text"}]}]}', NULL, NULL, 'richtext', '417d10efa92642f3bd057d96bab2cc619f4af852a7d4f008237c616f61b44308', NULL, true, 'committed', false, NULL, NULL, '2026-01-01 01:00:00'),
	(6, NULL, 37, 2, '{"type": "doc", "content": [{"type": "paragraph", "content": [{"text": "markdown v2", "type": "text"}]}]}', NULL, NULL, 'markdown', '9ea6a2f5567068885dd689bd31f9c43534d09a7a8b76db76d78e4c2f7b7ffda4', NULL, true, 'committed', false, NULL, NULL, '2026-01-01 01:01:00'),
	(7, NULL, 39, 1, '{"type": "doc", "content": [{"type": "paragraph", "content": [{"text": "loose", "type": "text"}]}]}', NULL, NULL, 'richtext', 'f47fb0ea0f7abc7ee4a56cbd76bfa564c111a8e3fbc7df833b65136ac74116ff', NULL, false, 'committed', false, NULL, 1001, '2026-01-01 01:02:00'),
	(8, NULL, 40, 1, '{"type": "doc", "content": [{"type": "paragraph", "content": [{"text": "name clash with the root folder", "type": "text"}]}]}', NULL, NULL, 'richtext', '0cae7f5294201c02bc97ae0d1f64cdc91f8b0237fc6e25de066b5630b9805666', NULL, false, 'committed', false, NULL, 1001, '2026-01-01 01:03:00');


--
-- Data for Name: v_node_grants; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_node_grants" VALUES
	(1, NULL, 2, 101, 'user', 'writer', 0, 'allow', 'subtree', false, NULL, NULL, 1001, NULL, NULL, '2026-01-01 01:05:00', '2026-01-01 01:05:00'),
	(2, NULL, 2, 201, 'role', 'writer', 0, 'allow', 'subtree', false, NULL, NULL, 1001, NULL, NULL, '2026-01-01 01:06:00', '2026-01-01 01:06:00'),
	(3, NULL, 2, 102, 'user', 'writer', 0, 'allow', 'subtree', false, NULL, NULL, 1001, NULL, '2026-01-01 01:10:00', '2026-01-01 01:07:00', '2026-01-01 01:10:00'),
	(4, NULL, 2, 202, 'role', 'writer', 0, 'allow', 'subtree', false, NULL, NULL, 1001, NULL, '2026-01-01 01:11:00', '2026-01-01 01:08:00', '2026-01-01 01:11:00'),
	(5, NULL, 2, 102, 'user', 'reader', 0, 'allow', 'subtree', false, NULL, NULL, 1001, NULL, NULL, '2026-01-01 01:12:00', '2026-01-01 01:12:00');


--
-- Data for Name: v_node_shares; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_node_shares" VALUES
	(1, NULL, 6, 'cc699e2456918a67d078db4e5e8655bc40f73146ca40f5fffc137f5901c832a9', 'fx000003', 1, 'reader', 'item', NULL, false, false, '2027-01-01 01:13:00', NULL, 0, NULL, 1001, NULL, NULL, '2026-01-01 01:13:00', '2026-01-01 01:13:00'),
	(2, NULL, 6, 'd80d249f3ee6d3d1c0fdc62ffda8f30e94ca3c340e38dc2cbd628b491fc657fb', 'fx000004', 1, 'reader', 'item', NULL, false, false, '2027-01-01 01:14:00', NULL, 0, '2026-01-01 01:17:00', 1001, NULL, NULL, '2026-01-01 01:14:00', '2026-01-01 01:17:00'),
	(3, NULL, 6, 'e3f840e240e14db43f7a3265c640d988eb82e5ffd1a7f45efb0536047abaf0ea', 'fx000005', 1, 'reader', 'item', NULL, false, false, '2027-01-01 01:15:00', NULL, 0, NULL, 1001, NULL, '2026-01-01 01:18:00', '2026-01-01 01:15:00', '2026-01-01 01:18:00'),
	(4, NULL, 6, '23a5ddb7f2c7c6346baa81adc1117f2dcf41a84b8072097ff12481f34f199b26', 'fx000006', 1, 'reader', 'item', NULL, false, false, '2027-01-01 01:16:00', NULL, 0, '2026-01-01 01:19:00', 1001, NULL, '2026-01-01 01:20:00', '2026-01-01 01:16:00', '2026-01-01 01:20:00');


--
-- Data for Name: v_nodes; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_nodes" VALUES
	(1, 'default', NULL, 1, NULL, 'folder', '', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
	(2, 'default', NULL, 1, 1, 'folder', 'Projects', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:01:00', '2026-01-01 00:01:00'),
	(4, 'default', NULL, 1, 1, 'folder', 'Archive', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:03:00', '2026-01-01 00:03:00'),
	(6, 'default', NULL, 1, 2, 'file', 'brief.txt', 'text/plain', 'txt', 19, 1, 1, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:05:00', '2026-01-01 00:05:00'),
	(7, 'default', NULL, 1, 3, 'file', 'q1.csv', 'text/csv', 'csv', 16, 4, 2, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:06:00', '2026-01-01 00:08:00'),
	(3, 'default', NULL, 1, 2, 'folder', 'Reports 2025', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:02:00', '2026-01-01 00:12:00'),
	(5, 'default', NULL, 1, 2, 'folder', '2025', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:04:00', '2026-01-01 00:13:00'),
	(8, 'default', NULL, 1, 4, 'file', 'old.txt', 'text/plain', 'txt', 17, 5, 3, 0, false, true, NULL, 1001, NULL, '2026-01-01 00:16:00', 1001, 4, 1001, NULL, NULL, '2026-01-01 00:09:00', '2026-01-01 00:16:00'),
	(10, 'default', NULL, 1, 9, 'file', 'note.txt', 'text/plain', 'txt', 21, 6, 4, 0, false, true, NULL, 1001, NULL, '2026-01-01 00:17:00', 1001, NULL, 1001, NULL, NULL, '2026-01-01 00:11:00', '2026-01-01 00:17:00'),
	(9, 'default', NULL, 1, 1, 'folder', 'Temp', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, 1001, NULL, '2026-01-01 00:17:00', 1001, 1, 1001, NULL, NULL, '2026-01-01 00:10:00', '2026-01-01 00:17:00'),
	(11, 'default', NULL, 2, NULL, 'folder', '', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-01-01 00:17:00', '2026-01-01 00:17:00'),
	(12, 'default', NULL, 2, 11, 'folder', 'Projects', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:18:00', '2026-01-01 00:18:00'),
	(14, 'default', NULL, 2, 11, 'folder', 'Archive', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:20:00', '2026-01-01 00:20:00'),
	(16, 'default', NULL, 2, 12, 'file', 'brief.txt', 'text/plain', 'txt', 19, 7, 5, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:22:00', '2026-01-01 00:22:00'),
	(17, 'default', NULL, 2, 13, 'file', 'q1.csv', 'text/csv', 'csv', 16, 10, 6, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:23:00', '2026-01-01 00:25:00'),
	(13, 'default', NULL, 2, 12, 'folder', 'Reports 2025', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:19:00', '2026-01-01 00:29:00'),
	(15, 'default', NULL, 2, 12, 'folder', '2025', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:21:00', '2026-01-01 00:30:00'),
	(18, 'default', NULL, 2, 14, 'file', 'old.txt', 'text/plain', 'txt', 17, 11, 7, 0, false, true, NULL, 1001, NULL, '2026-01-01 00:33:00', 1001, 14, 1001, NULL, NULL, '2026-01-01 00:26:00', '2026-01-01 00:33:00'),
	(20, 'default', NULL, 2, 19, 'file', 'note.txt', 'text/plain', 'txt', 21, 12, 8, 0, false, true, NULL, 1001, NULL, '2026-01-01 00:34:00', 1001, NULL, 1001, NULL, NULL, '2026-01-01 00:28:00', '2026-01-01 00:34:00'),
	(19, 'default', NULL, 2, 11, 'folder', 'Temp', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, 1001, NULL, '2026-01-01 00:34:00', 1001, 11, 1001, NULL, NULL, '2026-01-01 00:27:00', '2026-01-01 00:34:00'),
	(21, 'default', NULL, 3, NULL, 'folder', '', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-01-01 00:34:00', '2026-01-01 00:34:00'),
	(22, 'default', NULL, 3, 21, 'folder', 'Projects', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:35:00', '2026-01-01 00:35:00'),
	(24, 'default', NULL, 3, 21, 'folder', 'Archive', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:37:00', '2026-01-01 00:37:00'),
	(26, 'default', NULL, 3, 22, 'file', 'brief.txt', 'text/plain', 'txt', 19, 13, 9, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:39:00', '2026-01-01 00:39:00'),
	(27, 'default', NULL, 3, 23, 'file', 'q1.csv', 'text/csv', 'csv', 16, 16, 10, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:40:00', '2026-01-01 00:42:00'),
	(23, 'default', NULL, 3, 22, 'folder', 'Reports 2025', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:36:00', '2026-01-01 00:46:00'),
	(25, 'default', NULL, 3, 22, 'folder', '2025', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:38:00', '2026-01-01 00:47:00'),
	(28, 'default', NULL, 3, 24, 'file', 'old.txt', 'text/plain', 'txt', 17, 17, 11, 0, false, true, NULL, 1001, NULL, '2026-01-01 00:50:00', 1001, 24, 1001, NULL, NULL, '2026-01-01 00:43:00', '2026-01-01 00:50:00'),
	(30, 'default', NULL, 3, 29, 'file', 'note.txt', 'text/plain', 'txt', 21, 18, 12, 0, false, true, NULL, 1001, NULL, '2026-01-01 00:51:00', 1001, NULL, 1001, NULL, NULL, '2026-01-01 00:45:00', '2026-01-01 00:51:00'),
	(29, 'default', NULL, 3, 21, 'folder', 'Temp', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, 1001, NULL, '2026-01-01 00:51:00', 1001, 21, 1001, NULL, NULL, '2026-01-01 00:44:00', '2026-01-01 00:51:00'),
	(31, 'default', NULL, 2, 11, 'file', 'shared.bin', 'application/octet-stream', 'bin', 29, 19, 13, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:52:00', '2026-01-01 00:52:00'),
	(32, 'default', NULL, 3, 21, 'file', 'shared.bin', 'application/octet-stream', 'bin', 29, 20, 14, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:53:00', '2026-01-01 00:53:00'),
	(33, 'default', NULL, 3, 21, 'file', 'tenant.bin', 'application/octet-stream', 'bin', 33, 21, 15, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:54:00', '2026-01-01 00:54:00'),
	(34, 'default', NULL, 1, 1, 'file', 'global.bin', 'application/octet-stream', 'bin', 28, 22, 16, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:55:00', '2026-01-01 00:55:00'),
	(36, 'default', NULL, 1, 2, 'document', 'Budget', 'application/vnd.vaulter.document+json', NULL, 94, 2, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:57:00', '2026-01-01 00:57:00'),
	(38, 'default', NULL, 1, 2, 'document', 'Letter template', 'application/vnd.vaulter.document+json', NULL, 103, 4, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:59:00', '2026-01-01 00:59:00'),
	(35, 'default', NULL, 1, 2, 'document', 'Notes', 'application/vnd.vaulter.document+json', NULL, 93, 5, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:56:00', '2026-01-01 01:00:00'),
	(37, 'default', NULL, 1, 2, 'document', 'Readme', 'application/vnd.vaulter.document+json', NULL, 94, 6, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:58:00', '2026-01-01 01:01:00'),
	(39, 'default', NULL, 1, NULL, 'document', 'Loose note', 'application/vnd.vaulter.document+json', NULL, 90, 7, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 01:02:00', '2026-01-01 01:02:00'),
	(40, 'default', NULL, 1, NULL, 'document', 'Projects', 'application/vnd.vaulter.document+json', NULL, 116, 8, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 01:03:00', '2026-01-01 01:03:00');


--
-- Data for Name: v_outbox; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_outbox" VALUES
	(1, 'storage.created:1', 'default', '1', 'storage.created', '{"storage_id":"1","root_node_id":"1","name":"FixtureV020Host storage","event_id":"storage.created:1","actor_id":null}', '2026-01-01 00:00:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:00:00', '2026-01-01 01:09:00'),
	(2, 'node.created:2', 'default', '2', 'node.created', '{"node_id":"2","storage_id":"1","parent_id":"1","kind":"folder","name":"Projects","event_id":"node.created:2","actor_id":1001}', '2026-01-01 00:01:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:01:00', '2026-01-01 01:09:00'),
	(3, 'node.created:3', 'default', '3', 'node.created', '{"node_id":"3","storage_id":"1","parent_id":"2","kind":"folder","name":"Reports","event_id":"node.created:3","actor_id":1001}', '2026-01-01 00:02:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:02:00', '2026-01-01 01:09:00'),
	(4, 'node.created:4', 'default', '4', 'node.created', '{"node_id":"4","storage_id":"1","parent_id":"1","kind":"folder","name":"Archive","event_id":"node.created:4","actor_id":1001}', '2026-01-01 00:03:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:03:00', '2026-01-01 01:09:00'),
	(5, 'node.created:5', 'default', '5', 'node.created', '{"node_id":"5","storage_id":"1","parent_id":"4","kind":"folder","name":"2025","event_id":"node.created:5","actor_id":1001}', '2026-01-01 00:04:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:04:00', '2026-01-01 01:09:00'),
	(6, 'file.uploaded:1', 'default', '6', 'file.uploaded', '{"node_id":"6","storage_id":"1","version_id":"1","name":"brief.txt","size_bytes":19,"mime_type":"text\/plain","event_id":"file.uploaded:1","actor_id":1001}', '2026-01-01 00:05:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:05:00', '2026-01-01 01:09:00'),
	(7, 'file.uploaded:2', 'default', '7', 'file.uploaded', '{"node_id":"7","storage_id":"1","version_id":"2","name":"q1.csv","size_bytes":16,"mime_type":"text\/csv","event_id":"file.uploaded:2","actor_id":1001}', '2026-01-01 00:06:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:06:00', '2026-01-01 01:09:00'),
	(8, 'version.created:3', 'default', '7', 'version.created', '{"node_id":"7","storage_id":"1","version_id":"3","version_no":2,"size_bytes":16,"event_id":"version.created:3","actor_id":1001}', '2026-01-01 00:07:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:07:00', '2026-01-01 01:09:00'),
	(9, 'version.created:4', 'default', '7', 'version.created', '{"node_id":"7","storage_id":"1","version_id":"4","version_no":3,"size_bytes":16,"event_id":"version.created:4","actor_id":1001}', '2026-01-01 00:08:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:08:00', '2026-01-01 01:09:00'),
	(10, 'file.uploaded:5', 'default', '8', 'file.uploaded', '{"node_id":"8","storage_id":"1","version_id":"5","name":"old.txt","size_bytes":17,"mime_type":"text\/plain","event_id":"file.uploaded:5","actor_id":1001}', '2026-01-01 00:09:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:09:00', '2026-01-01 01:09:00'),
	(11, 'node.created:9', 'default', '9', 'node.created', '{"node_id":"9","storage_id":"1","parent_id":"1","kind":"folder","name":"Temp","event_id":"node.created:9","actor_id":1001}', '2026-01-01 00:10:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:10:00', '2026-01-01 01:09:00'),
	(12, 'file.uploaded:6', 'default', '10', 'file.uploaded', '{"node_id":"10","storage_id":"1","version_id":"6","name":"note.txt","size_bytes":21,"mime_type":"text\/plain","event_id":"file.uploaded:6","actor_id":1001}', '2026-01-01 00:11:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:11:00', '2026-01-01 01:09:00'),
	(13, 'node.renamed:3:Reports 2025', 'default', '3', 'node.renamed', '{"node_id":"3","storage_id":"1","from":"Reports","to":"Reports 2025","event_id":"node.renamed:3:Reports 2025","actor_id":1001}', '2026-01-01 00:12:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:12:00', '2026-01-01 01:09:00'),
	(14, 'node.moved:5:2', 'default', '5', 'node.moved', '{"node_id":"5","storage_id":"1","from_parent_id":"4","to_parent_id":"2","event_id":"node.moved:5:2","actor_id":1001}', '2026-01-01 00:13:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:13:00', '2026-01-01 01:09:00'),
	(15, 'node.trashed:8', 'default', '8', 'node.trashed', '{"node_id":"8","storage_id":"1","name":"old.txt","event_id":"node.trashed:8","actor_id":1001}', '2026-01-01 00:14:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:14:00', '2026-01-01 01:09:00'),
	(16, 'node.restored:8', 'default', '8', 'node.restored', '{"node_id":"8","storage_id":"1","parent_id":"4","name":"old.txt","event_id":"node.restored:8","actor_id":1001}', '2026-01-01 00:15:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:15:00', '2026-01-01 01:09:00'),
	(17, 'node.trashed:9', 'default', '9', 'node.trashed', '{"node_id":"9","storage_id":"1","name":"Temp","event_id":"node.trashed:9","actor_id":1001}', '2026-01-01 00:17:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:17:00', '2026-01-01 01:09:00'),
	(18, 'storage.created:2', 'default', '2', 'storage.created', '{"storage_id":"2","root_node_id":"11","name":"FixtureV020Host storage","event_id":"storage.created:2","actor_id":null}', '2026-01-01 00:17:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:17:00', '2026-01-01 01:09:00'),
	(19, 'node.created:12', 'default', '12', 'node.created', '{"node_id":"12","storage_id":"2","parent_id":"11","kind":"folder","name":"Projects","event_id":"node.created:12","actor_id":1001}', '2026-01-01 00:18:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:18:00', '2026-01-01 01:09:00'),
	(20, 'node.created:13', 'default', '13', 'node.created', '{"node_id":"13","storage_id":"2","parent_id":"12","kind":"folder","name":"Reports","event_id":"node.created:13","actor_id":1001}', '2026-01-01 00:19:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:19:00', '2026-01-01 01:09:00'),
	(21, 'node.created:14', 'default', '14', 'node.created', '{"node_id":"14","storage_id":"2","parent_id":"11","kind":"folder","name":"Archive","event_id":"node.created:14","actor_id":1001}', '2026-01-01 00:20:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:20:00', '2026-01-01 01:09:00'),
	(22, 'node.created:15', 'default', '15', 'node.created', '{"node_id":"15","storage_id":"2","parent_id":"14","kind":"folder","name":"2025","event_id":"node.created:15","actor_id":1001}', '2026-01-01 00:21:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:21:00', '2026-01-01 01:09:00'),
	(23, 'file.uploaded:7', 'default', '16', 'file.uploaded', '{"node_id":"16","storage_id":"2","version_id":"7","name":"brief.txt","size_bytes":19,"mime_type":"text\/plain","event_id":"file.uploaded:7","actor_id":1001}', '2026-01-01 00:22:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:22:00', '2026-01-01 01:09:00'),
	(24, 'file.uploaded:8', 'default', '17', 'file.uploaded', '{"node_id":"17","storage_id":"2","version_id":"8","name":"q1.csv","size_bytes":16,"mime_type":"text\/csv","event_id":"file.uploaded:8","actor_id":1001}', '2026-01-01 00:23:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:23:00', '2026-01-01 01:09:00'),
	(25, 'version.created:9', 'default', '17', 'version.created', '{"node_id":"17","storage_id":"2","version_id":"9","version_no":2,"size_bytes":16,"event_id":"version.created:9","actor_id":1001}', '2026-01-01 00:24:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:24:00', '2026-01-01 01:09:00'),
	(26, 'version.created:10', 'default', '17', 'version.created', '{"node_id":"17","storage_id":"2","version_id":"10","version_no":3,"size_bytes":16,"event_id":"version.created:10","actor_id":1001}', '2026-01-01 00:25:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:25:00', '2026-01-01 01:09:00'),
	(27, 'file.uploaded:11', 'default', '18', 'file.uploaded', '{"node_id":"18","storage_id":"2","version_id":"11","name":"old.txt","size_bytes":17,"mime_type":"text\/plain","event_id":"file.uploaded:11","actor_id":1001}', '2026-01-01 00:26:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:26:00', '2026-01-01 01:09:00'),
	(28, 'node.created:19', 'default', '19', 'node.created', '{"node_id":"19","storage_id":"2","parent_id":"11","kind":"folder","name":"Temp","event_id":"node.created:19","actor_id":1001}', '2026-01-01 00:27:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:27:00', '2026-01-01 01:09:00'),
	(29, 'file.uploaded:12', 'default', '20', 'file.uploaded', '{"node_id":"20","storage_id":"2","version_id":"12","name":"note.txt","size_bytes":21,"mime_type":"text\/plain","event_id":"file.uploaded:12","actor_id":1001}', '2026-01-01 00:28:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:28:00', '2026-01-01 01:09:00'),
	(30, 'node.renamed:13:Reports 2025', 'default', '13', 'node.renamed', '{"node_id":"13","storage_id":"2","from":"Reports","to":"Reports 2025","event_id":"node.renamed:13:Reports 2025","actor_id":1001}', '2026-01-01 00:29:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:29:00', '2026-01-01 01:09:00'),
	(31, 'node.moved:15:12', 'default', '15', 'node.moved', '{"node_id":"15","storage_id":"2","from_parent_id":"14","to_parent_id":"12","event_id":"node.moved:15:12","actor_id":1001}', '2026-01-01 00:30:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:30:00', '2026-01-01 01:09:00'),
	(32, 'node.trashed:18', 'default', '18', 'node.trashed', '{"node_id":"18","storage_id":"2","name":"old.txt","event_id":"node.trashed:18","actor_id":1001}', '2026-01-01 00:31:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:31:00', '2026-01-01 01:09:00'),
	(33, 'node.restored:18', 'default', '18', 'node.restored', '{"node_id":"18","storage_id":"2","parent_id":"14","name":"old.txt","event_id":"node.restored:18","actor_id":1001}', '2026-01-01 00:32:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:32:00', '2026-01-01 01:09:00'),
	(34, 'node.trashed:19', 'default', '19', 'node.trashed', '{"node_id":"19","storage_id":"2","name":"Temp","event_id":"node.trashed:19","actor_id":1001}', '2026-01-01 00:34:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:34:00', '2026-01-01 01:09:00'),
	(35, 'storage.created:3', 'default', '3', 'storage.created', '{"storage_id":"3","root_node_id":"21","name":"FixtureV020Host storage","event_id":"storage.created:3","actor_id":null}', '2026-01-01 00:34:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:34:00', '2026-01-01 01:09:00'),
	(36, 'node.created:22', 'default', '22', 'node.created', '{"node_id":"22","storage_id":"3","parent_id":"21","kind":"folder","name":"Projects","event_id":"node.created:22","actor_id":1001}', '2026-01-01 00:35:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:35:00', '2026-01-01 01:09:00'),
	(37, 'node.created:23', 'default', '23', 'node.created', '{"node_id":"23","storage_id":"3","parent_id":"22","kind":"folder","name":"Reports","event_id":"node.created:23","actor_id":1001}', '2026-01-01 00:36:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:36:00', '2026-01-01 01:09:00'),
	(38, 'node.created:24', 'default', '24', 'node.created', '{"node_id":"24","storage_id":"3","parent_id":"21","kind":"folder","name":"Archive","event_id":"node.created:24","actor_id":1001}', '2026-01-01 00:37:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:37:00', '2026-01-01 01:09:00'),
	(39, 'node.created:25', 'default', '25', 'node.created', '{"node_id":"25","storage_id":"3","parent_id":"24","kind":"folder","name":"2025","event_id":"node.created:25","actor_id":1001}', '2026-01-01 00:38:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:38:00', '2026-01-01 01:09:00'),
	(40, 'file.uploaded:13', 'default', '26', 'file.uploaded', '{"node_id":"26","storage_id":"3","version_id":"13","name":"brief.txt","size_bytes":19,"mime_type":"text\/plain","event_id":"file.uploaded:13","actor_id":1001}', '2026-01-01 00:39:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:39:00', '2026-01-01 01:09:00'),
	(41, 'file.uploaded:14', 'default', '27', 'file.uploaded', '{"node_id":"27","storage_id":"3","version_id":"14","name":"q1.csv","size_bytes":16,"mime_type":"text\/csv","event_id":"file.uploaded:14","actor_id":1001}', '2026-01-01 00:40:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:40:00', '2026-01-01 01:09:00'),
	(42, 'version.created:15', 'default', '27', 'version.created', '{"node_id":"27","storage_id":"3","version_id":"15","version_no":2,"size_bytes":16,"event_id":"version.created:15","actor_id":1001}', '2026-01-01 00:41:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:41:00', '2026-01-01 01:09:00'),
	(43, 'version.created:16', 'default', '27', 'version.created', '{"node_id":"27","storage_id":"3","version_id":"16","version_no":3,"size_bytes":16,"event_id":"version.created:16","actor_id":1001}', '2026-01-01 00:42:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:42:00', '2026-01-01 01:09:00'),
	(44, 'file.uploaded:17', 'default', '28', 'file.uploaded', '{"node_id":"28","storage_id":"3","version_id":"17","name":"old.txt","size_bytes":17,"mime_type":"text\/plain","event_id":"file.uploaded:17","actor_id":1001}', '2026-01-01 00:43:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:43:00', '2026-01-01 01:09:00'),
	(45, 'node.created:29', 'default', '29', 'node.created', '{"node_id":"29","storage_id":"3","parent_id":"21","kind":"folder","name":"Temp","event_id":"node.created:29","actor_id":1001}', '2026-01-01 00:44:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:44:00', '2026-01-01 01:09:00'),
	(46, 'file.uploaded:18', 'default', '30', 'file.uploaded', '{"node_id":"30","storage_id":"3","version_id":"18","name":"note.txt","size_bytes":21,"mime_type":"text\/plain","event_id":"file.uploaded:18","actor_id":1001}', '2026-01-01 00:45:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:45:00', '2026-01-01 01:09:00'),
	(47, 'node.renamed:23:Reports 2025', 'default', '23', 'node.renamed', '{"node_id":"23","storage_id":"3","from":"Reports","to":"Reports 2025","event_id":"node.renamed:23:Reports 2025","actor_id":1001}', '2026-01-01 00:46:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:46:00', '2026-01-01 01:09:00'),
	(48, 'node.moved:25:22', 'default', '25', 'node.moved', '{"node_id":"25","storage_id":"3","from_parent_id":"24","to_parent_id":"22","event_id":"node.moved:25:22","actor_id":1001}', '2026-01-01 00:47:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:47:00', '2026-01-01 01:09:00'),
	(49, 'node.trashed:28', 'default', '28', 'node.trashed', '{"node_id":"28","storage_id":"3","name":"old.txt","event_id":"node.trashed:28","actor_id":1001}', '2026-01-01 00:48:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:48:00', '2026-01-01 01:09:00'),
	(50, 'node.restored:28', 'default', '28', 'node.restored', '{"node_id":"28","storage_id":"3","parent_id":"24","name":"old.txt","event_id":"node.restored:28","actor_id":1001}', '2026-01-01 00:49:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:49:00', '2026-01-01 01:09:00'),
	(51, 'node.trashed:29', 'default', '29', 'node.trashed', '{"node_id":"29","storage_id":"3","name":"Temp","event_id":"node.trashed:29","actor_id":1001}', '2026-01-01 00:51:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:51:00', '2026-01-01 01:09:00'),
	(52, 'file.uploaded:19', 'default', '31', 'file.uploaded', '{"node_id":"31","storage_id":"2","version_id":"19","name":"shared.bin","size_bytes":29,"mime_type":"application\/octet-stream","event_id":"file.uploaded:19","actor_id":1001}', '2026-01-01 00:52:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:52:00', '2026-01-01 01:09:00'),
	(53, 'file.uploaded:20', 'default', '32', 'file.uploaded', '{"node_id":"32","storage_id":"3","version_id":"20","name":"shared.bin","size_bytes":29,"mime_type":"application\/octet-stream","event_id":"file.uploaded:20","actor_id":1001}', '2026-01-01 00:53:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:53:00', '2026-01-01 01:09:00'),
	(54, 'file.uploaded:21', 'default', '33', 'file.uploaded', '{"node_id":"33","storage_id":"3","version_id":"21","name":"tenant.bin","size_bytes":33,"mime_type":"application\/octet-stream","event_id":"file.uploaded:21","actor_id":1001}', '2026-01-01 00:54:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:54:00', '2026-01-01 01:09:00'),
	(55, 'file.uploaded:22', 'default', '34', 'file.uploaded', '{"node_id":"34","storage_id":"1","version_id":"22","name":"global.bin","size_bytes":28,"mime_type":"application\/octet-stream","event_id":"file.uploaded:22","actor_id":1001}', '2026-01-01 00:55:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 00:55:00', '2026-01-01 01:09:00'),
	(56, 'grant.created:1', 'default', '2', 'grant.created', '{"grant_id":"1","node_id":"2","storage_id":"1","subject_type":"user","subject_id":"101","level":"writer","effect":"allow","scope":"subtree","event_id":"grant.created:1","actor_id":1001}', '2026-01-01 01:05:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 01:05:00', '2026-01-01 01:09:00'),
	(57, 'grant.created:2', 'default', '2', 'grant.created', '{"grant_id":"2","node_id":"2","storage_id":"1","subject_type":"role","subject_id":"201","level":"writer","effect":"allow","scope":"subtree","event_id":"grant.created:2","actor_id":1001}', '2026-01-01 01:06:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 01:06:00', '2026-01-01 01:09:00'),
	(58, 'grant.created:3', 'default', '2', 'grant.created', '{"grant_id":"3","node_id":"2","storage_id":"1","subject_type":"user","subject_id":"102","level":"writer","effect":"allow","scope":"subtree","event_id":"grant.created:3","actor_id":1001}', '2026-01-01 01:07:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 01:07:00', '2026-01-01 01:09:00'),
	(59, 'grant.created:4', 'default', '2', 'grant.created', '{"grant_id":"4","node_id":"2","storage_id":"1","subject_type":"role","subject_id":"202","level":"writer","effect":"allow","scope":"subtree","event_id":"grant.created:4","actor_id":1001}', '2026-01-01 01:08:00', '2026-01-01 01:09:00', 0, 'dispatched', '2026-01-01 01:08:00', '2026-01-01 01:09:00'),
	(60, 'grant.revoked:2:user:102', 'default', '2', 'grant.revoked', '{"node_id":"2","storage_id":"1","subject_type":"user","subject_id":"102","revoked":1,"event_id":"grant.revoked:2:user:102","actor_id":1001}', '2026-01-01 01:10:00', NULL, 0, 'pending', '2026-01-01 01:10:00', '2026-01-01 01:10:00'),
	(61, 'grant.revoked:2:role:202', 'default', '2', 'grant.revoked', '{"node_id":"2","storage_id":"1","subject_type":"role","subject_id":"202","revoked":1,"event_id":"grant.revoked:2:role:202","actor_id":1001}', '2026-01-01 01:11:00', NULL, 0, 'pending', '2026-01-01 01:11:00', '2026-01-01 01:11:00'),
	(62, 'grant.created:5', 'default', '2', 'grant.created', '{"grant_id":"5","node_id":"2","storage_id":"1","subject_type":"user","subject_id":"102","level":"reader","effect":"allow","scope":"subtree","event_id":"grant.created:5","actor_id":1001}', '2026-01-01 01:12:00', NULL, 0, 'pending', '2026-01-01 01:12:00', '2026-01-01 01:12:00'),
	(63, 'share.created:1', 'default', '6', 'share.created', '{"share_id":"1","node_id":"6","storage_id":"1","token_prefix":"fx000003","level":"reader","scope":"item","permissions":1,"event_id":"share.created:1","actor_id":1001}', '2026-01-01 01:13:00', NULL, 0, 'pending', '2026-01-01 01:13:00', '2026-01-01 01:13:00'),
	(64, 'share.created:2', 'default', '6', 'share.created', '{"share_id":"2","node_id":"6","storage_id":"1","token_prefix":"fx000004","level":"reader","scope":"item","permissions":1,"event_id":"share.created:2","actor_id":1001}', '2026-01-01 01:14:00', NULL, 0, 'pending', '2026-01-01 01:14:00', '2026-01-01 01:14:00'),
	(65, 'share.created:3', 'default', '6', 'share.created', '{"share_id":"3","node_id":"6","storage_id":"1","token_prefix":"fx000005","level":"reader","scope":"item","permissions":1,"event_id":"share.created:3","actor_id":1001}', '2026-01-01 01:15:00', NULL, 0, 'pending', '2026-01-01 01:15:00', '2026-01-01 01:15:00'),
	(66, 'share.created:4', 'default', '6', 'share.created', '{"share_id":"4","node_id":"6","storage_id":"1","token_prefix":"fx000006","level":"reader","scope":"item","permissions":1,"event_id":"share.created:4","actor_id":1001}', '2026-01-01 01:16:00', NULL, 0, 'pending', '2026-01-01 01:16:00', '2026-01-01 01:16:00'),
	(67, 'share.revoked:2', 'default', '6', 'share.revoked', '{"share_id":"2","node_id":"6","storage_id":"1","token_prefix":"fx000004","event_id":"share.revoked:2","actor_id":1001}', '2026-01-01 01:17:00', NULL, 0, 'pending', '2026-01-01 01:17:00', '2026-01-01 01:17:00'),
	(68, 'share.revoked:4', 'default', '6', 'share.revoked', '{"share_id":"4","node_id":"6","storage_id":"1","token_prefix":"fx000006","event_id":"share.revoked:4","actor_id":1001}', '2026-01-01 01:19:00', NULL, 0, 'pending', '2026-01-01 01:19:00', '2026-01-01 01:19:00');


--
-- Data for Name: v_storages; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_storages" VALUES
	(2, 'default', NULL, 2, 'fixture_host', 'FixtureV020Host storage', NULL, 11, 'local', 'private', NULL, 102, 'active', 31, NULL, NULL, NULL, '2026-01-01 00:17:00', '2026-01-01 00:17:00'),
	(3, 'default', NULL, 3, 'fixture_host', 'FixtureV020Host storage', NULL, 21, 'local', 'private', NULL, 135, 'active', 31, NULL, NULL, NULL, '2026-01-01 00:34:00', '2026-01-01 00:34:00'),
	(1, 'default', NULL, 1, 'fixture_host', 'FixtureV020Host storage', NULL, 1, 'local', 'private', NULL, 101, 'active', 31, NULL, NULL, NULL, '2026-01-01 00:00:00', '2026-01-01 00:00:00');


--
-- Data for Name: v_upload_parts; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: v_upload_sessions; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_upload_sessions" VALUES
	(1, NULL, 1, '00000000-0000-4000-8000-000000000001', NULL, 2, 'large.bin', 'tus', 'uploading', NULL, 10, 4, 8388608, 'application/octet-stream', NULL, NULL, NULL, NULL, '2026-01-02 01:21:00', 1001, '2026-01-01 01:21:00', '2026-01-01 01:22:00');


--
-- Data for Name: v_webhook_deliveries; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_webhook_deliveries" VALUES
	(1, 1, 'grant.created', '{"grant_id":"1","node_id":"2","storage_id":"1","subject_type":"user","subject_id":"101","level":"writer","effect":"allow","scope":"subtree","event_id":"grant.created:1","actor_id":1001}', 'delivered', 1, NULL, NULL, '2026-01-01 01:09:00', '2026-01-01 01:09:00'),
	(2, 1, 'grant.created', '{"grant_id":"2","node_id":"2","storage_id":"1","subject_type":"role","subject_id":"201","level":"writer","effect":"allow","scope":"subtree","event_id":"grant.created:2","actor_id":1001}', 'delivered', 1, NULL, NULL, '2026-01-01 01:09:00', '2026-01-01 01:09:00'),
	(3, 1, 'grant.created', '{"grant_id":"3","node_id":"2","storage_id":"1","subject_type":"user","subject_id":"102","level":"writer","effect":"allow","scope":"subtree","event_id":"grant.created:3","actor_id":1001}', 'delivered', 1, NULL, NULL, '2026-01-01 01:09:00', '2026-01-01 01:09:00'),
	(4, 1, 'grant.created', '{"grant_id":"4","node_id":"2","storage_id":"1","subject_type":"role","subject_id":"202","level":"writer","effect":"allow","scope":"subtree","event_id":"grant.created:4","actor_id":1001}', 'delivered', 1, NULL, NULL, '2026-01-01 01:09:00', '2026-01-01 01:09:00');


--
-- Data for Name: v_webhook_endpoints; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_webhook_endpoints" VALUES
	(1, 'https://hooks.fixture.test/vaulter', '["grant.created","grant.revoked","share.created"]', 'eyJpdiI6IlV2QXpXTnpDVjhBQTNsQ2wrR2ZDWGc9PSIsInZhbHVlIjoiTmVkblQ0WmxGU2VQL1Jocmd1YzF3ald3TlZaZnRFVWNTUkpQRkh1S2tndXlzY2tTd2RHOTFRVENsK3VLSmpXdk5kZFVYd1YybXFJbWVGSnVBSWRvY0xsSkx0VnpOb3BnY3p2eXlqNXBINXc9IiwibWFjIjoiMWM4OTRhMDA4NjI5NzFmZTQyMzUzMWQyMTI0ZDU0YzI5ODYxNDg4YWVjNWQwYjY0MTFlZmI2NTJjNzJjNWMxZiIsInRhZyI6IiJ9', true, '2026-01-01 01:09:00', 0, '2026-01-01 01:04:00', '2026-01-01 01:09:00');


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."migrations_id_seq"', 19, true);


--
-- Name: v_activity_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_activity_id_seq"', 72, true);


--
-- Name: v_blobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_blobs_id_seq"', 21, true);


--
-- Name: v_file_versions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_file_versions_id_seq"', 22, true);


--
-- Name: v_files_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_files_id_seq"', 16, true);


--
-- Name: v_node_comments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_node_comments_id_seq"', 1, false);


--
-- Name: v_node_document_versions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_node_document_versions_id_seq"', 8, true);


--
-- Name: v_node_grants_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_node_grants_id_seq"', 5, true);


--
-- Name: v_node_shares_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_node_shares_id_seq"', 4, true);


--
-- Name: v_nodes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_nodes_id_seq"', 40, true);


--
-- Name: v_outbox_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_outbox_id_seq"', 68, true);


--
-- Name: v_storages_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_storages_id_seq"', 3, true);


--
-- Name: v_upload_sessions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_upload_sessions_id_seq"', 1, true);


--
-- Name: v_webhook_deliveries_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_webhook_deliveries_id_seq"', 4, true);


--
-- Name: v_webhook_endpoints_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_webhook_endpoints_id_seq"', 1, true);


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

