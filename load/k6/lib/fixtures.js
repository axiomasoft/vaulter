// VLT-LOAD P2.4 — D23 fixtures: no disk file, no k6/experimental/fs (that would still
// mean a multi-GiB file's RSS in the generator, harness.md §3). A single ArrayBuffer per
// chunk size is generated ONCE in the init context and reused, byte-for-byte, by every
// VU and every iteration — never regenerated per request.
import { TUS_CHUNK_BYTES, S3_PART_BYTES } from './config.js';

const TUS_CHUNK = new ArrayBuffer(TUS_CHUNK_BYTES);
const S3_PART = new ArrayBuffer(S3_PART_BYTES);

export function tusChunk() {
  return TUS_CHUNK;
}

export function s3Part() {
  return S3_PART;
}

/**
 * A 16-byte per-session salt written into a COPY of the first chunk/part of a session
 * — its sha256 must be unique or CAS dedup (BlobStore::commit) finds the existing blob
 * and the scenario measures dedup, not a write (harness.md §3). Only the FIRST
 * chunk/part of a session pays this copy; every later one reuses the shared buffer
 * directly.
 */
export function saltedFirstChunk(base, sessionId) {
  const view = new Uint8Array(base.slice(0));
  const salt = String(sessionId).padEnd(16, '0').slice(0, 16);

  for (let i = 0; i < 16; i++) {
    view[i] = salt.charCodeAt(i);
  }

  return view.buffer;
}

/**
 * A right-sized slice of a shared chunk buffer for the LAST chunk/part of a session
 * (declared size is rarely an exact multiple of the chunk size).
 */
export function sliceOf(base, length) {
  return base.slice(0, length);
}
