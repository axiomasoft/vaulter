// VLT-LOAD P2.5 — options.thresholds builder, transcribed from slo-gates.md §2
// (sequential) and §3.2 (streaming class A). Runs INSIDE the k6 sandbox — `run.sh`
// stages this file into `load/k6/lib/thresholds.js` before invoking k6, because the k6
// service only mounts `load/k6/` (docker-compose.load.yml `k6.volumes`), same reason
// `run.sh` already stages the seed manifest (P2.4 Known Deviations). Source of truth
// stays here, not the staged copy.
//
// What is deliberately NOT in this file (Implementation Rules — no invented numbers):
//  - G-4/G-5 (upload_conflict_total/throttled_total budgeted at 5%/1% of ANOTHER
//    counter's count): a k6 threshold expression compares one metric against a fixed
//    number, not two metrics against each other (RAG-8/RAG-8b — no cross-metric ratio
//    primitive). slo-gates.md §2 leaves the actual number as "..." because it is only
//    computable from this run's own op count — that arithmetic lives in
//    `load/gates/perf-assert.py`, which reads the same `summary.json` and can do
//    arbitrary cross-metric arithmetic. G-6 was perf-assert-only in the source table
//    already (`vaulter_failed_jobs_total`, a server metric).
//  - RSS ceilings (the "A" row's memory half for SLO-40…54): k6 cannot see server memory
//    at all (RAG-8b) — perf-assert.py only (Prometheus once P8 lands, `container-peak.json`
//    as a coarse stand-in today).
//  - Class B remains advisory outside `lab` (D20). In `lab`, the OP tag selects an
//    individual request inside a mixed scenario; the file-level scenario tag alone
//    combines unrelated operations (F-P2.8-3).
//  - §3.3 (background, SLO-55…81): no k6 metric at all (no clientside input) — perf-
//    assert.py only.
//
// Tag-vocabulary note: slo-gates.md names streaming operations by a conceptual slug
// (`tus_patch`, `s3_putpart`, `s3_complete`, `single_shot`, `download`) that is NOT a
// literal k6 tag value anywhere in `load/k6/scenarios/*.js` (P2.4) — those tag requests
// with `scenario` = the FILE-level scenario name (`tus-upload`, `s3-multipart`,
// `single-shot`, `download`) and `op` = the OP-nn from operations-catalog.md. Plan
// disagrees with code exactly the way P2.4's own Known Deviations already handled once
// ("сценарий подстраивается под КОД, расхождение фиксируется") — SCENARIO_TAG_MAP below
// is that same adaptation, not a new decision.
const SCENARIO_TAG_MAP = {
  tus_patch: { scenario: 'tus-upload', op: 'OP-02' },
  s3_putpart: { scenario: 's3-multipart', op: 'OP-06' },
  s3_complete: { scenario: 's3-multipart', op: 'OP-07' },
  single_shot: { scenario: 'single-shot', op: 'OP-17' },
  download: { scenario: 'download', op: 'OP-08' },
};

// §2 G-1..G-3 — the only sequential gates expressible as a single-metric k6 threshold.
// `class` here IS the literal tag scenarios already set (tree.js/docs-collab.js =
// interactive; tus-upload/s3-multipart/single-shot/download = streaming; mixed.js sets it
// per-request) — no translation needed, unlike SCENARIO_TAG_MAP above.
const SEQUENTIAL = {
  'http_req_failed{class:interactive}': ['rate<0.005'],
  'http_req_failed{class:streaming}': ['rate<0.01'],
  checks: ['rate>0.99'],
};

// §3.2 — one entry per SLO-40..54 "A" row, tier-keyed. `S0` has no dedicated row in
// slo-gates.md (it is a CI profile, not a deployment tier) — it reuses tier S's numbers:
// per-stream throughput is a single-connection-speed expectation, not something that
// scales down with the ~1/50 object-count cut S0 makes (`reference-env.md` §4.1). Same
// derivation rule P2.2 used for S0's PG params (phases/P2.md P2.2 Known Deviations) —
// documented here, not invented silently.
const STREAM_A = {
  tus_patch: {
    S0: { med: 25, min: 15, dataSentRate: 52428800 },
    S: { med: 25, min: 15, dataSentRate: 52428800 },
    M: { med: 30, min: 18, dataSentRate: 209715200 },
    L: { med: 35, min: 21, dataSentRate: 524288000 },
  },
  s3_putpart: {
    S0: { med: 25, min: 15 },
    S: { med: 25, min: 15 },
    M: { med: 30, min: 18 },
    L: { med: 35, min: 21 },
  },
  // SLO-46..48 (L-01, known-red — slo-gates.md §6.4): the throughput floor below stays
  // part of the spec (Implementation Rules — not weakened because RSS is red today). The
  // RSS half of this row is perf-assert-only and is where L-01's `known-red` verdict is
  // actually surfaced (see perf-assert.py KNOWN_RED).
  s3_complete: {
    S0: { med: 100 },
    S: { med: 100 },
    M: { med: 200 },
    L: { med: 300 },
  },
  single_shot: {
    S0: { med: 25, min: 15 },
    S: { med: 25, min: 15 },
    M: { med: 30, min: 18 },
    L: { med: 35, min: 21 },
  },
  download: {
    S0: { med: 30, min: 18, dataReceivedRate: 62914560 },
    S: { med: 30, min: 18, dataReceivedRate: 62914560 },
    M: { med: 40, min: 24, dataReceivedRate: 262144000 },
    L: { med: 50, min: 30, dataReceivedRate: 629145600 },
  },
};

