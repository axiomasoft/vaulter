-- Isolated microbenchmark schema; not production Vaulter migrations.
-- load.py drops/recreates only this schema when --reset is explicitly passed.
CREATE SCHEMA vbench;

CREATE TABLE vbench.monolith (
    organization_id bigint NOT NULL,
    document_id bigint NOT NULL,
    generation integer NOT NULL,
    source_seq bigint NOT NULL,
    kind text NOT NULL CHECK (kind IN ('text', 'workbook')),
    body jsonb NOT NULL,
    PRIMARY KEY (organization_id, document_id)
);
CREATE TABLE vbench.read_grants (
    organization_id bigint NOT NULL,
    document_id bigint NOT NULL,
    user_id bigint NOT NULL,
    PRIMARY KEY (organization_id, document_id, user_id)
);
CREATE TABLE vbench.projection_head (
    organization_id bigint NOT NULL,
    document_id bigint NOT NULL,
    generation integer NOT NULL,
    source_seq bigint NOT NULL,
    build_id bigint NOT NULL,
    projector_version text NOT NULL,
    PRIMARY KEY (organization_id, document_id)
);
CREATE TABLE vbench.block_projection (
    organization_id bigint NOT NULL,
    document_id bigint NOT NULL,
    generation integer NOT NULL,
    build_id bigint NOT NULL,
    block_id text NOT NULL,
    order_key bigint NOT NULL,
    node jsonb NOT NULL,
    PRIMARY KEY (organization_id, document_id, generation, build_id, block_id),
    UNIQUE (organization_id, document_id, generation, build_id, order_key)
);
CREATE TABLE vbench.cell_projection (
    organization_id bigint NOT NULL,
    document_id bigint NOT NULL,
    generation integer NOT NULL,
    build_id bigint NOT NULL,
    sheet_id text NOT NULL,
    row_no integer NOT NULL,
    col_no integer NOT NULL,
    cell jsonb NOT NULL,
    PRIMARY KEY (organization_id, document_id, generation, build_id, sheet_id, row_no, col_no)
);
CREATE TABLE vbench.part_head (
    organization_id bigint NOT NULL,
    document_id bigint NOT NULL,
    generation integer NOT NULL,
    source_seq bigint NOT NULL,
    PRIMARY KEY (organization_id, document_id)
);
CREATE TABLE vbench.block_part (
    organization_id bigint NOT NULL,
    document_id bigint NOT NULL,
    generation integer NOT NULL,
    block_id text NOT NULL,
    order_key bigint NOT NULL,
    node jsonb NOT NULL,
    PRIMARY KEY (organization_id, document_id, generation, block_id),
    UNIQUE (organization_id, document_id, generation, order_key)
);
CREATE TABLE vbench.cell_part (
    organization_id bigint NOT NULL,
    document_id bigint NOT NULL,
    generation integer NOT NULL,
    sheet_id text NOT NULL,
    row_no integer NOT NULL,
    col_no integer NOT NULL,
    cell jsonb NOT NULL,
    PRIMARY KEY (organization_id, document_id, generation, sheet_id, row_no, col_no)
);
CREATE TABLE vbench.part_snapshot (
    organization_id bigint NOT NULL,
    document_id bigint NOT NULL,
    generation integer NOT NULL,
    source_seq bigint NOT NULL,
    body jsonb NOT NULL,
    PRIMARY KEY (organization_id, document_id, generation, source_seq)
);
