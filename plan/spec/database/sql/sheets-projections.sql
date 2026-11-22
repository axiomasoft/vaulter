-- Phase 5.1. Workbook read projections on the Sheets owner database.
BEGIN;

CREATE TABLE v_sheets_projection_builds (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    document_id uuid NOT NULL REFERENCES v_documents(id) ON DELETE CASCADE,
    generation bigint NOT NULL CHECK (generation >= 0),
    source_position bigint NOT NULL CHECK (source_position >= 0),
    source_sha256 text NOT NULL CHECK (source_sha256 ~ '^[0-9a-f]{64}$'),
    projector_version integer NOT NULL CHECK (projector_version > 0),
    profile_id text NOT NULL CHECK (octet_length(profile_id) BETWEEN 1 AND 255),
    locale text NOT NULL CHECK (locale <> ''),
    state text NOT NULL CHECK (state IN ('building','ready')),
    calculated_position bigint CHECK (calculated_position >= 0 AND calculated_position <= source_position),
    created_at timestamptz NOT NULL DEFAULT now(),
    completed_at timestamptz,
    UNIQUE (document_id, id, state),
    CHECK ((state = 'ready') = (completed_at IS NOT NULL))
);
CREATE INDEX v_sheets_build_source_idx ON v_sheets_projection_builds (document_id, generation, source_position);
CREATE INDEX v_sheets_build_gc_idx ON v_sheets_projection_builds (created_at, id);

CREATE TABLE v_sheets_projection_heads (
    document_id uuid PRIMARY KEY REFERENCES v_documents(id) ON DELETE CASCADE,
    build_id uuid NOT NULL,
    build_state text NOT NULL DEFAULT 'ready' CHECK (build_state = 'ready'),
    published_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (document_id, build_id, build_state)
        REFERENCES v_sheets_projection_builds(document_id, id, state) ON DELETE NO ACTION DEFERRABLE INITIALLY IMMEDIATE
);
CREATE INDEX v_sheets_projection_heads_build_fk_idx
    ON v_sheets_projection_heads (document_id, build_id, build_state);

CREATE TABLE v_sheets_projection_sheets (
    build_id uuid NOT NULL REFERENCES v_sheets_projection_builds(id) ON DELETE CASCADE,
    sheet_id text NOT NULL CHECK (octet_length(sheet_id) BETWEEN 1 AND 255),
    title text NOT NULL,
    ordinal integer NOT NULL CHECK (ordinal >= 1),
    PRIMARY KEY (build_id, sheet_id),
    UNIQUE (build_id, ordinal)
);
CREATE TABLE v_sheets_projection_tables (
    build_id uuid NOT NULL,
    sheet_id text NOT NULL,
    table_id text NOT NULL CHECK (octet_length(table_id) BETWEEN 1 AND 255),
    title text NOT NULL,
    PRIMARY KEY (build_id, table_id),
    FOREIGN KEY (build_id, sheet_id) REFERENCES v_sheets_projection_sheets(build_id, sheet_id) ON DELETE CASCADE
);
CREATE INDEX v_sheets_table_sheet_idx ON v_sheets_projection_tables (build_id, sheet_id, table_id);

CREATE TABLE v_sheets_projection_columns (
    build_id uuid NOT NULL,
    table_id text NOT NULL,
    column_id text NOT NULL CHECK (octet_length(column_id) BETWEEN 1 AND 255),
    title text NOT NULL,
    column_no integer NOT NULL CHECK (column_no >= 1),
    PRIMARY KEY (build_id, table_id, column_id),
    UNIQUE (build_id, table_id, column_no),
    FOREIGN KEY (build_id, table_id) REFERENCES v_sheets_projection_tables(build_id, table_id) ON DELETE CASCADE
);
CREATE TABLE v_sheets_projection_rows (
    build_id uuid NOT NULL,
    table_id text NOT NULL,
    row_id text NOT NULL CHECK (octet_length(row_id) BETWEEN 1 AND 255),
    row_no integer NOT NULL CHECK (row_no >= 1),
    PRIMARY KEY (build_id, table_id, row_id),
    UNIQUE (build_id, table_id, row_no),
    FOREIGN KEY (build_id, table_id) REFERENCES v_sheets_projection_tables(build_id, table_id) ON DELETE CASCADE
);

CREATE TABLE v_sheets_projection_cells (
    build_id uuid NOT NULL,
    sheet_id text NOT NULL,
    row_no integer NOT NULL CHECK (row_no >= 1),
    column_no integer NOT NULL CHECK (column_no >= 1),
    kind text NOT NULL CHECK (kind IN ('blank','literal','formula')),
    literal jsonb,
    formula text,
    calculated jsonb,
    calc_error text,
    number_format text,
    display_text text,
    PRIMARY KEY (build_id, sheet_id, row_no, column_no),
    FOREIGN KEY (build_id, sheet_id) REFERENCES v_sheets_projection_sheets(build_id, sheet_id) ON DELETE CASCADE,
    CHECK (
        (kind = 'blank' AND literal IS NULL AND formula IS NULL) OR
        (kind = 'literal' AND literal IS NOT NULL AND jsonb_typeof(literal) IN ('boolean','number','string') AND formula IS NULL) OR
        (kind = 'formula' AND literal IS NULL AND formula IS NOT NULL AND left(formula,1) = '=')
    ),
    CHECK (kind = 'formula' OR (calculated IS NULL AND calc_error IS NULL)),
    CHECK (calculated IS NULL OR (jsonb_typeof(calculated) IN ('null','boolean','number','string') AND calc_error IS NULL))
);

COMMIT;
