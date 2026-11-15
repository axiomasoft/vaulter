-- Ranked pg_stat_statements export (admin); excludes profiler/admin noise.
\set ON_ERROR_STOP on

SELECT COALESCE(json_agg(row_to_json(ranked) ORDER BY ranked.total_exec_time DESC), '[]'::json)
FROM (
    SELECT
        queryid::text AS queryid,
        queryid::text AS query_fingerprint,
        calls::bigint AS calls,
        total_exec_time,
        mean_exec_time,
        stddev_exec_time,
        rows::bigint AS rows,
        shared_blks_hit::bigint AS shared_blks_hit,
        shared_blks_read::bigint AS shared_blks_read,
        shared_blks_dirtied::bigint AS shared_blks_dirtied,
        shared_blks_written::bigint AS shared_blks_written,
        temp_blks_read::bigint AS temp_blks_read,
        temp_blks_written::bigint AS temp_blks_written,
        wal_bytes::bigint AS wal_bytes
    FROM pg_stat_statements
    WHERE query NOT ILIKE '%pg_stat_statements%'
      AND query NOT ILIKE '%pg_stat_io%'
      AND query NOT ILIKE '%hypopg%'
      AND query NOT ILIKE '%EXPLAIN%'
    ORDER BY total_exec_time DESC
    LIMIT 25
) ranked;

SELECT dealloc AS dealloc_after_export
FROM pg_stat_statements_info;
