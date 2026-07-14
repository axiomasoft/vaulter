// VLT-LOAD P2.4 — the seed manifest (P2.3 `dataset_id`), staged as `dataset.json`
// next to the scenarios by `load/scripts/run.sh` (the k6 container only mounts
// `load/k6/` read-only — `load/seed/out/` is not itself reachable, harness.md §3/§5).
// Read once in the init context; the small id-list is wrapped in SharedArray per
// Implementation Rules (P2.4) — SharedArray is for manifest id-lists ONLY, nothing else
// here is large enough to need it.
import { SharedArray } from 'k6/data';

const raw = open('../dataset.json');
const manifest = JSON.parse(raw);

export const DATASET_ID = manifest.dataset_id;
export const STORAGE_ID = manifest.storage_id;
export const ROOT_NODE_ID = manifest.root_node_id;

export const ACTOR_OWNER = manifest.actors.seed_owner;
export const ACTOR_NORMAL = manifest.actors.normal;
export const ACTOR_MANY_GRANT = manifest.actors.many_grant;

// Both are id's, not counts, despite the manifest's own key names (P2.3 schema — see
// Known Deviations, not fixed here: P2.3 is closed, `load:seed`'s manifest shape is its
// SSOT).
export const BACKBONE_LEAF_NODE_ID = manifest.counts.backbone_leaf_node_id;
export const WIDE_NODE_ID = manifest.counts.wide_node_id;

export const MANY_GRANT_ANCHOR_NODE_IDS = new SharedArray('many-grant-anchor-node-ids', function () {
  return manifest.ids.many_grant_anchor_node_ids;
});
