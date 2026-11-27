WITH h AS MATERIALIZED (
  SELECT m.generation,m.source_seq,m.body->'content' AS nodes
  FROM vbench.monolith m
  JOIN vbench.read_grants g USING (organization_id,document_id)
  WHERE m.organization_id=1 AND m.document_id=1 AND g.user_id=10
    AND m.generation=1 AND m.source_seq=1
),
b AS MATERIALIZED (
  SELECT e.node,e.ord FROM h
  CROSS JOIN LATERAL jsonb_array_elements(h.nodes) WITH ORDINALITY e(node,ord)
), t AS (SELECT ord FROM b WHERE node->'attrs'->>'blockId'='p17')
SELECT jsonb_build_object(
  'generation',(SELECT generation FROM h),'sourceSeq',(SELECT source_seq FROM h),
  'blocks',COALESCE((SELECT jsonb_agg(jsonb_build_object('id',b.node->'attrs'->>'blockId','node',b.node) ORDER BY b.ord)
                    FROM b CROSS JOIN t WHERE b.ord BETWEEN t.ord-1 AND t.ord+1),'[]'::jsonb)
)::text;
