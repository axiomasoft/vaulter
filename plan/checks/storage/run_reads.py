#!/usr/bin/env python3
"""Read microbenchmarks. Requires psycopg 3 and a separately provisioned PostgreSQL.

The measured boundary is execute + fetchone of one SQL statement on an existing
connection. It includes database output serialization, network and driver cost,
but excludes SDK/HTTP, search and authorization logic outside these fixture SQLs.
"""
import argparse
import collections
import hashlib
import json
import math
import os
import platform
import statistics
import threading
import time
from datetime import datetime, timezone
from pathlib import Path


class Meter:
    def __init__(self, conn):
        self.conn = conn
        self.counts = collections.Counter()

    def query(self, sql, category="diagnostic"):
        self.counts[category] += 1
        with self.conn.cursor() as cur:
            cur.execute(sql)
            return cur.fetchall() if cur.description else []


class RssSampler:
    def __init__(self, backend_pid, local_backend):
        self.pids = {"client": os.getpid()}
        if local_backend:
            self.pids["backend"] = backend_pid
        self.peak = collections.defaultdict(int)
        self.high_water = collections.defaultdict(int)
        self.samples = collections.Counter()
        self.stop = threading.Event()
        self.thread = threading.Thread(target=self.poll, daemon=True)

    def sample_processes(self):
        for label, pid in self.pids.items():
            try:
                values = {}
                for line in Path(f"/proc/{pid}/status").read_text().splitlines():
                    if line.startswith(("VmRSS:", "VmHWM:")):
                        key, value, unit = line.split()
                        values[key.rstrip(":")] = int(value) * 1024
                self.peak[label] = max(self.peak[label], values.get("VmRSS", 0))
                self.high_water[label] = max(self.high_water[label], values.get("VmHWM", 0))
                self.samples[label] += 1
            except (FileNotFoundError, PermissionError, ValueError):
                pass

    def poll(self):
        while not self.stop.is_set():
            self.sample_processes()
            self.stop.wait(0.005)

    def start(self):
        self.thread.start()

    def finish(self):
        self.stop.set()
        self.thread.join()
        self.sample_processes()
        return {
            "sampled_peak_rss_bytes": dict(self.peak),
            "observed_process_high_water_rss_bytes": dict(self.high_water),
            "samples": dict(self.samples),
            "sampling_interval_ms": 5,
            "limitations": "RSS includes shared pages; samples may miss transients; high-water marks cover process lifetime. Backend is measured only with --local-backend-proc.",
        }


def safe_stats(meter, sql, warnings):
    try:
        return [row[0] if not isinstance(row[0], str) else json.loads(row[0]) for row in meter.query(sql)]
    except Exception as exc:
        warnings.append(f"Statistics unavailable: {type(exc).__name__}")
        return None


def snapshot(meter, warnings):
    try:
        # Request a flush at the end of this autocommit transaction.
        meter.query("SELECT pg_stat_force_next_flush()")
    except Exception as exc:
        warnings.append(f"Stats flush unavailable: {type(exc).__name__}")
    return {
        "relations": safe_stats(meter, "SELECT row_to_json(s)::text FROM pg_statio_all_tables s WHERE schemaname='vbench' ORDER BY relname", warnings),
        "cluster_io": safe_stats(meter, "SELECT row_to_json(s)::text FROM pg_stat_io s ORDER BY backend_type,object,context", warnings),
        "cluster_wal": safe_stats(meter, "SELECT row_to_json(s)::text FROM pg_stat_wal s", warnings),
        "backend_io": safe_stats(meter, "SELECT row_to_json(s)::text FROM pg_stat_get_backend_io(pg_backend_pid()) s ORDER BY object,context", warnings),
        "backend_wal": safe_stats(meter, "SELECT row_to_json(s)::text FROM pg_stat_get_backend_wal(pg_backend_pid()) s", warnings),
    }


def percentile95(values):
    return sorted(values)[max(0, math.ceil(0.95 * len(values)) - 1)]


