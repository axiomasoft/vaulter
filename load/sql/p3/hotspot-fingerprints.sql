-- P3.2 — mandatory view fingerprints (admin); separate from top-20 ranking.
\set ON_ERROR_STOP on

SELECT COALESCE(json_agg(row_to_json(h) ORDER BY h.view_name), '[]'::json)
FROM (
    SELECT
        v.view_name,
        s.queryid::text AS query_fingerprint,
        s.total_exec_time,
        s.calls
    FROM (
        VALUES
            ('v_nodes'),
            ('v_blobs'),
            ('v_upload_sessions'),
            ('v_upload_parts'),
            ('v_file_versions'),
            ('v_activity'),
            ('v_outbox'),
            ('v_webhook_deliveries')
    ) AS v(view_name)
    LEFT JOIN LATERAL (
        SELECT query, queryid, total_exec_time, calls
        FROM pg_stat_statements
        WHERE query ILIKE ('%' || v.view_name || '%')
          AND query NOT ILIKE '%pg_stat_statements%'
        ORDER BY total_exec_time DESC
        LIMIT 1
    ) s ON true
) h;
