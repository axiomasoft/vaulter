// P5.2 product case matrix. Planes, surfaces, shapes, and N match
// load/gates/p5_concurrency.py. Direct SQL stays unlabeled as an OP or SLO.
// Same-resource 409s are protocol results and are not scored against the ≤5% budget.

const SATURATION_N = [1, 8, 32, 200];
const CLOSED_N = [1, 8, 32, 200, 201];

const CELL_SPECS = [
  ['product-realistic', 'upload-tus', 'different-resource', SATURATION_N],
  ['product-realistic', 'upload-s3', 'different-resource', SATURATION_N],
  ['product-realistic', 'cas-lock', 'normal-key', SATURATION_N],
  ['product-realistic', 'subtree-move', 'disjoint-tree', SATURATION_N],
  ['product-realistic', 'collab-issuance', 'issuance-burst', SATURATION_N],
  ['product-realistic', 'sweeper-overlap', 'live-patch-overlap', [1, 8, 32]],
  ['product-worst-case', 'upload-tus', 'same-resource', SATURATION_N],
  ['product-worst-case', 'upload-s3', 'same-resource', SATURATION_N],
  ['product-worst-case', 'cas-lock', 'int4-collision', SATURATION_N],
  ['product-worst-case', 'subtree-move', 'overlapping-tree', SATURATION_N],
  ['primitive-control', 'advisory-primitive', 'xact-release', [1]],
  ['primitive-control', 'advisory-primitive', 'try-lock-nowait', CLOSED_N],
  ['primitive-control', 'advisory-primitive', 'blocking-wait', [1, 8, 32]],
  ['primitive-control', 'pgbouncer-queue', 'pool-queue', CLOSED_N],
  ['primitive-control', 'pgbouncer-queue', 'pool-timeout', [200]],
  ['dirty-cardinality-probe', 'sweeper-overlap', 'dirty-20k', [1]],
  ['dirty-cardinality-probe', 'sweeper-overlap', 'dirty-1m', [1]],
];

const PRODUCT_OP = {
  'upload-tus': { op: 'OP-02', slo: 'SLO-40' },
  'upload-s3': { op: 'OP-06', slo: 'SLO-43' },
  'cas-lock': { op: null, slo: null },
  'subtree-move': { op: 'OP-20', slo: 'SLO-13' },
  'collab-issuance': { op: 'OP-34', slo: 'SLO-28' },
  'sweeper-overlap': { op: 'OP-44', slo: null },
};

function envValue(name) {
  if (typeof __ENV !== 'undefined' && __ENV[name] != null && __ENV[name] !== '') {
    return __ENV[name];
  }

  if (typeof process !== 'undefined' && process.env && process.env[name]) {
    return process.env[name];
  }

  return '';
}

export function cellId(plane, surface, shape, n) {
  return `${plane}.${surface}.${shape}.n${n}`;
}

export function catalog() {
  const cells = [];

  for (const [plane, surface, shape, sizes] of CELL_SPECS) {
    for (const n of sizes) {
      cells.push({
        cell_id: cellId(plane, surface, shape, n),
        plane,
        surface,
        shape,
        n,
        boundary_control: n === 201,
      });
    }
  }

  return cells;
}

export function classify(cell) {
  const row = {
    cell_id: cell.cell_id,
    http_5xx_success: false,
    op: null,
    slo: null,
    error_budget: 'not-a-product-slo',
    expected_409: 'not-applicable',
    progress_owners: null,
    tier_l_claim: false,
    expected_statuses: [],
  };

  if (cell.plane === 'primitive-control') {
    return row;
  }

  if (cell.plane === 'dirty-cardinality-probe') {
    row.op = 'OP-44';
    row.expected_409 = 'not-applicable';
    row.tier_l_claim = false;

    return row;
  }

  const named = PRODUCT_OP[cell.surface] || { op: null, slo: null };
  row.op = named.op;
  row.slo = cell.shape === 'live-patch-overlap' ? null : named.slo;
  row.error_budget = 'section-0.4-5xx';
  row.expected_409 = 'not-expected';
  row.expected_statuses = [200];

  if (cell.plane === 'product-worst-case') {
    row.error_budget = 'excluded-from-5pct';
    row.expected_409 = 'not-compared-to-5pct';
  }

  if (cell.shape === 'same-resource') {
    row.expected_409 = 'protocol-owner-plus-conflicts';
    row.progress_owners = 1;
    row.expected_statuses = [204, 409];
    row.error_budget = 'excluded-from-5pct';
  } else if (cell.shape === 'different-resource') {
    row.expected_409 = 'budgeted-5pct';
    row.error_budget = 'within-5pct';
    row.expected_statuses = [204];
  } else if (cell.shape === 'int4-collision') {
    row.expected_409 = 'not-protocol';
    row.expected_statuses = [200];
    row.error_budget = 'section-0.4-5xx';
  } else if (cell.shape === 'live-patch-overlap') {
    row.op = 'OP-02+OP-44';
    row.expected_409 = 'not-expected';
    row.expected_statuses = [204];
    row.error_budget = 'section-0.4-5xx';
  }

  return row;
}

export function contractCells() {
  const seen = new Set();
  const selected = [];

  for (const cell of catalog()) {
    if (cell.shape === 'dirty-1m' || cell.n !== 1 || seen.has(cell.surface)) {
      continue;
    }

    seen.add(cell.surface);
    selected.push(cell);
  }

  return selected;
}

export function cellById(id) {
  return catalog().find((cell) => cell.cell_id === id) || null;
}

export function actorResource(cell, actorIndex) {
  if (
    cell.shape === 'different-resource' ||
    cell.shape === 'normal-key' ||
    cell.shape === 'disjoint-tree'
  ) {
    return { resource: actorIndex, role: 'owner' };
  }

  if (
    cell.shape === 'same-resource' ||
    cell.shape === 'int4-collision' ||
    cell.shape === 'overlapping-tree' ||
    cell.shape === 'issuance-burst' ||
    cell.shape === 'live-patch-overlap'
  ) {
    return { resource: 0, role: actorIndex === 0 ? 'owner' : 'contender' };
  }

  return { resource: 0, role: 'probe' };
}

export function matrixSignature() {
  return catalog().map((cell) => classify(cell));
}

export function contractMode() {
  return envValue('P5_CONTRACT') === '1';
}
