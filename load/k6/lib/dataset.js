// The seed manifest (`dataset_id`), staged as `dataset.json`
// next to the scenarios by `load/scripts/run.sh` (the k6 container only mounts
// `load/k6/` read-only — `load/seed/out/` is not itself reachable).
// Read once in the init context; the small id-list is wrapped in SharedArray per
// Implementation Rules — SharedArray is for manifest id-lists ONLY, nothing else
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

// Both are ids, not counts, despite the manifest's own key names: `load:seed`'s manifest
// shape is the source of truth, so the names stay.
export const BACKBONE_LEAF_NODE_ID = manifest.counts.backbone_leaf_node_id;
export const WIDE_NODE_ID = manifest.counts.wide_node_id;

export const MANY_GRANT_ANCHOR_NODE_IDS = new SharedArray('many-grant-anchor-node-ids', function () {
  return manifest.ids.many_grant_anchor_node_ids;
});