def write_result(path, result):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def main():
    base = Path(__file__).parent
    parser = argparse.ArgumentParser()
    parser.add_argument("--case", choices=[p.stem for p in sorted((base / "queries").glob("*.sql"))])
    parser.add_argument("--iterations", type=int, default=1000)
    parser.add_argument("--warmup", type=int, default=100)
    parser.add_argument("--cache-state", choices=["warm", "postgres_cold_os_unspecified", "both_cold_user_prepared"], default="warm")
    parser.add_argument("--local-backend-proc", action="store_true", help="Assert that backend PID refers to this machine's /proc.")
    parser.add_argument("--output", type=Path, default=base / "results" / "read_results.json")
    parser.add_argument("--trial-label", default="unspecified")
    parser.add_argument("--no-explain", action="store_true")
    args = parser.parse_args()
    if args.iterations < 1 or args.warmup < 0:
        parser.error("iterations must be positive; warmup nonnegative")
    if args.cache_state != "warm" and (not args.case or args.iterations != 1 or args.warmup != 0):
        parser.error("A cold trial requires one --case, --iterations 1 and --warmup 0. Reset caches outside this process before every independent trial.")
    result = {
        "created_utc": datetime.now(timezone.utc).isoformat(),
        "status": "NOT_RUN", "python_version": platform.python_version(),
        "trial_label": args.trial_label, "cache_state_claimed_by_operator": args.cache_state,
        "cache_reset_performed_by_runner": False,
        "fixture_manifest": json.loads((base / "fixtures" / "fixture_manifest.json").read_text()),
        "cases": [],
    }
    try:
        import psycopg
    except ImportError:
        result["reason"] = "psycopg 3 is not installed; no PostgreSQL measurements were made."
        write_result(args.output, result)
        print(str(args.output))
        return
    dsn = os.environ.get("BENCH_DSN")
    if not dsn:
        result["reason"] = "BENCH_DSN is not set; no PostgreSQL measurements were made."
        write_result(args.output, result)
        print(str(args.output))
        return
    result["psycopg_version"] = psycopg.__version__
    queries = [base / "queries" / f"{args.case}.sql"] if args.case else sorted((base / "queries").glob("*.sql"))
    for file in queries:
        warnings = []
        with psycopg.connect(dsn, autocommit=True) as conn:
            if conn.info.server_version < 180000:
                raise SystemExit("This bundle targets PostgreSQL 18+, including EXPLAIN SERIALIZE.")
            meter = Meter(conn)
            postgres_version = meter.query("SELECT version(),pg_backend_pid()")[0]
            result["postgres_version"] = postgres_version[0]
            settings = meter.query("SELECT name,setting,unit FROM pg_settings WHERE name IN ('block_size','shared_buffers','work_mem','jit','default_toast_compression','track_io_timing','track_wal_io_timing','synchronous_commit','wal_compression','checkpoint_timeout','max_wal_size','effective_cache_size','random_page_cost') ORDER BY name")
            sql = file.read_text().strip().rstrip(";")
            expected_file = "expected_text.json" if file.stem.startswith("text_") else "expected_cells.json"
            expected = json.loads((base / "fixtures" / expected_file).read_text())
            for unused in range(args.warmup):
                meter.query(sql, "warmup_read")
            before = snapshot(meter, warnings)
            sampler = RssSampler(postgres_version[1], args.local_backend_proc)
            sampler.start()
            samples = []
            byte_counts = []
            sample_hash = None
            try:
                for unused in range(args.iterations):
                    started = time.perf_counter_ns()
                    rows = meter.query(sql, "measured_read")
                    elapsed_ms = (time.perf_counter_ns() - started) / 1_000_000
                    if len(rows) != 1 or len(rows[0]) != 1 or not isinstance(rows[0][0], str):
                        raise RuntimeError("Expected one TEXT value containing the complete JSON response.")
                    payload = rows[0][0].encode("utf-8")
                    if json.loads(payload) != expected:
                        raise RuntimeError(f"Unexpected content/freshness/authorization for {file.stem}; not a successful read.")
                    samples.append(elapsed_ms)
                    byte_counts.append(len(payload))
                    sample_hash = hashlib.sha256(payload).hexdigest()
            finally:
                memory = sampler.finish()
            after = snapshot(meter, warnings)
            plan = None
            if not args.no_explain:
                plan = meter.query("EXPLAIN (ANALYZE, BUFFERS, WAL, SETTINGS, MEMORY, SERIALIZE TEXT, TIMING OFF, FORMAT JSON) " + sql, "diagnostic_explain")[0][0]
            case_result = {
                "case": file.stem, "status": "MEASURED", "settings_columns": ["name", "setting", "unit"],
                "query_sha256": hashlib.sha256(file.read_bytes()).hexdigest(),
                "connection_prepare_threshold": conn.prepare_threshold,
                "settings": settings, "samples_ms": samples,
                "metric_boundary": "One SQL execute+fetch on an existing psycopg connection; DB output serialization included; HTTP/SDK excluded.",
                "sample_count": len(samples), "mean_ms": statistics.mean(samples),
                "p95_ms": percentile95(samples) if len(samples) >= 20 else None,
                "p95_method": "nearest rank ceil(0.95*n); suppressed below 20 samples; no statistical confidence claim",
                "response_utf8_bytes": sorted(set(byte_counts)), "response_sha256": sample_hash,
                "response_bytes_exclude": "PostgreSQL wire framing, TLS, HTTP framing and compression",
                "sql_calls_by_category": dict(meter.counts),
                "stats_before_measured_batch": before, "stats_after_measured_batch": after,
                "stats_scope_note": "Relation/cluster counters include concurrent activity; isolate the cluster. Do not double-count parent TOAST counters plus toast-table counters.",
                "memory": memory, "explain_after_batch": plan,
                "explain_note": "This is an additional diagnostic execution after the measured batch. Its cache state is warm; its MEMORY values describe planning, not backend peak RSS.",
                "warnings": warnings,
            }
            result["cases"].append(case_result)
    result["status"] = "MEASURED"
    write_result(args.output, result)
    print(str(args.output))


if __name__ == "__main__":
    main()
