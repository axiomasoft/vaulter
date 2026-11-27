-- This diagnostic deliberately keeps a two-range B-tree predicate.
-- Do not assume that output row count equals index entries/pages visited.
WITH h AS MATERIALIZED (
  SELECT h.* FROM vbench.projection_head h JOIN vbench.read_grants g USING (organization_id,document_id)
  WHERE h.organization_id=1 AND h.document_id=2 AND g.user_id=10
    AND h.generation=1 AND h.source_seq=1
), coords AS (SELECT r AS row_no,c AS col_no FROM generate_series(2,6) r CROSS JOIN generate_series(2,5) c), found AS MATERIALIZED (
  SELECT p.row_no,p.col_no,p.cell FROM h JOIN vbench.cell_projection p
    USING (organization_id,document_id,generation,build_id)
  WHERE p.sheet_id='s1' AND p.row_no BETWEEN 2 AND 6 AND p.col_no BETWEEN 2 AND 5
)
SELECT jsonb_build_object(
  'generation',(SELECT generation FROM h),'sourceSeq',(SELECT source_seq FROM h),
  'cells',COALESCE((SELECT jsonb_agg(jsonb_build_object('row',r.row_no,'col',r.col_no,'cell',p.cell) ORDER BY r.row_no,r.col_no)
             FROM h CROSS JOIN coords r LEFT JOIN found p USING (row_no,col_no)),'[]'::jsonb)
)::text;
