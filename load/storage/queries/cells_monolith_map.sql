WITH h AS MATERIALIZED (
  -- Detoast/extract the large cells object once; do not benchmark 20 avoidable full extractions.
  SELECT m.generation,m.source_seq,m.body#>'{sheets,s1,cells}' AS cells
  FROM vbench.monolith m JOIN vbench.read_grants g USING (organization_id,document_id)
  WHERE m.organization_id=1 AND m.document_id=2 AND g.user_id=10
    AND m.generation=1 AND m.source_seq=1
), coords AS (SELECT r AS row_no,c AS col_no FROM generate_series(2,6) r CROSS JOIN generate_series(2,5) c)
SELECT jsonb_build_object(
  'generation',(SELECT generation FROM h),'sourceSeq',(SELECT source_seq FROM h),
  'cells',COALESCE((SELECT jsonb_agg(jsonb_build_object('row',r.row_no,'col',r.col_no,
             'cell',h.cells->(r.row_no::text||':'||r.col_no::text)) ORDER BY r.row_no,r.col_no)
             FROM h CROSS JOIN coords r),'[]'::jsonb)
)::text;
