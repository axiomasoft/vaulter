// VLT-LOAD P2.4 — S3-style multipart (OP-06 putPart, OP-07 complete/abort). The
// session is created through the SAME route as tus (operations-catalog.md §2 — the
// controller hardcodes protocol=tus on every session regardless; S3MultipartController
// does not consult it either, it drives S3MultipartHandler directly — a known
// discrepancy already on record in operations-catalog.md, not re-litigated here).
import http from 'k6/http';
import { check } from 'k6';
import { API, TIER, VUS_UPLOAD, DURATION, S3_PART_BYTES, S3_FILE_SIZE_BYTES, thinkTime } from '../lib/config.js';
import { STORAGE_ID, ACTOR_OWNER } from '../lib/dataset.js';
import { actorHeaders } from '../lib/auth.js';
import { s3Part, saltedFirstChunk, sliceOf } from '../lib/fixtures.js';
import { streamThroughputMiBs, ttfbMs } from '../lib/metrics.js';
import { trackProtocolStatus } from '../lib/expected.js';

const TAGS = { scenario: 's3-multipart', tier: TIER, class: 'streaming' };

export const options = {
  scenarios: {
    s3_multipart: { executor: 'constant-vus', vus: VUS_UPLOAD, duration: DURATION, exec: 'run' },
  },
};

export function setup() {
  return {};
}

/**
 * @return {{uploadId: string|null, complete: boolean, node: object|null}}
 */
export function run() {
  const headers = actorHeaders(ACTOR_OWNER);
  const name = `s3-multipart-${__VU}-${__ITER}-${Date.now()}.bin`;

  const createRes = http.post(
    `${API}/storages/${STORAGE_ID}/resumable-uploads`,
    JSON.stringify({ name, total_size_bytes: S3_FILE_SIZE_BYTES }),
    { headers: { ...headers, 'Content-Type': 'application/json' }, tags: { ...TAGS, op: 'OP-01' } },
  );

  if (!check(createRes, { 's3 create 201': (r) => r.status === 201 })) {
    trackProtocolStatus(createRes, TAGS);

    return { uploadId: null, complete: false, node: null };
  }

  const uploadId = createRes.json('data.upload_id');
  thinkTime();

  const abortsMidway = Math.random() < 0.05;
  const partCount = Math.ceil(S3_FILE_SIZE_BYTES / S3_PART_BYTES);
  let byteOffset = 0;
  let node = null;
  let complete = false;

  for (let partNo = 1; partNo <= partCount; partNo++) {
    if (abortsMidway && partNo === Math.floor(partCount / 2) + 1) {
      const abortRes = http.del(`${API}/resumable-uploads/${uploadId}`, null, {
        headers,
        tags: { ...TAGS, op: 'OP-05' },
      });

      check(abortRes, { 's3 abort 204': (r) => r.status === 204 });

      return { uploadId, complete: false, node: null };
    }

    const remaining = S3_FILE_SIZE_BYTES - byteOffset;
    const size = Math.min(S3_PART_BYTES, remaining);
    const base = partNo === 1 ? saltedFirstChunk(s3Part(), uploadId) : s3Part();
    const part = sliceOf(base, size);
    const start = Date.now();

    const partRes = http.post(
      `${API}/resumable-uploads/${uploadId}/parts?part_no=${partNo}&byte_offset=${byteOffset}`,
      part,
      {
        headers: { ...headers, 'Content-Type': 'application/octet-stream' },
        tags: { ...TAGS, op: 'OP-06' },
      },
    );

    const elapsedS = (Date.now() - start) / 1000;

    if (partNo === 1) {
      ttfbMs.add(partRes.timings.waiting, TAGS);
    }

    trackProtocolStatus(partRes, TAGS);

    if (elapsedS > 0) {
      streamThroughputMiBs.add(part.byteLength / 1024 / 1024 / elapsedS, TAGS);
    }

    if (!check(partRes, { 's3 putPart 201': (r) => r.status === 201 })) {
      return { uploadId, complete: false, node: null };
    }

    byteOffset += size;
  }

  if (abortsMidway) {
    // partCount == 1 and the abort branch above never fired — nothing left to do.
    return { uploadId, complete: false, node: null };
  }

  // Ожидаемый красный (Code Guidance): complete() assembles every part into ONE PHP
  // string before BlobStore.commit (S3MultipartHandler.php:152-188, L-01) — a large
  // enough S3_FILE_SIZE can legitimately 5xx the worker on memory. That is the finding,
  // not a harness bug: the check below records it as a known outcome, never "fixed" by
  // shrinking the file (Implementation Rules / Code Guidance).
  const completeRes = http.post(`${API}/resumable-uploads/${uploadId}/complete`, null, {
    headers,
    tags: { ...TAGS, op: 'OP-07' },
  });

  trackProtocolStatus(completeRes, TAGS);
  complete = completeRes.status === 201;

  if (complete) {
    node = completeRes.json('data');
  } else {
    check(completeRes, { 's3 complete known-red (L-01)': (r) => r.status >= 500 || r.status === 409 });
  }

  return { uploadId, complete, node };
}

export default function () {
  run();
  thinkTime();
}
