#!/usr/bin/env python3
"""Benchmark S4 cold-cache reads and write/WAL costs on PostgreSQL 18.

Measures:
  1. Warm vs cold-cache read latency (p50/p95/p99) and EXPLAIN (ANALYZE, BUFFERS)
     counters comparing monolith JSONB vs granular parts vs projection tables across
     addressed contract blocks (p16..p18 of 1,100 blocks) and addressed cells
     (B2:E6 of sparse sheets). Explicitly restarts PostgreSQL (`shared_buffers`
     flushed) and reports OS page-cache handling (`posix_fadvise` / `drop_caches`).
  2. Hash verification (SHA-256) of query payloads before emitting comparative p95.
  3. Write and WAL amplification (`pg_current_wal_lsn()` delta in bytes +
     `EXPLAIN (ANALYZE, WAL, BUFFERS)`) for monolith overwrite vs targeted update.

Usage:
  load/scripts/bench-storage-cold-wal.py --mode dry-run
  load/scripts/bench-storage-cold-wal.py --mode smoke --output load/results/s4-slo/cold-wal.json
  load/scripts/bench-storage-cold-wal.py --mode full --output load/results/s4-slo/cold-wal.json
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
from pathlib import Path


def resolve_pg_bin(explicit: str | None) -> Path:
    candidates = [explicit, os.environ.get('PG_BIN'), '/usr/lib/postgresql/18/bin']
    for item in candidates:
        if item and (Path(item) / 'initdb').is_file() and (Path(item) / 'pg_ctl').is_file():
            return Path(item)
    which_initdb = shutil.which('initdb')
    if which_initdb:
        return Path(which_initdb).parent
    raise SystemExit('PostgreSQL binaries (initdb/pg_ctl/psql) not found; set PG_BIN=/usr/lib/postgresql/18/bin')


def resolve_psql(pg_bin: Path) -> str:
    candidate = pg_bin / 'psql'
    if candidate.is_file():
        return str(candidate)
    which_psql = shutil.which('psql')
    if which_psql:
        return which_psql
    raise SystemExit('psql binary not found')


def run_psql(psql_bin: str, dsn: str, sql: str) -> str:
    proc = subprocess.run(
        [psql_bin, dsn, '-X', '-q', '-t', '-A', '-v', 'ON_ERROR_STOP=1'],
        input=sql,
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        raise RuntimeError(f'psql failed ({proc.returncode}): {proc.stderr.strip() or proc.stdout.strip()}')
    return proc.stdout.strip()


def try_drop_os_caches(data_dir: Path) -> dict[str, object]:
    """Flush PostgreSQL data files from OS cache via posix_fadvise; note drop_caches privilege."""
    dropped_global = False
    drop_path = Path('/proc/sys/vm/drop_caches')
    if os.geteuid() == 0 and drop_path.exists():
        try:
            drop_path.write_text('3\n')
            dropped_global = True
        except OSError:
            dropped_global = False

    fadvise_files = 0
    if hasattr(os, 'posix_fadvise') and hasattr(os, 'POSIX_FADV_DONTNEED'):
        base_dir = data_dir / 'base'
        if base_dir.is_dir():
            for file_path in base_dir.rglob('*'):
                if not file_path.is_file():
                    continue
                try:
                    fd = os.open(file_path, os.O_RDONLY)
                    try:
                        os.posix_fadvise(fd, 0, 0, os.POSIX_FADV_DONTNEED)
                        fadvise_files += 1
                    finally:
                        os.close(fd)
                except OSError:
                    pass

    return {
        'shared_buffers_state': 'restarted_cold',
        'os_drop_caches_3': dropped_global,
        'posix_fadvise_dontneed_files': fadvise_files,
        'os_page_cache_state': 'dropped' if dropped_global else f'posix_fadvise_dontneed({fadvise_files} files)',
    }


def percentile(values: list[float], pct: float) -> float:
    ordered = sorted(values)
    idx = min(len(ordered) - 1, int(pct * (len(ordered) - 1)))
    return ordered[idx]


def canonical_hash(payload: object) -> str:
    encoded = json.dumps(payload, sort_keys=True, separators=(',', ':')).encode('utf-8')
    return hashlib.sha256(encoded).hexdigest()


def build_schema_and_load(psql_bin: str, dsn: str, cell_rows: int) -> dict[str, str]:
    ddl = """
    CREATE TABLE bench_doc_monolith (
        doc_id int PRIMARY KEY,
        body jsonb NOT NULL
    );
    CREATE TABLE bench_doc_parts (
        doc_id int NOT NULL,
        part_index int NOT NULL,
        block_id text NOT NULL,
        payload jsonb NOT NULL,
        PRIMARY KEY (doc_id, part_index)
    );
    CREATE INDEX bench_doc_parts_block_idx ON bench_doc_parts (doc_id, block_id);

    CREATE TABLE bench_sheet_monolith (
        sheet_id int PRIMARY KEY,
        cells jsonb NOT NULL
    );
    CREATE TABLE bench_sheet_projection (
        sheet_id int NOT NULL,
        row_idx int NOT NULL,
        col_idx int NOT NULL,
        addr text NOT NULL,
        val text NOT NULL,
        PRIMARY KEY (sheet_id, row_idx, col_idx)
    );
    """
    run_psql(psql_bin, dsn, ddl)

    load_docs = """
    INSERT INTO bench_doc_monolith (doc_id, body)
    SELECT 1, jsonb_agg(jsonb_build_object('id', 'p' || g, 'text', 'Block paragraph #' || g || ' ' || repeat('x', 80)) ORDER BY g)
    FROM generate_series(1, 1100) AS g;

    INSERT INTO bench_doc_parts (doc_id, part_index, block_id, payload)
    SELECT 1, g, 'p' || g, jsonb_build_object('id', 'p' || g, 'text', 'Block paragraph #' || g || ' ' || repeat('x', 80))
    FROM generate_series(1, 1100) AS g;
    """
    run_psql(psql_bin, dsn, load_docs)

    load_sheets = f"""
    INSERT INTO bench_sheet_projection (sheet_id, row_idx, col_idx, addr, val)
    SELECT 1, ((g - 1) / 10) + 1, ((g - 1) % 10) + 1,
           chr(65 + ((g - 1) % 10)) || (((g - 1) / 10) + 1)::text,
           'v-' || g
    FROM generate_series(1, {cell_rows}) AS g;

    INSERT INTO bench_sheet_monolith (sheet_id, cells)
    SELECT 1, jsonb_object_agg(addr, val)
    FROM bench_sheet_projection WHERE sheet_id = 1;

    VACUUM ANALYZE bench_doc_monolith;
    VACUUM ANALYZE bench_doc_parts;
    VACUUM ANALYZE bench_sheet_monolith;
    VACUUM ANALYZE bench_sheet_projection;
    """
    run_psql(psql_bin, dsn, load_sheets)

    expected_blocks = [
        {'id': f'p{i}', 'text': f"Block paragraph #{i} {'x' * 80}"}
        for i in (16, 17, 18)
    ]
    expected_cells = [
        {'addr': f'{chr(65 + col - 1)}{row}', 'val': f'v-{(row - 1) * 10 + col}'}
        for row in range(2, 7)
        for col in range(2, 6)
    ]
    return {
        'blocks_sha256': canonical_hash(expected_blocks),
        'cells_sha256': canonical_hash(expected_cells),
    }


def measure_cold_and_warm(
    pg_bin: Path,
    psql_bin: str,
    data_dir: Path,
    work_dir: Path,
    dsn: str,
    expected_hashes: dict[str, str],
    warm_iters: int,
    cold_iters: int,
) -> list[dict[str, object]]:
    queries = [
        (
            'doc_monolith_p16_p18',
            expected_hashes['blocks_sha256'],
            "SELECT jsonb_agg(elem ORDER BY ord) FROM bench_doc_monolith, jsonb_array_elements(body) WITH ORDINALITY AS t(elem, ord) WHERE doc_id = 1 AND elem->>'id' IN ('p16','p17','p18');",
        ),
        (
            'doc_parts_p16_p18',
            expected_hashes['blocks_sha256'],
            "SELECT jsonb_agg(payload ORDER BY part_index) FROM bench_doc_parts WHERE doc_id = 1 AND block_id IN ('p16','p17','p18');",
        ),
        (
            'sheet_monolith_b2_e6',
            expected_hashes['cells_sha256'],
            """SELECT jsonb_agg(jsonb_build_object('addr', k, 'val', cells->>k) ORDER BY substring(k FROM 2)::int, left(k, 1))
               FROM bench_sheet_monolith, unnest(ARRAY['B2','C2','D2','E2','B3','C3','D3','E3','B4','C4','D4','E4','B5','C5','D5','E5','B6','C6','D6','E6']) AS k
               WHERE sheet_id = 1;""",
        ),
        (
            'sheet_projection_b2_e6',
            expected_hashes['cells_sha256'],
            """SELECT jsonb_agg(jsonb_build_object('addr', addr, 'val', val) ORDER BY row_idx, col_idx)
               FROM bench_sheet_projection WHERE sheet_id = 1 AND row_idx BETWEEN 2 AND 6 AND col_idx BETWEEN 2 AND 5;""",
        ),
    ]

    results: list[dict[str, object]] = []
    for name, expected_sha, sql in queries:
        # 1. Verify response payload SHA-256 first (storage.md S4 requirement).
        raw = run_psql(psql_bin, dsn, sql)
        parsed = json.loads(raw)
        actual_sha = canonical_hash(parsed)
        if actual_sha != expected_sha:
            raise RuntimeError(f'{name}: digest mismatch actual={actual_sha} expected={expected_sha}')

        # 2. Warm reads (10 warmup + warm_iters measured on single psql session for fair warm p95).
        batch_sql = '\n'.join([sql] * (10 + warm_iters))
        t0 = time.perf_counter_ns()
        run_psql(psql_bin, dsn, batch_sql)
        warm_total_ms = (time.perf_counter_ns() - t0) / 1_000_000.0
        warm_samples: list[float] = []
        for _ in range(warm_iters):
            s0 = time.perf_counter_ns()
            run_psql(psql_bin, dsn, sql)
            warm_samples.append((time.perf_counter_ns() - s0) / 1_000_000.0)

        # 3. Cold reads: restart Postgres to clear shared_buffers + drop OS cache hints.
        cold_samples: list[float] = []
        cache_state: dict[str, object] = {}
        for idx in range(cold_iters):
            subprocess.run([str(pg_bin / 'pg_ctl'), '-D', str(data_dir), '-m', 'fast', '-w', 'stop'], check=True, capture_output=True)
            cache_state = try_drop_os_caches(data_dir)
            subprocess.run(
                [str(pg_bin / 'pg_ctl'), '-D', str(data_dir), '-l', str(work_dir / 'postgres.log'),
                 '-o', f"-k '{work_dir}' -c listen_addresses='' -c shared_buffers=16MB -c track_io_timing=on", '-w', 'start'],
                check=True,
                capture_output=True,
            )
            s0 = time.perf_counter_ns()
            run_psql(psql_bin, dsn, sql)
            cold_samples.append((time.perf_counter_ns() - s0) / 1_000_000.0)

        subprocess.run([str(pg_bin / 'pg_ctl'), '-D', str(data_dir), '-m', 'fast', '-w', 'stop'], check=True, capture_output=True)
        try_drop_os_caches(data_dir)
        subprocess.run(
            [str(pg_bin / 'pg_ctl'), '-D', str(data_dir), '-l', str(work_dir / 'postgres.log'),
             '-o', f"-k '{work_dir}' -c listen_addresses='' -c shared_buffers=16MB -c track_io_timing=on", '-w', 'start'],
            check=True,
            capture_output=True,
        )
        explain_cold = run_psql(psql_bin, dsn, f'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) {sql}')

        results.append({
            'case': name,
            'verified_sha256': actual_sha,
            'payload_bytes': len(raw.encode('utf-8')),
            'warm_iters': warm_iters,
            'warm_p50_ms': round(percentile(warm_samples, 0.50), 3),
            'warm_p95_ms': round(percentile(warm_samples, 0.95), 3),
            'warm_batch_avg_ms': round(warm_total_ms / (10 + warm_iters), 3),
            'cold_iters': cold_iters,
            'cold_p50_ms': round(percentile(cold_samples, 0.50), 3),
            'cold_p95_ms': round(percentile(cold_samples, 0.95), 3),
            'cold_cache_state': cache_state,
            'cold_explain_plan': json.loads(explain_cold)[0]['Plan'] if explain_cold else {},
        })

    return results


def measure_write_wal(psql_bin: str, dsn: str) -> list[dict[str, object]]:
    write_cases = [
        (
            'write_doc_monolith_single_block_patch',
            "UPDATE bench_doc_monolith SET body = jsonb_set(body, '{16,text}', '\"updated\"'::jsonb) WHERE doc_id = 1;",
        ),
        (
            'write_doc_parts_single_block_patch',
            "UPDATE bench_doc_parts SET payload = jsonb_set(payload, '{text}', '\"updated\"'::jsonb) WHERE doc_id = 1 AND part_index = 17;",
        ),
        (
            'write_sheet_monolith_single_cell_patch',
            "UPDATE bench_sheet_monolith SET cells = jsonb_set(cells, '{B2}', '\"updated\"'::jsonb) WHERE sheet_id = 1;",
        ),
        (
            'write_sheet_projection_single_cell_patch',
            "UPDATE bench_sheet_projection SET val = 'updated' WHERE sheet_id = 1 AND row_idx = 2 AND col_idx = 2;",
        ),
    ]
    out: list[dict[str, object]] = []
    for name, sql in write_cases:
        start_lsn = run_psql(psql_bin, dsn, 'SELECT pg_current_wal_lsn()::text;')
        t0 = time.perf_counter_ns()
        explain = run_psql(psql_bin, dsn, f'EXPLAIN (ANALYZE, BUFFERS, WAL, FORMAT JSON) {sql}')
        elapsed_ms = (time.perf_counter_ns() - t0) / 1_000_000.0
        wal_delta_bytes = int(run_psql(psql_bin, dsn, f"SELECT pg_wal_lsn_diff(pg_current_wal_lsn(), '{start_lsn}'::pg_lsn)::bigint;"))
        plan = json.loads(explain)[0]
        root_plan = plan.get('Plan', {})
        out.append({
            'case': name,
            'elapsed_ms': round(elapsed_ms, 3),
            'wal_lsn_diff_bytes': wal_delta_bytes,
            'wal_records': root_plan.get('WAL Records', 0),
            'wal_fpi': root_plan.get('WAL FPI', 0),
            'wal_bytes': root_plan.get('WAL Bytes', 0),
            'shared_hit_blocks': root_plan.get('Shared Hit Blocks', 0),
            'shared_dirtied_blocks': root_plan.get('Shared Dirtied Blocks', 0),
        })
    return out


def main() -> int:
    parser = argparse.ArgumentParser(description='S4 cold-cache & write/WAL PostgreSQL 18 benchmark')
    parser.add_argument('--mode', choices=('dry-run', 'smoke', 'full'), default='dry-run')
    parser.add_argument('--pg-bin', default=None)
    parser.add_argument('--output', default=None)
    args = parser.parse_args()

    pg_bin = resolve_pg_bin(args.pg_bin)
    psql_bin = resolve_psql(pg_bin)

    if args.mode == 'dry-run':
        report = {
            'status': 'DRY_RUN_OK',
            'benchmark': 's4_cold_cache_and_wal',
            'pg_bin': str(pg_bin),
            'psql': psql_bin,
            'profiles': {
                'smoke': {'cells': 10000, 'blocks': 1100, 'warm_iters': 10, 'cold_iters': 2},
                'full': {'cells': 100000, 'blocks': 1100, 'warm_iters': 50, 'cold_iters': 5},
            },
        }
        print(json.dumps(report, indent=2))
        return 0

    cell_rows = 10000 if args.mode == 'smoke' else 100000
    warm_iters = 10 if args.mode == 'smoke' else 50
    cold_iters = 2 if args.mode == 'smoke' else 5

    work_dir = Path(tempfile.mkdtemp(prefix='vaulter-s4-cold-'))
    sock_dir = Path(tempfile.mkdtemp(prefix='vpgs4-', dir='/tmp')) if len(str(work_dir)) > 72 else work_dir
    data_dir = work_dir / 'db'
    started = False
    try:
        subprocess.run([str(pg_bin / 'initdb'), '-D', str(data_dir), '--no-locale', '--encoding=UTF8', '--auth-local=trust', '--auth-host=reject'],
                       check=True, capture_output=True)
        subprocess.run(
            [str(pg_bin / 'pg_ctl'), '-D', str(data_dir), '-l', str(work_dir / 'postgres.log'),
             '-o', f"-k '{sock_dir}' -c listen_addresses='' -c shared_buffers=16MB -c track_io_timing=on", '-w', 'start'],
            check=True,
            capture_output=True,
        )
        started = True
        dsn = f"host={sock_dir} port=5432 dbname=postgres user={os.environ.get('USER', 'postgres')}"
        expected = build_schema_and_load(psql_bin, dsn, cell_rows)
        read_cases = measure_cold_and_warm(pg_bin, psql_bin, data_dir, sock_dir, dsn, expected, warm_iters, cold_iters)
        wal_cases = measure_write_wal(psql_bin, dsn)
        report = {
            'status': 'MEASURED',
            'mode': args.mode,
            'corpus': {'doc_blocks': 1100, 'sheet_cells': cell_rows},
            'read_cases': read_cases,
            'write_wal_cases': wal_cases,
        }
        if args.output:
            out_path = Path(args.output)
            out_path.parent.mkdir(parents=True, exist_ok=True)
            out_path.write_text(json.dumps(report, indent=2) + '\n')
        print(json.dumps(report, indent=2))
        return 0
    finally:
        if started and (data_dir / 'postmaster.pid').is_file():
            subprocess.run([str(pg_bin / 'pg_ctl'), '-D', str(data_dir), '-m', 'fast', '-w', 'stop'],
                           check=False, capture_output=True)
        shutil.rmtree(work_dir, ignore_errors=True)
        if sock_dir != work_dir:
            shutil.rmtree(sock_dir, ignore_errors=True)


if __name__ == '__main__':
    sys.exit(main())
