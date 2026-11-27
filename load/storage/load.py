#!/usr/bin/env python3
"""Load fixtures into an isolated PostgreSQL database. Requires externally installed psycopg 3."""
import argparse
import json
import os
from pathlib import Path


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--fixtures", type=Path, default=Path(__file__).parent / "fixtures")
    p.add_argument("--reset", action="store_true", help="Drop/recreate the dedicated vbench schema.")
    args = p.parse_args()
    try:
        import psycopg
    except ImportError as exc:
        raise SystemExit("NOT_RUN: install psycopg 3 in the benchmark environment and record its version.") from exc
    dsn = os.environ.get("BENCH_DSN")
    if not dsn:
        raise SystemExit("NOT_RUN: set BENCH_DSN; its value is never written into results.")
    base = Path(__file__).parent
    contract = (args.fixtures / "contract.json").read_text()
    workbook = (args.fixtures / "workbook.json").read_text()
    with psycopg.connect(dsn, autocommit=True) as conn:
        if conn.info.server_version < 180000:
            raise SystemExit("This bundle targets PostgreSQL 18 or newer; do not silently change the baseline.")
        with conn.transaction():
            with conn.cursor() as cur:
                if args.reset:
                    cur.execute("DROP SCHEMA IF EXISTS vbench CASCADE")
                cur.execute((base / "schema.sql").read_text())
                for doc, kind, payload in [(1, "text", contract), (2, "workbook", workbook)]:
                    cur.execute("INSERT INTO vbench.monolith VALUES (1,%s,1,1,%s,%s::jsonb)", (doc, kind, payload))
                    cur.execute("INSERT INTO vbench.read_grants VALUES (1,%s,10)", (doc,))
                    cur.execute("INSERT INTO vbench.projection_head VALUES (1,%s,1,1,1,'fixture-projector-1')", (doc,))
                    cur.execute("INSERT INTO vbench.part_head VALUES (1,%s,1,1)", (doc,))
                cur.execute("""
                    INSERT INTO vbench.block_projection
                    SELECT 1,1,1,1, e.node->'attrs'->>'blockId', e.ord*1024, e.node
                    FROM vbench.monolith m
                    CROSS JOIN LATERAL jsonb_array_elements(m.body->'content') WITH ORDINALITY e(node,ord)
                    WHERE m.organization_id=1 AND m.document_id=1
                """)
                cur.execute("""
                    INSERT INTO vbench.cell_projection
                    SELECT 1,2,1,1,'s1', split_part(e.key,':',1)::integer,
                           split_part(e.key,':',2)::integer, e.value
                    FROM vbench.monolith m
                    CROSS JOIN LATERAL jsonb_each(m.body#>'{sheets,s1,cells}') e
                    WHERE m.organization_id=1 AND m.document_id=2
                """)
                cur.execute("""
                    INSERT INTO vbench.block_part
                    SELECT organization_id,document_id,generation,block_id,order_key,node
                    FROM vbench.block_projection
                """)
                cur.execute("""
                    INSERT INTO vbench.cell_part
                    SELECT organization_id,document_id,generation,sheet_id,row_no,col_no,cell
                    FROM vbench.cell_projection
                """)
                cur.execute("INSERT INTO vbench.part_snapshot SELECT organization_id,document_id,generation,source_seq,body FROM vbench.monolith")
        # Deliberately outside a transaction. This establishes a read-mostly baseline.
        with conn.cursor() as cur:
            for table in ["monolith", "read_grants", "projection_head", "block_projection", "cell_projection", "part_head", "block_part", "cell_part", "part_snapshot"]:
                cur.execute(f"VACUUM (ANALYZE) vbench.{table}")
            cur.execute("""SELECT c.relname, pg_relation_size(c.oid), pg_indexes_size(c.oid),
                       pg_total_relation_size(c.oid), c.reltoastrelid::text
                       FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                       WHERE n.nspname='vbench' AND c.relkind='r' ORDER BY c.relname""")
            sizes = cur.fetchall()
            cur.execute("SELECT version()")
            version = cur.fetchone()[0]
    print(json.dumps({"status": "LOADED", "postgres_version": version,
                      "psycopg_version": psycopg.__version__,
                      "size_columns": ["relation", "heap_bytes", "index_bytes", "total_bytes_including_toast", "toast_oid"],
                      "relation_sizes": sizes}, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
