-- Целевая чистая установка. PostgreSQL / uuid7.bigint.v.on / public.
BEGIN;

CREATE TABLE v_webhook_deliveries (
    id uuid NOT NULL,
    endpoint_id uuid NOT NULL,
    event character varying(255) NOT NULL,
    payload json NOT NULL,
    status character varying(255) NOT NULL,
    attempts integer NOT NULL DEFAULT 0,
    next_retry_at timestamp(0) without time zone,
    claim_token character varying(32),
    claim_until timestamp(0) without time zone,
    last_error text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    event_id character varying(64),
    CONSTRAINT v_webhook_deliveries_event_endpoint_unique UNIQUE (event_id, endpoint_id),
    CONSTRAINT v_webhook_deliveries_pkey PRIMARY KEY (id)
);

CREATE TABLE v_webhook_endpoints (
    id uuid NOT NULL,
    url character varying(255) NOT NULL,
    events json NOT NULL,
    signing_secret text NOT NULL,
    previous_signing_secret text,
    previous_secret_expires_at timestamp(0) without time zone,
    is_active boolean NOT NULL DEFAULT true,
    last_triggered_at timestamp(0) without time zone,
    failure_count integer NOT NULL DEFAULT 0,
    failing_since timestamp(0) without time zone,
    disabled_at timestamp(0) without time zone,
    disabled_reason character varying(32),
    version bigint NOT NULL DEFAULT '0'::bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    tenant_id bigint,
    drive_id uuid,
    CONSTRAINT v_webhook_endpoints_pkey PRIMARY KEY (id),
    CONSTRAINT v_webhook_endpoints_version_check CHECK ((version >= 0))
);

CREATE INDEX v_webhook_deliveries_endpoint_id_index ON v_webhook_deliveries USING btree (endpoint_id);
CREATE INDEX v_webhook_deliveries_prune_idx ON v_webhook_deliveries USING btree (status, updated_at);
CREATE INDEX v_webhook_deliveries_retry_idx ON v_webhook_deliveries USING btree (status, next_retry_at);
CREATE INDEX v_webhook_endpoints_active_tenant_idx ON v_webhook_endpoints USING btree (is_active, tenant_id);
CREATE INDEX v_webhook_endpoints_drive_id_index ON v_webhook_endpoints USING btree (drive_id);
CREATE INDEX v_webhook_endpoints_is_active_index ON v_webhook_endpoints USING btree (is_active);
CREATE INDEX v_webhook_endpoints_tenant_id_index ON v_webhook_endpoints USING btree (tenant_id);
ALTER TABLE v_webhook_deliveries ADD CONSTRAINT v_webhook_deliveries_endpoint_id_foreign FOREIGN KEY (endpoint_id) REFERENCES v_webhook_endpoints(id) ON DELETE CASCADE;
ALTER TABLE v_webhook_endpoints ADD CONSTRAINT v_webhook_endpoints_drive_id_foreign FOREIGN KEY (drive_id) REFERENCES v_drives(id) ON DELETE CASCADE;
COMMIT;
