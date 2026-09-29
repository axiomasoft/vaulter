-- P3.1 preflight: extensions, pg_stat_statements GUCs, optional track_io_timing evidence.
\set ON_ERROR_STOP on

SELECT extname, extversion
FROM pg_extension
WHERE extname IN ('pg_stat_statements', 'hypopg')
ORDER BY extname;

SELECT name, setting, unit
FROM pg_settings
WHERE name IN (
    'shared_preload_libraries',
    'pg_stat_statements.track',
    'pg_stat_statements.max',
    'track_io_timing'
)
ORDER BY name;

SELECT stats_reset, dealloc
FROM pg_stat_statements_info;
