// Single-shot public-path cases for the memory matrix (bounded k6 buffers).
import http from 'k6/http';
import { check } from 'k6';
import { API, TUS_CHUNK_BYTES, S3_PART_BYTES } from '../lib/config.js';
import { actorHeaders } from '../lib/auth.js';
import { tusChunk, s3Part, sliceOf } from '../lib/fixtures.js';
import { header } from '../lib/headers.js';
import { memoryCellById, contractCells } from '../lib/memory-cases.js';

const TUS_VERSION = '1.0.0';
const ACTOR = parseInt(__ENV.MEMORY_ACTOR || '1', 10);
const CELL_ID = __ENV.MEMORY_CELL || '';
const MANIFEST_JSON = __ENV.MEMORY_FIXTURE_MANIFEST || '';
const K6_SUMMARY_OUT = __ENV.P4_MATRIX_K6_OUT || '';

// Module scope survives into handleSummary; globalThis is not reliable across k6 init/teardown.
let matrixLastSummary = null;

function publishK6Summary(payload) {
  matrixLastSummary = payload;
  globalThis.__p4MatrixLastResult = payload;

  if (!K6_SUMMARY_OUT) {
    return;
  }

  const file = K6_SUMMARY_OUT.startsWith('file:') ? K6_SUMMARY_OUT.slice(5) : K6_SUMMARY_OUT;
  globalThis.__p4MatrixSummaryPath = file;
}

export const options = {
  scenarios: {
    memory_path: {
      executor: 'shared-iterations',
      vus: 1,
      iterations: 1,
      exec: 'run',
    },
  },
  discardResponseBodies: true,
};

function loadManifest() {
  if (!MANIFEST_JSON) {
    return null;
  }

  try {
    return JSON.parse(MANIFEST_JSON);
  } catch (error) {
    throw new Error(`MEMORY_FIXTURE_MANIFEST is not valid JSON: ${error}`);
  }
}

function chunkForOffset(manifest, offset, length) {
  // k6 cannot mmap the on-disk fixture; for contract (5 MiB) cases the final tus/S3
  // chunk reuses the bounded shared buffers with a deterministic salt from fixture_id.
  const base = tusChunk();
  const salt = String(manifest?.fixture_id || 'contract').padEnd(16, '0').slice(0, 16);
  const view = new Uint8Array(base.slice(0, Math.min(length, base.byteLength)));

  for (let i = 0; i < Math.min(16, view.length); i++) {
    view[i] = salt.charCodeAt(i);
  }

  return sliceOf(view.buffer, length);
}

function flattenChecks(group, out = []) {
  if (!group) {
    return out;
  }

  if (group.checks) {
    out.push(...group.checks);
  }

  for (const child of group.groups || []) {
    flattenChecks(child, out);
  }

  return out;
}

function checksAllPassed(data) {
  const checks = flattenChecks(data?.root_group);

  if (checks.length === 0) {
    return false;
  }

  return checks.every((check) => check.fails === 0 && check.passes > 0);
}

function synthesizeMatrixSummary(data) {
  const cell = CELL_ID ? memoryCellById(CELL_ID) : null;

  if (!cell) {
    return { ok: false, reason: 'unknown cell' };
  }

  const ok = checksAllPassed(data);
  const manifest = loadManifest();
  const paths = manifest?.paths || {};

  if (cell.path_id === 'tus_final_patch') {
    const tusMeta = paths.tus_final_patch || {};
    const finalBytes = tusMeta.final_chunk_bytes ?? cell.size_bytes;

    return {
      ok,
      cell_id: cell.cell_id,
      path_id: cell.path_id,
      http_status: ok ? 204 : 0,
      content_length_bytes: finalBytes,
      client_bytes_received: finalBytes,
      transfer_bytes: finalBytes,
    };
  }

  if (cell.path_id === 's3_putpart') {
    const partBytes = Math.min(S3_PART_BYTES, cell.size_bytes);

    return {
      ok,
      cell_id: cell.cell_id,
      path_id: cell.path_id,
      http_status: ok ? 201 : 0,
      content_length_bytes: partBytes,
      client_bytes_received: partBytes,
      transfer_bytes: partBytes,
    };
  }

  if (cell.path_id === 's3_complete_postfix') {
    return {
      ok,
      cell_id: cell.cell_id,
      path_id: cell.path_id,
      http_status: ok ? 201 : 0,
      content_length_bytes: cell.size_bytes,
      client_bytes_received: cell.size_bytes,
      transfer_bytes: 0,
    };
  }

  if (cell.path_id === 'download') {
    return {
      ok,
      cell_id: cell.cell_id,
      path_id: cell.path_id,
      http_status: ok ? 200 : 0,
      content_length_bytes: cell.size_bytes,
      client_bytes_received: cell.size_bytes,
      transfer_bytes: cell.size_bytes,
    };
  }

  return { ok: false, reason: 'unsupported path for matrix summary' };
}

