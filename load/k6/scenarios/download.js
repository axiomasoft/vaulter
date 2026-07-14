// VLT-LOAD P2.4 — download (OP-08 current version, OP-09 a specific version, OP-10
// public share-link). Bodies are ALWAYS discarded (RAG-10 / Implementation Rules):
// throughput comes from the known file size and `res.timings`, never from an
// accumulated response body.
import http from 'k6/http';
import { check } from 'k6';
import { API, TIER, VUS_DOWNLOAD, DURATION, thinkTime } from '../lib/config.js';
import { ACTOR_NORMAL } from '../lib/dataset.js';
import { actorHeaders } from '../lib/auth.js';
import { discoverDownloadableFiles, mintShareToken } from '../lib/discovery.js';
import { streamThroughputMiBs, ttfbMs } from '../lib/metrics.js';
import { trackProtocolStatus } from '../lib/expected.js';
import { header } from '../lib/headers.js';

const TAGS = { scenario: 'download', tier: TIER, class: 'streaming' };

export const options = {
  scenarios: {
    download: { executor: 'constant-vus', vus: VUS_DOWNLOAD, duration: DURATION, exec: 'run' },
  },
};

/**
 * Discovery — ONCE per run (not per iteration): a pool of confirmed byte-backed file
 * node ids (harness.md §3 / P2.3 §2.2 hot vs metadata-only blobs), plus one bootstrap
 * share token for OP-10 (see lib/discovery.js — the manifest carries no token).
 */
export function setup() {
  const fileIds = discoverDownloadableFiles(50);
  const shareToken = fileIds.length > 0 ? mintShareToken(fileIds[0]) : null;

  return { fileIds, shareToken };
}

export function run(data) {
  if (!data || data.fileIds.length === 0) {
    return;
  }

  const nodeId = data.fileIds[Math.floor(Math.random() * data.fileIds.length)];
  const headers = actorHeaders(ACTOR_NORMAL);
  const roll = Math.random();

  // OP-10 (share, unauthenticated, throttled by IP) is the rarer path; OP-08 (current)
  // dominates the mix, OP-09 (a named version) a distant second (operations-catalog.md
  // §3: "Высокая" / "Низкая" / "Средняя").
  if (roll < 0.1 && data.shareToken) {
    downloadShare(data.shareToken);

    return;
  }

  if (roll < 0.2) {
    downloadVersion(nodeId, headers);

    return;
  }

  downloadCurrent(nodeId, headers);
}

function downloadCurrent(nodeId, headers) {
  const start = Date.now();

  const res = http.get(`${API}/nodes/${nodeId}/download`, {
    headers,
    tags: { ...TAGS, op: 'OP-08' },
    responseType: 'none',
  });

  recordThroughput(res, start);
  trackProtocolStatus(res, TAGS);
  check(res, { 'download current 200': (r) => r.status === 200 });
}

function downloadVersion(nodeId, headers) {
  const start = Date.now();

  const res = http.get(`${API}/nodes/${nodeId}/versions/1/download`, {
    headers,
    tags: { ...TAGS, op: 'OP-09' },
    responseType: 'none',
  });

  recordThroughput(res, start);
  trackProtocolStatus(res, TAGS);
  check(res, { 'download version 200': (r) => r.status === 200 });
}

function downloadShare(token) {
  const start = Date.now();

  const res = http.get(`${API}/share/${token}/download`, {
    tags: { ...TAGS, op: 'OP-10' },
    responseType: 'none',
  });

  recordThroughput(res, start);
  trackProtocolStatus(res, TAGS);
  check(res, { 'download share 200 or throttled': (r) => r.status === 200 || r.status === 429 });
}

function recordThroughput(res, startedAt) {
  const elapsedS = (Date.now() - startedAt) / 1000;

  ttfbMs.add(res.timings.waiting, TAGS);

  const contentLength = parseInt(header(res, 'Content-Length') || '0', 10);

  if (elapsedS > 0 && contentLength > 0) {
    streamThroughputMiBs.add(contentLength / 1024 / 1024 / elapsedS, TAGS);
  }
}

export default function (data) {
  run(data);
  thinkTime();
}
