-- P3.1 — bounded pg_stat_statements sample (admin capture; not authoritative top-20 — P3.2 ranks).
\set ON_ERROR_STOP on

SELECT
    queryid,
    left(query, 120) AS query_prefix,
    calls,
    total_exec_time,
    mean_exec_time,
    rows,
    shared_blks_hit,
    shared_blks_read
FROM pg_stat_statements
WHERE query NOT LIKE '%pg_stat_statements%'
ORDER BY total_exec_time DESC
LIMIT 20;

SELECT dealloc AS dealloc_after_capture
FROM pg_stat_statements_info;
