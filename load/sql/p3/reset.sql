-- Isolated pg_stat_statements window with JSON evidence lines.
\set ON_ERROR_STOP on

SELECT json_build_object(
    'dealloc_before', (SELECT dealloc FROM pg_stat_statements_info)
);

SELECT pg_stat_statements_reset() AS reset_ok;

SELECT json_build_object(
    'stats_reset', stats_reset,
    'dealloc_after', dealloc
)
FROM pg_stat_statements_info;
