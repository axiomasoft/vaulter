-- P3.2 — cluster I/O context snapshot (not query-attributed).
\set ON_ERROR_STOP on

SELECT COALESCE(json_agg(row_to_json(snap) ORDER BY snap.backend_type, snap.object, snap.context), '[]'::json)
FROM (
    SELECT
        backend_type,
        object,
        context,
        reads,
        read_time,
        writes,
        write_time,
        extends,
        extend_time,
        hits,
        evictions,
        reuses,
        fsyncs,
        fsync_time
    FROM pg_stat_io
) snap;
