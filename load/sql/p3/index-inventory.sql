-- Index inventory for eight hot table families (load stand uses v_* table names).
\set ON_ERROR_STOP on

SELECT COALESCE(json_agg(row_to_json(inv) ORDER BY inv.family, inv.index_name), '[]'::json)
FROM (
    SELECT
        fam.family,
        fam.base_table,
        i.indexrelid::regclass::text AS index_name,
        i.indisunique AS is_unique,
        i.indisprimary AS is_primary,
        pg_get_indexdef(i.indexrelid) AS indexdef,
        pg_relation_size(i.indexrelid) AS index_bytes,
        COALESCE(s.idx_scan, 0) AS idx_scan,
        COALESCE(s.idx_tup_read, 0) AS idx_tup_read,
        COALESCE(s.idx_tup_fetch, 0) AS idx_tup_fetch
    FROM (
        VALUES
            ('v_nodes', 'v_nodes'),
            ('v_blobs', 'v_blobs'),
            ('v_upload_sessions', 'v_upload_sessions'),
            ('v_upload_parts', 'v_upload_parts'),
            ('v_file_versions', 'v_file_versions'),
            ('v_activity', 'v_activity'),
            ('v_outbox', 'v_outbox'),
            ('v_webhook_deliveries', 'v_webhook_deliveries')
    ) AS fam(family, base_table)
    JOIN pg_class c ON c.relname = fam.base_table AND c.relnamespace = 'public'::regnamespace
    JOIN pg_index i ON i.indrelid = c.oid
    LEFT JOIN pg_stat_user_indexes s ON s.indexrelid = i.indexrelid
) inv;
