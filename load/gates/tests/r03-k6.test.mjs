import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve('load');
async function loadModule(file, stubs = {}, globals = {}) {
  const context = vm.createContext({ __ENV: {}, ...globals });
  const cache = new Map();
  async function get(id) {
    if (cache.has(id)) return cache.get(id);
    let mod;
    if (id in stubs) {
      const exports = stubs[id];
      mod = new vm.SyntheticModule(Object.keys(exports), function () {
        for (const [name, value] of Object.entries(exports)) this.setExport(name, value);
      }, { context, identifier: id });
    } else {
      mod = new vm.SourceTextModule(fs.readFileSync(id, 'utf8'), { context, identifier: id });
    }
    cache.set(id, mod);
    await mod.link((specifier, parent) => get(specifier.startsWith('.')
      ? path.resolve(path.dirname(parent.identifier), specifier) : specifier));
    return mod;
  }
  const mod = await get(path.resolve(root, file));
  await mod.evaluate();
  return mod.namespace;
}

const samples = {};
class Metric {
  constructor(name) { this.name = name; samples[name] = []; }
  add(value, tags) { samples[this.name].push({ value, tags }); }
}
const metricStubs = { 'k6/metrics': { Trend: Metric, Counter: Metric } };

test('S3 part/complete selectors keep distinct floors and accepted byte rates', async () => {
  const gates = await loadModule('gates/thresholds.js');
  const thresholds = gates.thresholdsFor('lab', 'S');
  assert.deepEqual(Array.from(thresholds['stream_throughput_mibs{scenario:s3-multipart,op:OP-06,tier:S}']), ['med>25', 'min>15']);
  assert.deepEqual(Array.from(thresholds['stream_throughput_mibs{scenario:s3-multipart,op:OP-07,tier:S}']), ['med>100']);
  assert.deepEqual(Array.from(thresholds['accepted_sent_bytes{scenario:tus-upload,op:OP-02,tier:S}']), ['rate>52428800']);
});

test('rejected streams cannot contribute bytes or speed; finalization is not sent bytes', async () => {
  const metrics = await loadModule('k6/lib/metrics.js', metricStubs);
  const tags = { scenario: 's3-multipart', op: 'OP-06', tier: 'S' };
  for (const status of [0, 409, 422, 429, 500]) {
    metrics.recordAcceptedStream({ status }, 1048576, 1, tags, [201], 'sent');
  }
  assert.equal(samples.stream_throughput_mibs.length, 0);
  assert.equal(samples.accepted_sent_bytes.length, 0);
  metrics.recordAcceptedStream({ status: 201 }, 1048576, 1, tags, [201], 'sent');
  metrics.recordAcceptedStream({ status: 201 }, 2097152, 1, { ...tags, op: 'OP-07' }, [201], 'finalize');
  assert.equal(samples.accepted_sent_bytes.length, 1);
  assert.equal(samples.stream_throughput_mibs.length, 2);
  assert.equal(samples.stream_rejected_total.length, 5);
  metrics.recordAcceptedStream({ status: 200, error_code: 1200 }, 1048576, 1, { ...tags, op: 'OP-08' }, [200], 'received');
  assert.equal(samples.accepted_received_bytes.length, 0);
  assert.equal(samples.stream_throughput_mibs.length, 2);
  assert.equal(samples.stream_throughput_mibs[1].tags.op, 'OP-07');
});

function discoveryStubs(listings, probeStatus = 200) {
  return {
    'k6/http': { default: { get: () => ({ status: probeStatus }), expectedStatuses: () => {} } },
    [path.join(root, 'k6/lib/config.js')]: { API: '/api' },
    [path.join(root, 'k6/lib/dataset.js')]: { STORAGE_ID: 1, ROOT_NODE_ID: 2, ACTOR_OWNER: 1, WIDE_NODE_ID: 3 },
    [path.join(root, 'k6/lib/auth.js')]: { actorHeaders: () => ({}) },
    [path.join(root, 'k6/lib/nodes.js')]: { children: () => listings.shift() },
  };
}
const listing = (items, status = 200) => ({ status, json: () => items });

test('mandatory discovery refuses HTTP 500, missing docs and unbacked files', async () => {
  const download = await loadModule('k6/lib/discovery.js', discoveryStubs([listing([], 500)]));
  assert.throws(() => download.discoverDownloadableFiles(50), /500/);
  const docs = await loadModule('k6/lib/discovery.js', discoveryStubs([listing([])]));
  assert.throws(() => docs.discoverDocumentNodeIds(20), /documents/);
  const files = await loadModule('k6/lib/discovery.js', discoveryStubs([listing([{ kind: 'file', id: 1 }])], 404));
  assert.throws(() => files.discoverDownloadableFiles(50), /download/);
});

test('discovery admits byte-backed files and a nonempty document pool', async () => {
  const files = await loadModule('k6/lib/discovery.js', discoveryStubs([listing([{ kind: 'file', id: 1 }])]));
  assert.deepEqual(Array.from(files.discoverDownloadableFiles(50)), [1]);
  const docs = await loadModule('k6/lib/discovery.js', discoveryStubs([
    listing([{ kind: 'folder', name: 'documents', id: 2 }]), listing([{ kind: 'document', id: 3 }]),
  ]));
  assert.deepEqual(Array.from(docs.discoverDocumentNodeIds(20)), [3]);
});

