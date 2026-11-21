-- Optional 6.1 profile, after sheets.sql; real-time engine authentication is enforced by owner.
BEGIN;

CREATE TABLE v_sheets_realtime_epochs (
    document_id uuid NOT NULL REFERENCES v_documents(id) ON DELETE CASCADE,
    generation bigint NOT NULL CHECK (generation >= 0),
    profile_id text NOT NULL CHECK (profile_id <> ''),
    fence bigint NOT NULL CHECK (fence >= 1),
    owner_id uuid,
    lease_until timestamptz,
    head_seq bigint NOT NULL DEFAULT 0 CHECK (head_seq >= 0),
    checkpoint_seq bigint NOT NULL DEFAULT 0 CHECK (checkpoint_seq BETWEEN 0 AND head_seq),
    checkpoint_position bigint NOT NULL CHECK (checkpoint_position >= 0),
    checkpoint bytea NOT NULL,
    checkpoint_sha256 text NOT NULL CHECK (checkpoint_sha256 ~ '^[0-9a-f]{64}$'),
    PRIMARY KEY (document_id, generation),
    CHECK ((owner_id IS NULL) = (lease_until IS NULL))
);
CREATE INDEX v_sheets_rt_lease_idx ON v_sheets_realtime_epochs (lease_until) WHERE owner_id IS NOT NULL;
CREATE TABLE v_sheets_realtime_changes (
    document_id uuid NOT NULL,
    generation bigint NOT NULL,
    seq bigint NOT NULL CHECK (seq >= 1),
    mutation_id text NOT NULL CHECK (mutation_id <> ''),
    payload bytea NOT NULL,
    payload_sha256 text NOT NULL CHECK (payload_sha256 ~ '^[0-9a-f]{64}$'),
    source_position bigint NOT NULL CHECK (source_position >= 0),
    receipt_id uuid,
    fence bigint NOT NULL CHECK (fence >= 1),
    committed_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (document_id, generation, seq),
    UNIQUE (document_id, generation, mutation_id),
    FOREIGN KEY (document_id, generation) REFERENCES v_sheets_realtime_epochs(document_id, generation) ON DELETE CASCADE,
    FOREIGN KEY (document_id, generation, receipt_id)
        REFERENCES v_sheets_commands(document_id, generation, receipt_id) ON DELETE NO ACTION DEFERRABLE INITIALLY DEFERRED
);
CREATE INDEX v_sheets_rt_receipt_idx ON v_sheets_realtime_changes (receipt_id) WHERE receipt_id IS NOT NULL;

COMMIT;
