// VLT-LOAD P2.4 — setup-only discovery. The P2.3 manifest carries only a few anchor
// ids (many-grant anchors, the backbone leaf, the wide node) — not a full id-list per
// kind (Known Deviations vs the item's original Scope). Scenarios needing a POOL of
// existing nodes look them up the same way a real client would: one/two listing calls,
// run ONCE in setup() — never per iteration, never counted in the mix.
import http from 'k6/http';
import { API } from './config.js';
import { STORAGE_ID, ROOT_NODE_ID, ACTOR_OWNER, WIDE_NODE_ID } from './dataset.js';
import { actorHeaders } from './auth.js';
import * as nodes from './nodes.js';

const SETUP_TAGS = { scenario: 'setup' };

/**
 * A pool of file node ids that actually stream real bytes. The wide-node folder (its
 * children are the FIRST ones the seed's hot/byte-backed blob budget is spent on,
 * P2.3 Completion Notes) mixes byte-backed ("hot") and metadata-only blobs
 * (reference-env.md §2.2) once the hot budget runs out — each candidate is probed with
 * a real (discarded-body) download once so download.js never samples a 404.
 */
export function discoverDownloadableFiles(limit) {
  const listing = nodes.children(STORAGE_ID, WIDE_NODE_ID, actorHeaders(ACTOR_OWNER), SETUP_TAGS);

  if (listing.status !== 200) {
    throw new Error(`download discovery listing HTTP ${listing.status}`);
  }

  const items = listing.json('data') || [];
  const candidates = items.filter((n) => n.kind === 'file').map((n) => n.id).slice(0, limit);
  const verified = [];

  for (const nodeId of candidates) {
    const probe = http.get(`${API}/nodes/${nodeId}/download`, {
      headers: actorHeaders(ACTOR_OWNER),
      tags: SETUP_TAGS,
      responseType: 'none',
      responseCallback: http.expectedStatuses({ min: 200, max: 399 }, 404),
    });

    if (probe.status === 200) {
      verified.push(nodeId);
    }
  }

  if (verified.length === 0) throw new Error('mandatory download pool is empty');
  return verified;
}

/**
 * The `documents` folder P2.3's SeedLoadDatasetCommand::seedDocumentVersions() creates
 * directly under root (fixed name, not in the manifest).
 */
export function discoverDocumentsFolderId() {
  const listing = nodes.children(STORAGE_ID, ROOT_NODE_ID, actorHeaders(ACTOR_OWNER), SETUP_TAGS);
  if (listing.status !== 200) throw new Error(`documents discovery listing HTTP ${listing.status}`);
  const items = listing.json('data') || [];
  const folder = items.find((n) => n.kind === 'folder' && n.name === 'documents');

  if (!folder) throw new Error('mandatory documents folder is missing');
  return folder.id;
}

/**
 * The seeded document-kind node ids (P2.3 §node_document_versions) — the shared pool
 * docs-collab.js co-edits, exactly the contention the scenario means to exercise.
 */
export function discoverDocumentNodeIds(limit) {
  const documentsFolderId = discoverDocumentsFolderId();

  const listing = nodes.children(STORAGE_ID, documentsFolderId, actorHeaders(ACTOR_OWNER), SETUP_TAGS);
  if (listing.status !== 200) throw new Error(`documents discovery listing HTTP ${listing.status}`);
  const items = listing.json('data') || [];

  const ids = items.filter((n) => n.kind === 'document').map((n) => n.id).slice(0, limit);
  if (ids.length === 0) throw new Error('mandatory documents pool is empty');
  return ids;
}

/**
 * A single reader share token on a downloadable node, for the OP-10 share-download
 * path — the manifest carries no token (node_shares are seeded, but the raw token is
 * returned exactly once at mint time and never persisted, ShareController::store).
 * Returns null when no downloadable candidate exists yet (a scenario using it must
 * tolerate that — no share-download variant that run, not a failure).
 */
export function mintShareToken(nodeId) {
  if (nodeId === null || nodeId === undefined) {
    return null;
  }

  const res = http.post(
    `${API}/nodes/${nodeId}/shares`,
    JSON.stringify({ level: 'reader', scope: 'item' }),
    { headers: { ...actorHeaders(ACTOR_OWNER), 'Content-Type': 'application/json' }, tags: SETUP_TAGS },
  );

  return res.status === 201 ? res.json('data.token') : null;
}
