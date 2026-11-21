WITH h AS MATERIALIZED (
  SELECT h.* FROM vbench.part_head h
  JOIN vbench.read_grants g USING (organization_id,document_id)
  WHERE h.organization_id=1 AND h.document_id=1 AND g.user_id=10
    AND h.generation=1 AND h.source_seq=1
), t AS MATERIALIZED (
  SELECT b.* FROM h JOIN vbench.block_part b USING (organization_id,document_id,generation) WHERE b.block_id='p17'
), wanted AS (
  SELECT block_id,order_key,node FROM t
  UNION ALL
  SELECT x.block_id,x.order_key,x.node FROM t
  CROSS JOIN LATERAL (
    SELECT b.block_id,b.order_key,b.node FROM vbench.block_part b
    WHERE b.organization_id=t.organization_id AND b.document_id=t.document_id AND b.generation=t.generation AND b.order_key<t.order_key ORDER BY b.order_key DESC LIMIT 1
  ) x
  UNION ALL
  SELECT x.block_id,x.order_key,x.node FROM t
  CROSS JOIN LATERAL (
    SELECT b.block_id,b.order_key,b.node FROM vbench.block_part b
    WHERE b.organization_id=t.organization_id AND b.document_id=t.document_id AND b.generation=t.generation AND b.order_key>t.order_key ORDER BY b.order_key ASC LIMIT 1
  ) x
)
SELECT jsonb_build_object(
  'generation',(SELECT generation FROM h),'sourceSeq',(SELECT source_seq FROM h),
  'blocks',COALESCE((SELECT jsonb_agg(jsonb_build_object('id',block_id,'node',node) ORDER BY order_key) FROM wanted),'[]'::jsonb)
)::text;
