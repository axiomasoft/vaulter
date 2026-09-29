// VLT-LOAD P2.4 — single-shot small-file upload (OP-17), not the multi-GB path: whole
// body in one multipart/form-data POST, contract-capped at 32 MiB (D19).
import http from 'k6/http';
import { check } from 'k6';
import { API, TIER, VUS_UPLOAD, DURATION, SINGLE_SHOT_FILE_SIZE_BYTES, thinkTime } from '../lib/config.js';
import { STORAGE_ID, ACTOR_OWNER } from '../lib/dataset.js';
import { actorHeaders } from '../lib/auth.js';
import { tusChunk, saltedFirstChunk, sliceOf } from '../lib/fixtures.js';
import { recordAcceptedStream, ttfbMs } from '../lib/metrics.js';
import { trackProtocolStatus } from '../lib/expected.js';

const TAGS = { scenario: 'single-shot', tier: TIER, class: 'streaming' };

export const options = {
  scenarios: {
    single_shot: { executor: 'constant-vus', vus: VUS_UPLOAD, duration: DURATION, exec: 'run' },
  },
};

export function setup() {
  return {};
}

/**
 * @return {{complete: boolean, node: object|null}}
 */
export function run() {
  const headers = actorHeaders(ACTOR_OWNER);
  const name = `single-shot-${__VU}-${__ITER}-${Date.now()}.bin`;
  const size = Math.min(SINGLE_SHOT_FILE_SIZE_BYTES, 32 * 1024 * 1024);
  const bytes = saltedFirstChunk(sliceOf(tusChunk(), size), name);

  const start = Date.now();

  const res = http.post(
    `${API}/storages/${STORAGE_ID}/uploads`,
    { file: http.file(bytes, name, 'application/octet-stream') },
    { headers, tags: { ...TAGS, op: 'OP-17' } },
  );

  const elapsedS = (Date.now() - start) / 1000;

  ttfbMs.add(res.timings.waiting, TAGS);
  trackProtocolStatus(res, TAGS);

  recordAcceptedStream(res, bytes.byteLength, elapsedS, { ...TAGS, op: 'OP-17' }, [201], 'sent');

  const complete = check(res, { 'single-shot upload 201': (r) => r.status === 201 });

  return { complete, node: complete ? res.json('data') : null };
}

export default function () {
  run();
  thinkTime();
}
