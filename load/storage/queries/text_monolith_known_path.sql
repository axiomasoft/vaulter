-- Addresses 17..19 are valid only for this exact generation/source_seq fixture.
WITH h AS MATERIALIZED (
  SELECT m.generation,m.source_seq,m.body->'content' AS nodes
  FROM vbench.monolith m
  JOIN vbench.read_grants g USING (organization_id,document_id)
  WHERE m.organization_id=1 AND m.document_id=1 AND g.user_id=10
    AND m.generation=1 AND m.source_seq=1
),
b AS (
  SELECT h.nodes->i AS node,i AS ord FROM h CROSS JOIN generate_series(17,19) i
)
SELECT jsonb_build_object(
  'generation',(SELECT generation FROM h),'sourceSeq',(SELECT source_seq FROM h),
  'blocks',COALESCE((SELECT jsonb_agg(jsonb_build_object('id',node->'attrs'->>'blockId','node',node) ORDER BY ord) FROM b),'[]'::jsonb)
)::text;
