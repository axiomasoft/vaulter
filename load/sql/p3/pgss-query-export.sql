-- Export normalized query text for workload fingerprints (admin).
\set ON_ERROR_STOP on

SELECT COALESCE(json_agg(row_to_json(q) ORDER BY q.total_exec_time DESC), '[]'::json)
FROM (
    SELECT
        queryid::text AS query_fingerprint,
        query,
        calls::bigint AS calls,
        total_exec_time,
        mean_exec_time
    FROM pg_stat_statements
    WHERE query NOT ILIKE '%pg_stat_statements%'
      AND query NOT ILIKE '%pg_stat_io%'
      AND query NOT ILIKE '%hypopg%'
      AND query NOT ILIKE '%EXPLAIN%'
) q;
