-- Dataset volume + statistics freshness (admin, direct-to-postgres).
\set ON_ERROR_STOP on

SELECT json_build_object(
    'pg_version', version(),
    'settings', (
        SELECT COALESCE(json_agg(row_to_json(s) ORDER BY s.name), '[]'::json)
        FROM (
            SELECT name, setting, unit
            FROM pg_settings
            WHERE name IN (
                'shared_preload_libraries',
                'pg_stat_statements.track',
                'pg_stat_statements.max',
                'track_io_timing',
                'shared_buffers',
                'effective_cache_size',
                'random_page_cost',
                'work_mem'
            )
        ) s
    ),
    'pg_stat_statements_info', (
        SELECT row_to_json(i)
        FROM (
            SELECT stats_reset, dealloc
            FROM pg_stat_statements_info
        ) i
    ),
    'tables', (
        SELECT COALESCE(json_agg(row_to_json(t) ORDER BY t.relation), '[]'::json)
        FROM (
            SELECT
                c.relname AS relation,
                c.relkind,
                pg_total_relation_size(c.oid) AS total_bytes,
                pg_relation_size(c.oid) AS heap_bytes,
                pg_indexes_size(c.oid) AS index_bytes,
                COALESCE(st.n_live_tup, 0)::bigint AS live_rows,
                COALESCE(st.n_dead_tup, 0)::bigint AS dead_rows,
                st.last_analyze,
                st.last_autoanalyze
            FROM pg_class c
            LEFT JOIN pg_stat_user_tables st ON st.relid = c.oid
            WHERE c.relnamespace = 'public'::regnamespace
              AND c.relkind IN ('r', 'm', 'p')
              AND c.relname LIKE 'v\_%' ESCAPE '\'
        ) t
    )
);
