// P5.3 live product cell. One VU per actor, one iteration, no sleep barrier.
// Status counters are the NOWAIT/conflict evidence. 5xx is never a pass.
import http from 'k6/http';
import exec from 'k6/execution';
import encoding from 'k6/encoding';
import { Counter } from 'k6/metrics';

const API = `${(__ENV.BASE_URL || 'http://app:8080').replace(/\/+$/, '')}/api/vaulter`;
const SURFACE = __ENV.P5_SURFACE || '';
const SHAPE = __ENV.P5_SHAPE || '';
const N = Number(__ENV.P5_N || '1');
const ACTOR_BASE = Number(__ENV.P5_ACTOR_BASE || '1');
const STORAGE = __ENV.P5_STORAGE_ID || '1';
const SHARED_UPLOAD = __ENV.P5_UPLOAD_ID || '';
const DOCUMENT = __ENV.P5_DOCUMENT_ID || '';
const MOVE_TARGET = __ENV.P5_MOVE_TARGET || '';
const NODE_LIST = (__ENV.P5_NODE_IDS || '').split(',').filter(Boolean);
const BODY = __ENV.P5_BODY_B64 || '';

const c2xx = new Counter('p5_status_2xx');
const c409 = new Counter('p5_status_409');
const c429 = new Counter('p5_status_429');
const c5xx = new Counter('p5_status_5xx');
const cOther = new Counter('p5_status_other');

export const options = {
  scenarios: {
    cell: {
      executor: 'per-vu-iterations',
      vus: N,
      iterations: 1,
      maxDuration: __ENV.P5_MAX_DURATION || '90s',
      exec: 'run',
    },
  },
  discardResponseBodies: false,
};

function headers(extra) {
  return Object.assign({
    'X-Load-Actor': String(ACTOR_BASE + exec.vu.idInTest - 1),
    'Content-Type': 'application/json',
  }, extra || {});
}

function tally(response, phase) {
  const status = response.status;
  const actor = ACTOR_BASE + exec.vu.idInTest - 1;
  const retryAfter = response.headers['Retry-After'] || '';
  console.log(`P5_HTTP|${actor}|${phase}|${status}|${retryAfter}|END`);
  if (status >= 200 && status < 300) {
    c2xx.add(1);
  } else if (status === 409) {
    c409.add(1);
  } else if (status === 429) {
    c429.add(1);
  } else if (status >= 500) {
    c5xx.add(1);
  } else {
    cOther.add(1);
  }
}

function createUpload(name, bytes) {
  const created = http.post(
    `${API}/storages/${STORAGE}/resumable-uploads`,
    JSON.stringify({ name, total_size_bytes: bytes }),
    { headers: headers({ 'Tus-Resumable': '1.0.0' }), timeout: '30s' },
  );
  tally(created, 'create');
  if (created.status !== 201) {
    return null;
  }
  return created.json('data.upload_id');
}

function patchTus(uploadId, payload) {
  const response = http.patch(`${API}/resumable-uploads/${uploadId}`, payload, {
    headers: headers({
      'Content-Type': 'application/offset+octet-stream',
      'Tus-Resumable': '1.0.0',
      'Upload-Offset': '0',
      'X-P5-Overlap': SHAPE === 'live-patch-overlap' ? SHARED_UPLOAD : '',
    }),
    timeout: '30s',
  });
  tally(response, 'operation');
  return response;
}

function putPart(uploadId, payload) {
  const response = http.post(
    `${API}/resumable-uploads/${uploadId}/parts?part_no=1&byte_offset=0`,
    payload,
    { headers: headers({ 'Content-Type': 'application/octet-stream' }), timeout: '30s' },
  );
  tally(response, 'operation');
  return response;
}

function completeUpload(uploadId) {
  const response = http.post(`${API}/resumable-uploads/${uploadId}/complete`, null, {
    headers: headers(),
    timeout: '30s',
  });
  tally(response, 'operation');
  return response;
}

function payload() {
  if (!BODY) {
    return new Uint8Array([0x70, 0x35, exec.vu.idInTest & 0xff, 0x0a]);
  }
  return new Uint8Array(encoding.b64decode(BODY));
}

function operation() {
  const vu = exec.vu.idInTest;
  const bytes = payload();

  if (SURFACE === 'upload-tus' || SURFACE === 'upload-s3' || SURFACE === 'cas-lock' || SURFACE === 'sweeper-overlap') {
    const shared = SHAPE === 'same-resource' || SHAPE === 'live-patch-overlap';
    const uploadId = shared ? SHARED_UPLOAD : createUpload(`p5-${SURFACE}-${vu}.bin`, bytes.byteLength);
    if (!uploadId) {
      return;
    }
    if (SURFACE === 'upload-s3' || SURFACE === 'cas-lock') {
      const part = putPart(uploadId, bytes);
      if (SURFACE === 'cas-lock' && part.status >= 200 && part.status < 300) {
        completeUpload(uploadId);
      }
      return;
    }
    patchTus(uploadId, bytes);
    return;
  }

  if (SURFACE === 'subtree-move') {
    const nodeId = SHAPE === 'overlapping-tree'
      ? (NODE_LIST[0] || '')
      : (NODE_LIST[(vu - 1) % Math.max(NODE_LIST.length, 1)] || '');
    if (!nodeId || !MOVE_TARGET) {
      throw new Error('P5 subtree fixture is missing');
    }
    const response = http.post(
      `${API}/nodes/${nodeId}/move`,
      JSON.stringify({ parent_id: Number(MOVE_TARGET) }),
      { headers: headers(), timeout: '30s' },
    );
    tally(response, 'operation');
    return;
  }

  if (SURFACE === 'collab-issuance') {
    const response = http.post(`${API}/nodes/${DOCUMENT}/collab/token`, null, {
      headers: headers(),
      timeout: '30s',
    });
    tally(response, 'operation');
  }
}

export function run() {
  const actor = ACTOR_BASE + exec.vu.idInTest - 1;
  console.log(`P5_ITER|${actor}|started|END`);
  try {
    operation();
    console.log(`P5_ITER|${actor}|completed|END`);
  } catch (error) {
    console.log(`P5_ITER|${actor}|aborted|END`);
    throw error;
  }
}

export function handleSummary(data) {
  const target = __ENV.P5_SUMMARY || '/results/p5-cell-summary.json';
  return { [target]: JSON.stringify(data) };
}
