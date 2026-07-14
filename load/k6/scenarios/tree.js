// VLT-LOAD P2.4 — tree/navigation mix (OP-12 children, OP-13 shared, OP-14 search,
// OP-15 trash listing, OP-16/19/20/21/22/23/24 a self-contained create→rename→move→
// copy→trash→restore→forceDelete lifecycle). Reads run as the MANY-GRANT actor
// (operations-catalog.md L-06 — NodeReadRepository::candidateNodeIds() N-CTE
// fan-out — the actor whose grant-anchor count actually exercises it) as well as the
// plain reader; writes run as the seed owner (the only actor with Create/Edit/Move/
// Share on the tree, P2.3 seedGrants — Reader-level grants cannot mutate).
import { check } from 'k6';
import { TIER, VUS_INTERACTIVE, DURATION, thinkTime } from '../lib/config.js';
import {
  STORAGE_ID,
  ROOT_NODE_ID,
  WIDE_NODE_ID,
  ACTOR_OWNER,
  ACTOR_NORMAL,
  ACTOR_MANY_GRANT,
  MANY_GRANT_ANCHOR_NODE_IDS,
} from '../lib/dataset.js';
import { actorHeaders } from '../lib/auth.js';
import * as nodes from '../lib/nodes.js';

const TAGS = { scenario: 'tree', tier: TIER, class: 'interactive' };

export const options = {
  scenarios: {
    tree: { executor: 'constant-vus', vus: VUS_INTERACTIVE, duration: DURATION, exec: 'run' },
  },
};

export function setup() {
  return {};
}

function readMix() {
  // many-grant actor exercises L-06's N-CTE fan-out; the plain reader is the baseline
  // to compare it against — both listings/search share the same request shape. The
  // many-grant actor's Reader grants (P2.3 seedGrants) sit on its OWN anchor subtrees,
  // NOT on root — listing root as that actor is a legitimate 403 (no grant there), not
  // the fan-out this scenario means to exercise.
  const asManyGrant = Math.random() < 0.5;
  const headers = actorHeaders(asManyGrant ? ACTOR_MANY_GRANT : ACTOR_NORMAL);
  const listParentId = asManyGrant
    ? MANY_GRANT_ANCHOR_NODE_IDS[Math.floor(Math.random() * MANY_GRANT_ANCHOR_NODE_IDS.length)]
    : ROOT_NODE_ID;

  const listRes = nodes.children(STORAGE_ID, listParentId, headers, { ...TAGS, op: 'OP-12' });
  check(listRes, { 'tree children 200': (r) => r.status === 200 });

  const sharedRes = nodes.shared(headers, { ...TAGS, op: 'OP-13' });
  check(sharedRes, { 'tree shared 200': (r) => r.status === 200 });

  const searchRes = nodes.search(STORAGE_ID, 'file', headers, { ...TAGS, op: 'OP-14' });
  check(searchRes, { 'tree search 200': (r) => r.status === 200 });

  const trashRes = nodes.trashed(STORAGE_ID, headers, { ...TAGS, op: 'OP-15' });
  check(trashRes, { 'tree trash 200': (r) => r.status === 200 });
}

/**
 * A self-contained lifecycle so concurrent iterations never collide on the same node:
 * create → rename → move → copy → trash → restore → forceDelete, all under root.
 */
function writeLifecycle() {
  const headers = actorHeaders(ACTOR_OWNER);
  const suffix = `${__VU}-${__ITER}-${Date.now()}`;

  const createRes = nodes.createFolder(STORAGE_ID, `tree-${suffix}`, ROOT_NODE_ID, headers, { ...TAGS, op: 'OP-16' });

  if (!check(createRes, { 'tree create 201': (r) => r.status === 201 })) {
    return;
  }

  const nodeId = createRes.json('data.id');

  const showRes = nodes.show(nodeId, headers, { ...TAGS, op: 'OP-18' });
  check(showRes, { 'tree show 200': (r) => r.status === 200 });

  const renameRes = nodes.rename(nodeId, `tree-${suffix}-renamed`, headers, { ...TAGS, op: 'OP-19' });
  check(renameRes, { 'tree rename 200': (r) => r.status === 200 });

  // Into the wide-node folder, not back into root — a same-parent move is a documented
  // no-op (MoveNodeAction::execute), which would silently skip exercising the move path.
  const moveRes = nodes.move(nodeId, WIDE_NODE_ID, headers, { ...TAGS, op: 'OP-20' });
  check(moveRes, { 'tree move 200': (r) => r.status === 200 });

  const copyRes = nodes.copy(nodeId, `tree-${suffix}-copy`, ROOT_NODE_ID, headers, { ...TAGS, op: 'OP-21' });
  const copyId = copyRes.status === 201 ? copyRes.json('data.id') : null;
  check(copyRes, { 'tree copy 201': (r) => r.status === 201 });

  if (copyId === null) {
    nodes.trash(nodeId, headers, { ...TAGS, op: 'OP-24' });

    return;
  }

  const trashRes = nodes.trash(copyId, headers, { ...TAGS, op: 'OP-24' });
  check(trashRes, { 'tree trash-node 200': (r) => r.status === 200 });

  const restoreRes = nodes.restore(copyId, headers, { ...TAGS, op: 'OP-22' });
  check(restoreRes, { 'tree restore 200': (r) => r.status === 200 });

  const forceDeleteRes = nodes.forceDelete(copyId, headers, { ...TAGS, op: 'OP-23' });
  check(forceDeleteRes, { 'tree forceDelete 204': (r) => r.status === 204 });

  nodes.forceDelete(nodeId, headers, { ...TAGS, op: 'OP-23' });
}

export function run() {
  // Reads dominate the mix (operations-catalog.md: OP-12/OP-18 "Высокая"); the write
  // lifecycle is the low-frequency mutation tail.
  if (Math.random() < 0.7) {
    readMix();
  } else {
    writeLifecycle();
  }
}

export default function () {
  run();
  thinkTime();
}
