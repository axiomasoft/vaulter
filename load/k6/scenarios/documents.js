// Document editing (OP-28 read content, OP-29 autosave, OP-30 list
// versions, OP-31 named-version snapshot, OP-32 restore, OP-33 export,
// OP-38 comment + broadcast). Operates on the SEEDED
// document-kind nodes (the seeded `documents` folder) — a shared pool, deliberately: co-edit
// contention is exactly what this scenario means to exercise, not something to avoid.
// The activity feed (OP-25/26) is Scope Excluded here — it lives in mixed.js, run
// alongside the background writer (`load:activity-write`) or it measures fiction.
import http from 'k6/http';
import { check } from 'k6';
import { API, TIER, VUS_INTERACTIVE, DURATION, thinkTime } from '../lib/config.js';
import { STORAGE_ID, ACTOR_OWNER } from '../lib/dataset.js';
import { actorHeaders } from '../lib/auth.js';
import { discoverDocumentNodeIds } from '../lib/discovery.js';
import { ttfbMs } from '../lib/metrics.js';
import { trackProtocolStatus } from '../lib/expected.js';

const TAGS = { scenario: 'documents', tier: TIER, class: 'interactive' };
const JSON_HEADERS = { 'Content-Type': 'application/json' };

export const options = {
  scenarios: {
    documents: { executor: 'constant-vus', vus: VUS_INTERACTIVE, duration: DURATION, exec: 'run' },
  },
};

export function setup() {
  return { documentNodeIds: discoverDocumentNodeIds(20) };
}

export function run(data) {
  if (!data || data.documentNodeIds.length === 0) {
    return;
  }

  const nodeId = data.documentNodeIds[Math.floor(Math.random() * data.documentNodeIds.length)];
  const headers = actorHeaders(ACTOR_OWNER);

  // OP-28: open the document.
  const contentRes = http.get(`${API}/nodes/${nodeId}/content`, { headers, tags: { ...TAGS, op: 'OP-28' } });
  ttfbMs.add(contentRes.timings.waiting, TAGS);
  trackProtocolStatus(contentRes, TAGS);
  check(contentRes, { 'docs content 200': (r) => r.status === 200 });

  // OP-29: autosave (the dominant op, operations-catalog.md "Высокая").
  const autosaveRes = http.patch(
    `${API}/nodes/${nodeId}/content`,
    JSON.stringify({ content: { type: 'doc', content: [{ type: 'paragraph', content: [] }] } }),
    { headers: { ...headers, ...JSON_HEADERS }, tags: { ...TAGS, op: 'OP-29' } },
  );
  trackProtocolStatus(autosaveRes, TAGS);
  check(autosaveRes, { 'docs autosave 200': (r) => r.status === 200 });

  // OP-38: add a comment (+ its background broadcast-queue fan-out).
  const commentRes = http.post(
    `${API}/nodes/${nodeId}/comments`,
    JSON.stringify({ body: `load-comment-${__VU}-${__ITER}-${Date.now()}` }),
    { headers: { ...headers, ...JSON_HEADERS }, tags: { ...TAGS, op: 'OP-38' } },
  );
  trackProtocolStatus(commentRes, TAGS);
  check(commentRes, { 'docs comment 201': (r) => r.status === 201 });

  // OP-30/31/32/33 — low-frequency tail ("Низкая").
  if (Math.random() < 0.05) {
    lowFrequencyTail(nodeId, headers);
  }
}

function lowFrequencyTail(nodeId, headers) {
  const versionsRes = http.get(`${API}/nodes/${nodeId}/document-versions`, {
    headers,
    tags: { ...TAGS, op: 'OP-30' },
  });
  check(versionsRes, { 'docs versions 200': (r) => r.status === 200 });

  const exportRes = http.get(`${API}/nodes/${nodeId}/export?format=html`, {
    headers,
    tags: { ...TAGS, op: 'OP-33' },
  });
  check(exportRes, { 'docs export 200': (r) => r.status === 200 });

  const snapshotRes = http.post(
    `${API}/nodes/${nodeId}/document-versions`,
    JSON.stringify({ label: `load-snapshot-${__VU}-${__ITER}-${Date.now()}` }),
    { headers: { ...headers, ...JSON_HEADERS }, tags: { ...TAGS, op: 'OP-31' } },
  );
  check(snapshotRes, { 'docs snapshot 201': (r) => r.status === 201 });

  if (snapshotRes.status !== 201) {
    return;
  }

  const versionId = snapshotRes.json('data.id');

  const restoreRes = http.post(`${API}/nodes/${nodeId}/document-versions/${versionId}/restore`, null, {
    headers,
    tags: { ...TAGS, op: 'OP-32' },
  });
  check(restoreRes, { 'docs restore 201': (r) => r.status === 201 });
}

export default function (data) {
  run(data);
  thinkTime();
}