// Tier L rows are "блокирующий (лаб.)" in slo-gates.md §3.2 — every other tier is
// unconditionally blocking wherever it is even run (S0/S/M never carry the "(лаб.)"
// qualifier).
function streamingBlocking(tier, profile) {
  return tier === 'L' ? profile === 'lab' : true;
}

function streamingThresholds(tier) {
  const out = {};

  for (const [slug, byTier] of Object.entries(STREAM_A)) {
    const row = byTier[tier];

    if (!row) {
      continue;
    }

    const tags = SCENARIO_TAG_MAP[slug];
    const trendExpr = [];

    if (row.med !== undefined) {
      trendExpr.push(`med>${row.med}`);
    }

    if (row.min !== undefined) {
      trendExpr.push(`min>${row.min}`);
    }

    // Every custom sample carries the accepted operation, including OP-07.
    // Downloads share a per-stream floor across OP-08/09/10; their byte-rate
    // contract remains specifically OP-08.
    const selector = slug === 'download'
      ? `scenario:${tags.scenario},tier:${tier}`
      : `scenario:${tags.scenario},op:${tags.op},tier:${tier}`;
    out[`stream_throughput_mibs{${selector}}`] = trendExpr;

    const ops = slug === 'download' ? ['OP-08', 'OP-09', 'OP-10'] : [tags.op];
    for (const op of ops) {
      const opTags = `scenario:${tags.scenario},op:${op},tier:${tier}`;
      out[`stream_accepted_total{${opTags}}`] = ['count>=0'];
      out[`stream_rejected_total{${opTags}}`] = ['count>=0'];
      out[`accepted_stream_duration_seconds{${opTags}}`] = ['count>=0'];
      const byteMetric = slug === 'download' ? 'accepted_received_bytes'
        : slug === 's3_complete' ? 'finalized_bytes' : 'accepted_sent_bytes';
      out[`${byteMetric}{${opTags}}`] = ['count>=0'];
      if (slug === 'download') out[`http_reqs{op:${op},tier:${tier}}`] = ['count>0'];
    }

    if (row.dataSentRate !== undefined) {
      out[`accepted_sent_bytes{scenario:${tags.scenario},op:${tags.op},tier:${tier}}`] = [
        `rate>${row.dataSentRate}`,
      ];
    }

    if (row.dataReceivedRate !== undefined) {
      out[`accepted_received_bytes{scenario:${tags.scenario},op:${tags.op},tier:${tier}}`] = [
        `rate>${row.dataReceivedRate}`,
      ];
    }
  }

  return out;
}

// Tier-S B rows exercised by the mixed workload. The OP tag is set on each HTTP
// request, so these submetrics cannot accidentally aggregate tree reads and writes.
// Sparse export/broadcast/TTFB rows require a separate sample-count verdict.
const LAB_B_S = {
  'OP-18': 120, 'OP-12': 300, 'OP-14': 600, 'OP-16': 180,
  'OP-20': 900, 'OP-25': 225, 'OP-28': 225, 'OP-29': 300,
  'OP-34': 150, 'OP-31': 375, 'OP-38': 225, 'OP-01': 180,
  'OP-02': 600, 'OP-06': 4500, 'OP-17': 1800,
};

function labBThresholds(tier) {
  if (tier !== 'S') {
    return {};
  }

  const thresholds = {};
  for (const [op, p95] of Object.entries(LAB_B_S)) {
    thresholds[`http_req_duration{op:${op},tier:S}`] = [`p(95)<${p95}`];
  }
  return thresholds;
}

/**
 * k6 `options.thresholds` for one (profile, tier) pair.
 *
 * Class B (latency) only enters `options.thresholds` in the `lab` profile (D20) — this
 * function intentionally returns latency thresholds ONLY there; every other profile gets
 * class A alone, and class B stays advisory (reported by perf-assert.py from the same
 * summary.json, not gated by k6 itself).
 *
 * @param {'smoke-perf'|'regression'|'lab'} profile
 * @param {'S0'|'S'|'M'|'L'} tier
 * @return {object}
 */
export function thresholdsFor(profile, tier) {
  const thresholds = { ...SEQUENTIAL };

  if (streamingBlocking(tier, profile)) {
    Object.assign(thresholds, streamingThresholds(tier));
  }

  if (profile === 'lab') {
    Object.assign(thresholds, labBThresholds(tier));
  }

  return thresholds;
}

export const KNOWN_TAG_MAP = SCENARIO_TAG_MAP;
