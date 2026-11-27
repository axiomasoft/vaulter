-- Целевая чистая установка. PostgreSQL / uuid7.bigint.v.on / public.
BEGIN;
CREATE SEQUENCE v_outbox_outbox_seq_seq AS bigint;

CREATE TABLE v_idempotency_keys (
    id uuid NOT NULL,
    scope_digest character(64) NOT NULL,
    tenant_kind character varying(16) NOT NULL,
    tenant_id character varying(191),
    scope_kind character varying(32) NOT NULL,
    scope_id character varying(191) NOT NULL,
    actor_kind character varying(16) NOT NULL,
    actor_id character varying(191),
    operation character varying(64) NOT NULL,
    idempotency_key character varying(344) NOT NULL,
    request_hash character(64) NOT NULL,
    status character varying(16) NOT NULL,
    receipt_kind character varying(64),
    codec_version smallint,
    receipt_body json,
    http_code smallint,
    receipt_headers json,
    resource_kind character varying(32),
    resource_id character varying(191),
    auth_scope_kind character varying(32),
    auth_scope_id character varying(191),
    created_at timestamp(0) without time zone NOT NULL,
    completed_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone NOT NULL,
    CONSTRAINT v_idempotency_keys_pkey PRIMARY KEY (id),
    CONSTRAINT v_idempotency_keys_scope_unique UNIQUE (scope_digest)
);

CREATE TABLE v_outbox (
    outbox_seq bigint NOT NULL DEFAULT nextval('v_outbox_outbox_seq_seq'::regclass),
    event_id character varying(64) NOT NULL,
    tenant_id bigint,
    scope_kind character varying(32) NOT NULL,
    scope_id character varying(191),
    aggregate_type character varying(32),
    aggregate_id character varying(64),
    sequence bigint,
    type character varying(64) NOT NULL,
    external boolean NOT NULL,
    payload json NOT NULL,
    created_at timestamp(0) without time zone NOT NULL,
    staged_at timestamp(6) without time zone,
    CONSTRAINT v_outbox_event_id_unique UNIQUE (event_id),
    CONSTRAINT v_outbox_pkey PRIMARY KEY (outbox_seq)
);

CREATE TABLE v_outbox_consumers (
    consumer_id character varying(64) NOT NULL,
    delivery_class character varying(16) NOT NULL,
    state character varying(16) NOT NULL,
    activation_seq bigint NOT NULL,
    reconciled_through_seq bigint NOT NULL,
    paused_at timestamp(0) without time zone,
    lease_owner character varying(64),
    lease_generation bigint NOT NULL DEFAULT '0'::bigint,
    lease_expires_at timestamp(0) without time zone,
    gaps json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT v_outbox_consumers_pkey PRIMARY KEY (consumer_id)
);

CREATE TABLE v_outbox_deliveries (
    event_id character varying(64) NOT NULL,
    consumer_id character varying(64) NOT NULL,
    outbox_seq bigint NOT NULL,
    state character varying(16) NOT NULL,
    attempts integer NOT NULL DEFAULT 0,
    next_attempt_at timestamp(0) without time zone NOT NULL,
    lease_owner character varying(64),
    lease_generation bigint NOT NULL DEFAULT '0'::bigint,
    lease_expires_at timestamp(0) without time zone,
    continuation character varying(191),
    last_error text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    done_at timestamp(0) without time zone,
    CONSTRAINT v_outbox_deliveries_pkey PRIMARY KEY (event_id, consumer_id)
);

CREATE INDEX v_idempotency_keys_expires_idx ON v_idempotency_keys USING btree (expires_at);
CREATE INDEX v_outbox_created_idx ON v_outbox USING btree (created_at);
CREATE INDEX v_outbox_staged_idx ON v_outbox USING btree (staged_at);
CREATE INDEX v_outbox_tenant_id_index ON v_outbox USING btree (tenant_id);
CREATE INDEX v_outbox_deliveries_due_idx ON v_outbox_deliveries USING btree (consumer_id, state, next_attempt_at);
CREATE INDEX v_outbox_deliveries_lease_idx ON v_outbox_deliveries USING btree (state, lease_expires_at);
CREATE INDEX v_outbox_deliveries_seq_idx ON v_outbox_deliveries USING btree (outbox_seq);
ALTER SEQUENCE v_outbox_outbox_seq_seq OWNED BY v_outbox.outbox_seq;
COMMIT;