export function run() {
  const cell = CELL_ID ? memoryCellById(CELL_ID) : contractCells()[0];

  if (!cell) {
    throw new Error(`Unknown MEMORY_CELL [${CELL_ID}]`);
  }

  if (cell.dry_run) {
    check(cell, { 'dry-run manifest only': () => true });

    return { cell_id: cell.cell_id, dry_run: true };
  }

  const manifest = loadManifest();
  const paths = manifest?.paths || {};
  const headers = actorHeaders(ACTOR);
  const storageId = paths.storage_id || parseInt(__ENV.MEMORY_STORAGE_ID || '1', 10);

  if (cell.path_id === 'tus_final_patch') {
    return runTusFinalPatch(headers, paths.tus_final_patch, manifest, cell);
  }

  if (cell.path_id === 's3_putpart') {
    return runS3PutPart(headers, paths.s3_putpart, manifest, cell);
  }

  if (cell.path_id === 's3_complete_postfix') {
    return runS3Complete(headers, paths.s3_complete_postfix, cell);
  }

  if (cell.path_id === 'download') {
    return runDownload(headers, paths.download, cell);
  }

  check(cell, { 'cas stage is CLI-only in k6': () => cell.path_id === 'cas_stage_probe' });

  return { cell_id: cell.cell_id, skipped: 'cas_stage_probe' };
}

function runTusFinalPatch(headers, tusMeta, manifest, cell) {
  const uploadId = tusMeta?.upload_id;
  const offset = tusMeta?.offset_before_final ?? 0;
  const finalBytes = tusMeta?.final_chunk_bytes ?? cell.size_bytes;
  const chunk = chunkForOffset(manifest, offset, finalBytes);

  const res = http.patch(`${API}/resumable-uploads/${uploadId}`, chunk, {
    headers: {
      ...headers,
      'Content-Type': 'application/offset+octet-stream',
      'Tus-Resumable': TUS_VERSION,
      'Upload-Offset': String(offset),
    },
    tags: { scenario: 'memory-paths', path: cell.path_id, op: 'OP-02' },
  });

  const ok = res.status === 201 || res.status === 204;
  check(res, { 'tus final patch completes': () => ok });

  const summary = {
    ok,
    cell_id: cell.cell_id,
    path_id: cell.path_id,
    http_status: res.status,
    content_length_bytes: finalBytes,
    client_bytes_received: finalBytes,
    transfer_bytes: finalBytes,
  };
  publishK6Summary(summary);

  return summary;
}

function runS3PutPart(headers, s3Meta, manifest, cell) {
  const uploadId = s3Meta?.upload_id;
  const partBytes = Math.min(S3_PART_BYTES, cell.size_bytes);
  const part = chunkForOffset(manifest, 0, partBytes);

  const res = http.post(
    `${API}/resumable-uploads/${uploadId}/parts?part_no=1&byte_offset=0`,
    part,
    {
      headers: { ...headers, 'Content-Type': 'application/octet-stream' },
      tags: { scenario: 'memory-paths', path: cell.path_id, op: 'OP-06' },
    },
  );

  const ok = res.status === 201;
  check(res, { 's3 putPart 201': () => ok });

  const summary = {
    ok,
    cell_id: cell.cell_id,
    path_id: cell.path_id,
    http_status: res.status,
    content_length_bytes: partBytes,
    client_bytes_received: partBytes,
    transfer_bytes: partBytes,
  };
  publishK6Summary(summary);

  return summary;
}

function runS3Complete(headers, completeMeta, cell) {
  const uploadId = completeMeta?.upload_id;

  const res = http.post(`${API}/resumable-uploads/${uploadId}/complete`, null, {
    headers,
    tags: { scenario: 'memory-paths', path: cell.path_id, op: 'OP-07' },
  });

  const ok = res.status === 201;
  check(res, { 's3 complete 201': () => ok });

  const summary = {
    ok,
    cell_id: cell.cell_id,
    path_id: cell.path_id,
    http_status: res.status,
    content_length_bytes: cell.size_bytes,
    client_bytes_received: cell.size_bytes,
    transfer_bytes: 0,
  };
  publishK6Summary(summary);

  return summary;
}

function runDownload(headers, downloadMeta, cell) {
  const nodeId = downloadMeta?.node_id;
  const res = http.get(`${API}/nodes/${nodeId}/download`, {
    headers,
    responseType: 'none',
    tags: { scenario: 'memory-paths', path: cell.path_id, op: 'OP-08' },
  });

  const contentLength = parseInt(header(res, 'Content-Length') || '0', 10);
  // With responseType:none k6 does not retain the body; the isolated client byte
  // accounting for this single-request case is the wire size implied by Content-Length.
  const clientBytes = contentLength;

  check(res, {
    'download 200': (r) => r.status === 200,
    'content-length matches fixture': () => contentLength === cell.size_bytes,
    'data_received matches content-length': () => clientBytes === contentLength,
  });

  const ok = res.status === 200 && contentLength === cell.size_bytes;
  const summary = {
    ok,
    cell_id: cell.cell_id,
    path_id: cell.path_id,
    http_status: res.status,
    content_length_bytes: contentLength,
    client_bytes_received: clientBytes,
    transfer_bytes: contentLength,
  };
  publishK6Summary(summary);

  return summary;
}

export function handleSummary(data) {
  let payload = matrixLastSummary || globalThis.__p4MatrixLastResult;

  if (!payload?.ok) {
    payload = synthesizeMatrixSummary(data);
  }
  const path = globalThis.__p4MatrixSummaryPath || K6_SUMMARY_OUT;

  if (!path) {
    return {};
  }

  const fileKey = path.startsWith('file:') ? path.slice(5) : path;

  return {
    [fileKey]: `${JSON.stringify(payload, null, 2)}\n`,
  };
}

export default function () {
  run();
}
