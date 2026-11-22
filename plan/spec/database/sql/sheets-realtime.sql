-- Optional 6.1 profile, after sheets.sql, collaboration.sql and collaboration-agent.sql.
-- Reuses the unified protocol-neutral vaulter-collaboration engine (v_collaboration_heads with
-- protocol = 'univer-sheets/1', v_collaboration_updates, v_collaboration_receipts, owner_fence)
-- and adds only the Sheets-specific correlation link to v_sheets_commands receipts.
BEGIN;

CREATE TABLE v_sheets_realtime_links (
    document_id uuid NOT NULL,
    generation bigint NOT NULL CHECK (generation >= 0),
    seq bigint NOT NULL CHECK (seq >= 1),
    source_position bigint NOT NULL CHECK (source_position >= 0),
    receipt_id uuid,
    fence bigint NOT NULL CHECK (fence >= 1),
    committed_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (document_id, generation, seq),
    FOREIGN KEY (document_id, generation, seq)
        REFERENCES v_collaboration_updates(document_id, generation, seq) ON DELETE CASCADE,
    FOREIGN KEY (document_id, generation, receipt_id)
        REFERENCES v_sheets_commands(document_id, generation, receipt_id) ON DELETE NO ACTION DEFERRABLE INITIALLY IMMEDIATE
);
CREATE INDEX v_sheets_rt_links_receipt_idx
    ON v_sheets_realtime_links (receipt_id) WHERE receipt_id IS NOT NULL;
CREATE INDEX v_sheets_rt_links_receipt_fk_idx
    ON v_sheets_realtime_links (document_id, generation, receipt_id);

COMMIT;