async function scenario(name, statuses, random = 0.5, headers = {}) {
  let clock = 0;
  const bytes = new ArrayBuffer(1048576);
  const calls = [];
  let acceptedOffset = 0;
  const response = (method, url, body, opts) => {
    calls.push({ method, url, body, opts });
    const status = statuses.shift();
    if (method === 'patch' && [204, 201].includes(status)) acceptedOffset = Number(opts.headers['Upload-Offset']) + body.byteLength;
    const responseHeaders = name === 'tus-upload' ? { 'Upload-Offset': String(acceptedOffset) } : headers;
    return { status, headers: responseHeaders, timings: { waiting: 10 },
      json: (key) => key === 'data.upload_id' ? 'upload-1' : { id: 1 } };
  };
  const http = {
    post: (url, body, opts) => response('post', url, body, opts),
    patch: (url, body, opts) => response('patch', url, body, opts),
    head: (url, opts) => response('head', url, null, opts),
    get: (url, opts) => response('get', url, null, opts),
    file: (data) => data,
  };
  const stubs = {
    ...metricStubs,
    'k6/http': { default: http },
    'k6': { check: (res, checks) => Object.values(checks).every((fn) => fn(res)) },
    [path.join(root, 'k6/lib/config.js')]: { API: '/api', TIER: 'S', VUS_UPLOAD: 1,
      VUS_DOWNLOAD: 1, DURATION: '180s', TUS_CHUNK_BYTES: 1048576, TUS_FILE_SIZE_BYTES: 2097152,
      S3_PART_BYTES: 1048576, S3_FILE_SIZE_BYTES: 2097152, SINGLE_SHOT_FILE_SIZE_BYTES: 1048576, thinkTime() {} },
    [path.join(root, 'k6/lib/dataset.js')]: { STORAGE_ID: 1, ACTOR_OWNER: 1, ACTOR_NORMAL: 2 },
    [path.join(root, 'k6/lib/auth.js')]: { actorHeaders: () => ({}) },
    [path.join(root, 'k6/lib/fixtures.js')]: { tusChunk: () => bytes, s3Part: () => bytes,
      saltedFirstChunk: (data) => data, sliceOf: (data, size) => data.slice(0, size) },
    [path.join(root, 'k6/lib/expected.js')]: { trackProtocolStatus() {} },
    [path.join(root, 'k6/lib/discovery.js')]: { discoverDownloadableFiles: () => [1], mintShareToken: () => 'share' },
  };
  const math = Object.create(Math);
  math.random = typeof random === 'function' ? random : () => random;
  const mod = await loadModule(`k6/scenarios/${name}.js`, stubs,
    { __VU: 1, __ITER: 1, Date: { now: () => clock += 1000 }, Math: math });
  return { mod, calls };
}

test('real S3 scenario measures accepted completion without sending its bytes twice', async () => {
  const { mod } = await scenario('s3-multipart', [201, 201, 201, 201]);
  assert.equal(mod.run().complete, true);
  assert.deepEqual(samples.stream_throughput_mibs.map((s) => s.tags.op), ['OP-06', 'OP-06', 'OP-07']);
  assert.equal(samples.accepted_sent_bytes.reduce((sum, s) => sum + s.value, 0), 2097152);
  assert.equal(samples.finalized_bytes[0].value, 2097152);
  const failedComplete = await scenario('s3-multipart', [201, 201, 201, 500]);
  assert.equal(failedComplete.mod.run().complete, false);
  assert.equal(samples.finalized_bytes.length, 0);
  assert.equal(samples.stream_throughput_mibs.length, 2);
  const rejected = await scenario('s3-multipart', [201, 500]);
  assert.equal(rejected.mod.run().complete, false);
  assert.equal(samples.stream_throughput_mibs.length, 0);
});

test('real TUS resume records both accepted half chunks and a rejected PATCH records zero bytes', async () => {
  const rolls = [0.5, 0.01, 0]; // do not abort, resume at chunk 1
  const { mod } = await scenario('tus-upload', [201, 204, 204, 204, 201], () => rolls.shift(), { 'Upload-Offset': '1572864' });
  assert.equal(mod.run().complete, true);
  assert.deepEqual(samples.accepted_sent_bytes.map((s) => s.value), [1048576, 524288, 524288]);
  assert.equal(samples.stream_throughput_mibs.length, 3);
  const rejected = await scenario('tus-upload', [201, 409]);
  assert.equal(rejected.mod.run().complete, false);
  assert.equal(samples.accepted_sent_bytes.length, 0);
});

test('real single-shot and discarded-body download exclude error payloads', async () => {
  const single = await scenario('single-shot', [422]);
  assert.equal(single.mod.run().complete, false);
  assert.equal(samples.accepted_sent_bytes.length, 0);
  for (const [roll, op] of [[0.5, 'OP-08'], [0.15, 'OP-09'], [0.01, 'OP-10']]) {
    const download = await scenario('download', [200], roll, { 'Content-Length': '1048576' });
    download.mod.run({ fileIds: [1], shareToken: 'share' });
    assert.equal(samples.accepted_received_bytes[0].value, 1048576);
    assert.equal(samples.accepted_received_bytes[0].tags.op, op);
    assert.equal(download.calls[0].opts.responseType, 'none');
  }
  const rejected = await scenario('download', [500], 0.5, { 'Content-Length': '1048576' });
  rejected.mod.run({ fileIds: [1] });
  assert.equal(samples.accepted_received_bytes.length, 0);
  assert.equal(samples.stream_throughput_mibs.length, 0);
});
