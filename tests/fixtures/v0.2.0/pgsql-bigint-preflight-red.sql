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
-- Name: vlegacy_storages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE "public"."vlegacy_storages" (
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
-- Name: vlegacy_storages_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE "public"."vlegacy_storages_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: vlegacy_storages_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE "public"."vlegacy_storages_id_seq" OWNED BY "public"."vlegacy_storages"."id";


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
-- Name: vlegacy_storages id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."vlegacy_storages" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."vlegacy_storages_id_seq"'::"regclass");


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
	(2, NULL, 1, 1001, NULL, 'node.created', 2, 'Vaulter\Models\Drive\Node', '{"kind": "folder", "name": "Inbox", "parent_id": "1"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:01:00'),
	(3, NULL, 1, 1001, NULL, 'file.uploaded', 3, 'Vaulter\Models\Drive\Node', '{"name": "gone.txt", "mime_type": "text/plain", "size_bytes": 23, "version_id": "1"}', '127.0.0.1', 'Symfony', '00000000-0000-4000-8000-000000000001', NULL, '2026-01-01 00:02:00');


--
-- Data for Name: v_blobs; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_blobs" VALUES
	(1, NULL, 1, 'sha256', 'a1ea7ce39d2d93bc45102b18f06dfd009562f641070aace5e620a98a304d8707', 23, 'local', 's1/a1/ea/a1ea7ce39d2d93bc45102b18f06dfd009562f641070aace5e620a98a304d8707', 'committed', 0, 'storage', NULL, NULL, NULL, false, NULL, '2026-01-01 00:02:00', '2026-01-01 00:02:00', NULL, NULL);


--
-- Data for Name: v_file_version_blobs; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_file_version_blobs" VALUES
	(1, 1, 'data', 0, NULL, NULL);


--
-- Data for Name: v_file_versions; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_file_versions" VALUES
	(1, NULL, 1, 1, 1, NULL, 'a1ea7ce39d2d93bc45102b18f06dfd009562f641070aace5e620a98a304d8707', 23, 'text/plain', 'gone.txt', 'committed', false, NULL, 1001, '2026-01-01 00:02:00', '2026-01-01 00:02:00');


--
-- Data for Name: v_files; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_files" VALUES
	(1, NULL, 3, 1, 'text/plain', 23, 1, NULL, NULL, NULL, '2026-01-01 00:02:00', '2026-01-01 00:02:00');


--
-- Data for Name: v_node_comments; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: v_node_document_versions; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: v_node_grants; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: v_node_shares; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: v_nodes; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_nodes" VALUES
	(1, 'default', NULL, 1, NULL, 'folder', '', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
	(2, 'default', NULL, 1, 1, 'folder', 'Inbox', NULL, NULL, 0, NULL, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:01:00', '2026-01-01 00:01:00'),
	(4, 'default', NULL, 1, 2, 'document', 'Headless', 'application/vnd.vaulter.document+json', NULL, 93, 1, NULL, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, NULL, '2026-01-01 00:03:00', '2026-01-01 00:03:00'),
	(3, 'default', NULL, 1, 2, 'file', 'gone.txt', 'text/plain', 'txt', 23, 1, 1, 0, false, true, NULL, 1001, NULL, NULL, NULL, NULL, 1001, NULL, '2026-01-01 00:03:00', '2026-01-01 00:02:00', '2026-01-01 00:02:00');


--
-- Data for Name: v_outbox; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_outbox" VALUES
	(1, 'storage.created:1', 'default', '1', 'storage.created', '{"storage_id":"1","root_node_id":"1","name":"FixtureV020Host storage","event_id":"storage.created:1","actor_id":null}', '2026-01-01 00:00:00', NULL, 0, 'pending', '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
	(2, 'node.created:2', 'default', '2', 'node.created', '{"node_id":"2","storage_id":"1","parent_id":"1","kind":"folder","name":"Inbox","event_id":"node.created:2","actor_id":1001}', '2026-01-01 00:01:00', NULL, 0, 'pending', '2026-01-01 00:01:00', '2026-01-01 00:01:00'),
	(3, 'file.uploaded:1', 'default', '3', 'file.uploaded', '{"node_id":"3","storage_id":"1","version_id":"1","name":"gone.txt","size_bytes":23,"mime_type":"text\/plain","event_id":"file.uploaded:1","actor_id":1001}', '2026-01-01 00:02:00', NULL, 0, 'pending', '2026-01-01 00:02:00', '2026-01-01 00:02:00');


--
-- Data for Name: v_storages; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."v_storages" VALUES
	(1, 'default', NULL, 1, 'fixture_host', 'FixtureV020Host storage', NULL, 1, 'local', 'private', NULL, 23, 'active', 31, NULL, NULL, NULL, '2026-01-01 00:00:00', '2026-01-01 00:00:00');


--
-- Data for Name: v_upload_parts; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: v_upload_sessions; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: v_webhook_deliveries; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: v_webhook_endpoints; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: vlegacy_storages; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO "public"."vlegacy_storages" VALUES
	(1, 'legacy', NULL, 2, 'fixture_host', 'Legacy panel storage', NULL, NULL, 'local', 'project', NULL, 0, 'active', 0, NULL, NULL, NULL, '2026-01-01 00:03:00', '2026-01-01 00:03:00');


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."migrations_id_seq"', 19, true);


--
-- Name: v_activity_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_activity_id_seq"', 3, true);


--
-- Name: v_blobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_blobs_id_seq"', 1, true);


--
-- Name: v_file_versions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_file_versions_id_seq"', 1, true);


--
-- Name: v_files_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_files_id_seq"', 1, true);


--
-- Name: v_node_comments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_node_comments_id_seq"', 1, false);


--
-- Name: v_node_document_versions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_node_document_versions_id_seq"', 1, true);


--
-- Name: v_node_grants_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_node_grants_id_seq"', 1, false);


--
-- Name: v_node_shares_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_node_shares_id_seq"', 1, false);


--
-- Name: v_nodes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_nodes_id_seq"', 4, true);


--
-- Name: v_outbox_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_outbox_id_seq"', 3, true);


--
-- Name: v_storages_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_storages_id_seq"', 1, true);


--
-- Name: v_upload_sessions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_upload_sessions_id_seq"', 1, false);


--
-- Name: v_webhook_deliveries_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_webhook_deliveries_id_seq"', 1, false);


--
-- Name: v_webhook_endpoints_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."v_webhook_endpoints_id_seq"', 1, false);


--
-- Name: vlegacy_storages_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('"public"."vlegacy_storages_id_seq"', 1, false);


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
-- Name: vlegacy_storages vlegacy_storages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY "public"."vlegacy_storages"
    ADD CONSTRAINT "vlegacy_storages_pkey" PRIMARY KEY ("id");


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
-- Name: vlegacy_storages_owner_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "vlegacy_storages_owner_id_index" ON "public"."vlegacy_storages" USING "btree" ("owner_id");


--
-- Name: vlegacy_storages_owner_panel_uniq; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX "vlegacy_storages_owner_panel_uniq" ON "public"."vlegacy_storages" USING "btree" ("owner_type", "owner_id", "panel") WHERE ("deleted_at" IS NULL);


--
-- Name: vlegacy_storages_owner_type_owner_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "vlegacy_storages_owner_type_owner_id_index" ON "public"."vlegacy_storages" USING "btree" ("owner_type", "owner_id");


--
-- Name: vlegacy_storages_panel_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "vlegacy_storages_panel_index" ON "public"."vlegacy_storages" USING "btree" ("panel");


--
-- Name: vlegacy_storages_panel_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "vlegacy_storages_panel_status_index" ON "public"."vlegacy_storages" USING "btree" ("panel", "status");


--
-- Name: vlegacy_storages_root_node_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "vlegacy_storages_root_node_id_index" ON "public"."vlegacy_storages" USING "btree" ("root_node_id");


--
-- Name: vlegacy_storages_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "vlegacy_storages_status_index" ON "public"."vlegacy_storages" USING "btree" ("status");


--
-- Name: vlegacy_storages_storage_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "vlegacy_storages_storage_type_index" ON "public"."vlegacy_storages" USING "btree" ("storage_type");


--
-- Name: vlegacy_storages_tenant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX "vlegacy_storages_tenant_id_index" ON "public"."vlegacy_storages" USING "btree" ("tenant_id");


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

