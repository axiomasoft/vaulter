// Reference of AI Core ProposalDigest for Node (stdlib only, Node >= 20 for isWellFormed).
// RFC 8785 (JCS): members ordered by UTF-16 code units (Array.prototype.sort default),
// strings and numbers serialized as ECMAScript JSON.stringify does, lone surrogates rejected.
// The Office v1 digest profile additionally admits only safe integers (by value).
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

export class CanonicalizationError extends Error {}

/** @param {unknown} value @returns {string} canonical JSON text */
export function canonicalize(value) {
  if (value === null || typeof value === 'boolean') return JSON.stringify(value);
  if (typeof value === 'number') {
    if (!Number.isFinite(value)) throw new CanonicalizationError('NaN/Infinity');
    return JSON.stringify(value); // ECMAScript Number::toString; -0 → "0"
  }
  if (typeof value === 'string') {
    if (!value.isWellFormed()) throw new CanonicalizationError('lone surrogate');
    return JSON.stringify(value); // escapes only " \ and U+0000..U+001F (short forms, lowercase hex)
  }
  if (Array.isArray(value)) return '[' + value.map(canonicalize).join(',') + ']';
  if (typeof value === 'object') {
    const keys = Object.keys(value);
    for (const key of keys) if (!key.isWellFormed()) throw new CanonicalizationError('lone surrogate in key');
    keys.sort(); // UTF-16 code unit order, exactly what RFC 8785 §3.2.3 requires
    return '{' + keys.map((k) => JSON.stringify(k) + ':' + canonicalize(value[k])).join(',') + '}';
  }
  throw new CanonicalizationError(`unsupported ${typeof value}`);
}

/** Office v1 digest records carry only safe integers; JSON.parse already mapped 27.0 to 27. */
export function assertDigestProfile(value) {
  if (typeof value === 'number') {
    if (!Number.isSafeInteger(value)) throw new CanonicalizationError(`number outside safe integer profile: ${value}`);
  } else if (value && typeof value === 'object') {
    for (const item of Object.values(value)) assertDigestProfile(item);
  }
}

/** {version:1, prepare_input, plan: result without digest} */
export function proposalRecord(prepareInput, prepareResult) {
  const { digest: _ignored, ...plan } = prepareResult;
  return { version: 1, prepare_input: prepareInput, plan };
}

export function proposalDigest(prepareInput, prepareResult) {
  const record = proposalRecord(prepareInput, prepareResult);
  assertDigestProfile(record);
  return createHash('sha256').update(Buffer.from(canonicalize(record), 'utf8')).digest('hex');
}

function runVectors(path) {
  const vectors = JSON.parse(readFileSync(path, 'utf8'));
  let failures = 0;
  const report = (ok, id, detail = '') => {
    failures += ok ? 0 : 1;
    console.error(`${ok ? 'ok  ' : 'FAIL'} node ${id}${detail}`);
  };
  for (const v of vectors.jcs) {
    let text = null;
    let profile = 'accept';
    try {
      const parsed = JSON.parse(v.input_json);
      text = canonicalize(parsed);
      try { assertDigestProfile(parsed); } catch { profile = 'reject'; }
    } catch (e) {
      if (!(e instanceof CanonicalizationError || e instanceof SyntaxError)) throw e;
      profile = 'reject';
    }
    const bytes = text === null ? null : Buffer.from(text, 'utf8');
    report(text === v.jcs && profile === v.profile
      && (bytes === null || (bytes.toString('hex') === v.utf8_hex && createHash('sha256').update(bytes).digest('hex') === v.sha256)), v.id);
  }
  for (const p of vectors.proposals) {
    const input = p.prepare_request.input;
    const result = p.prepare_response.result;
    const text = canonicalize(proposalRecord(input, result));
    const permuted = canonicalize(JSON.parse(p.permuted_record_json));
    report(text === p.canonical_record && permuted === text && proposalDigest(input, result) === p.digest
      && result.digest === p.digest, p.id);
  }
  return failures;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  process.exit(runVectors(process.argv[2] ?? fileURLToPath(new URL('../vectors.json', import.meta.url))) === 0 ? 0 : 1);
}
