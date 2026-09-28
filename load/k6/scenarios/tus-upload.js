// VLT-LOAD P2.4 — tus resumable upload (OP-01 create, OP-02 PATCH-chunk, OP-03
// HEAD-resume, OP-05 abort). Stock k6 only (D22) — no xk6-fasthttp, PATCH is a first-
// class k6 http verb.
import http from 'k6/http';
import { check } from 'k6';
import { API, TIER, VUS_UPLOAD, DURATION, TUS_CHUNK_BYTES, TUS_FILE_SIZE_BYTES, thinkTime } from '../lib/config.js';
import { STORAGE_ID, ACTOR_OWNER } from '../lib/dataset.js';
import { actorHeaders } from '../lib/auth.js';
import { tusChunk, saltedFirstChunk, sliceOf } from '../lib/fixtures.js';
import { recordAcceptedStream, ttfbMs } from '../lib/metrics.js';
import { trackProtocolStatus } from '../lib/expected.js';
import { header } from '../lib/headers.js';

const TUS_VERSION = '1.0.0';
const TAGS = { scenario: 'tus-upload', tier: TIER, class: 'streaming' };

export const options = {
  scenarios: {
    tus_upload: { executor: 'constant-vus', vus: VUS_UPLOAD, duration: DURATION, exec: 'run' },
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
  const name = `tus-upload-${__VU}-${__ITER}-${Date.now()}.bin`;

  const createRes = http.post(
    `${API}/storages/${STORAGE_ID}/resumable-uploads`,
    JSON.stringify({ name, total_size_bytes: TUS_FILE_SIZE_BYTES }),
    {
      headers: { ...headers, 'Content-Type': 'application/json', 'Tus-Resumable': TUS_VERSION },
      tags: { ...TAGS, op: 'OP-01' },
    },
  );

  if (!check(createRes, { 'tus create 201': (r) => r.status === 201 })) {
    trackProtocolStatus(createRes, TAGS);

    return { uploadId: null, complete: false, node: null };
  }

  const uploadId = createRes.json('data.upload_id');
  thinkTime();

  // ~5% of sessions abort mid-upload (OP-05) instead of completing — operations-catalog
  // §1 "гипотеза: ~2-5% сессий обрываются".
  const abortsMidway = Math.random() < 0.05;
  const chunkCount = Math.ceil(TUS_FILE_SIZE_BYTES / TUS_CHUNK_BYTES);
  // ~8% of sessions resume from a genuine HEAD-read offset (OP-03) — operations-catalog
  // §1 "гипотеза: ~5-10% сессий резюмируются". A real interruption: one chunk is sent
  // HALF-size, then HEAD is consulted for the authoritative offset before continuing —
  // "делаем вид что resume — это просто новый PATCH" is a faked scenario (Implementation
  // Rules), so the next PATCH ALWAYS uses the server-reported offset, never a client guess.
  const resumeAtChunk = Math.random() < 0.08 ? 1 + Math.floor(Math.random() * (chunkCount - 1)) : -1;

  let offset = 0;
  let node = null;
  let complete = false;

  for (let i = 0; i < chunkCount; i++) {
    if (abortsMidway && i === Math.floor(chunkCount / 2)) {
      const abortRes = http.del(`${API}/resumable-uploads/${uploadId}`, null, {
        headers: { ...headers, 'Tus-Resumable': TUS_VERSION },
        tags: { ...TAGS, op: 'OP-05' },
      });

      check(abortRes, { 'tus abort 204': (r) => r.status === 204 });

      return { uploadId, complete: false, node: null };
    }

    const remaining = TUS_FILE_SIZE_BYTES - offset;
    const fullChunkSize = Math.min(TUS_CHUNK_BYTES, remaining);
    const base = i === 0 ? saltedFirstChunk(tusChunk(), uploadId) : tusChunk();

    if (i === resumeAtChunk) {
      // Interrupt mid-chunk: send only half of it, then rely on HEAD, not our own guess.
      const halfSize = Math.max(1, Math.floor(fullChunkSize / 2));
      const partial = sliceOf(base, halfSize);

      const partialStart = Date.now();
      const partialRes = http.patch(`${API}/resumable-uploads/${uploadId}`, partial, {
        headers: {
          ...headers,
          'Content-Type': 'application/offset+octet-stream',
          'Tus-Resumable': TUS_VERSION,
          'Upload-Offset': String(offset),
        },
        tags: { ...TAGS, op: 'OP-02' },
      });

      recordAcceptedStream(partialRes, partial.byteLength, (Date.now() - partialStart) / 1000, { ...TAGS, op: 'OP-02' }, [204, 201], 'sent');
      trackProtocolStatus(partialRes, TAGS);

      if (partialRes.status !== 204) {
        break;
      }

      const headRes = http.head(`${API}/resumable-uploads/${uploadId}`, {
        headers: { ...headers, 'Tus-Resumable': TUS_VERSION },
        tags: { ...TAGS, op: 'OP-03' },
      });

      check(headRes, { 'tus HEAD 204': (r) => r.status === 204 });
      offset = parseInt(header(headRes, 'Upload-Offset') || String(offset), 10);

      const rest = sliceOf(base, fullChunkSize).slice(halfSize);
      const restStart = Date.now();
      const restRes = http.patch(`${API}/resumable-uploads/${uploadId}`, rest, {
        headers: {
          ...headers,
          'Content-Type': 'application/offset+octet-stream',
          'Tus-Resumable': TUS_VERSION,
          'Upload-Offset': String(offset),
        },
        tags: { ...TAGS, op: 'OP-02' },
      });

      recordAcceptedStream(restRes, rest.byteLength, (Date.now() - restStart) / 1000, { ...TAGS, op: 'OP-02' }, [204, 201], 'sent');
      trackProtocolStatus(restRes, TAGS);

      if (restRes.status === 201) {
        node = restRes.json('data');
        complete = true;
        break;
      }

      if (restRes.status !== 204) {
        break;
      }

      offset = parseInt(header(restRes, 'Upload-Offset') || String(offset + rest.byteLength), 10);

      continue;
    }

    const chunk = sliceOf(base, fullChunkSize);
    const start = Date.now();

    const patchRes = http.patch(`${API}/resumable-uploads/${uploadId}`, chunk, {
      headers: {
        ...headers,
        'Content-Type': 'application/offset+octet-stream',
        'Tus-Resumable': TUS_VERSION,
        'Upload-Offset': String(offset),
      },
      tags: { ...TAGS, op: 'OP-02' },
    });

    const elapsedS = (Date.now() - start) / 1000;

    if (i === 0) {
      ttfbMs.add(patchRes.timings.waiting, TAGS);
    }

    trackProtocolStatus(patchRes, TAGS);

    recordAcceptedStream(patchRes, chunk.byteLength, elapsedS, { ...TAGS, op: 'OP-02' }, [204, 201], 'sent');

    if (patchRes.status === 201) {
      node = patchRes.json('data');
      complete = true;
      break;
    }

    if (patchRes.status !== 204) {
      break;
    }

    offset = parseInt(header(patchRes, 'Upload-Offset') || String(offset + fullChunkSize), 10);
  }

  check({ complete }, { 'tus session completed': (r) => r.complete === true });

  return { uploadId, complete, node };
}

export default function () {
  run();
  thinkTime();
}
