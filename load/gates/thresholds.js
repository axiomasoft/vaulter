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
//  - Class B (latency — §3.1 entirely, and the "B" row of every §3.2 SLO): advisory in
//    every profile except `lab` (D20) — never blocking outside `lab`.
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
  'http_req_failed{class:interactive}': [{ threshold: 'rate<0.005', abortOnFail: true, delayAbortEval: '30s' }],
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
    const abort = { abortOnFail: true, delayAbortEval: '30s' };
    const trendExpr = [];

    if (row.med !== undefined) {
      trendExpr.push({ threshold: `med>${row.med}`, ...abort });
    }

    if (row.min !== undefined) {
      trendExpr.push({ threshold: `min>${row.min}`, ...abort });
    }

    // `stream_throughput_mibs` is added with each scenario's base `TAGS` constant only
    // (`scenario`/`tier`/`class` — see e.g. tus-upload.js's `streamThroughputMiBs.add(
    // ..., TAGS)`), never `op`: verified live while validating this item (a threshold
    // key that also filtered on `op` never matched a single sample). `data_sent`/
    // `data_received` ARE per-request built-ins and DO carry `op` (the request's own
    // `tags` param sets it) — kept there.
    out[`stream_throughput_mibs{scenario:${tags.scenario},tier:${tier}}`] = trendExpr;

    if (row.dataSentRate !== undefined) {
      out[`data_sent{scenario:${tags.scenario},op:${tags.op},tier:${tier}}`] = [
        { threshold: `rate>${row.dataSentRate}`, ...abort },
      ];
    }

    if (row.dataReceivedRate !== undefined) {
      out[`data_received{scenario:${tags.scenario},op:${tags.op},tier:${tier}}`] = [
        { threshold: `rate>${row.dataReceivedRate}`, ...abort },
      ];
    }
  }

  return out;
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

  return thresholds;
}

export const KNOWN_TAG_MAP = SCENARIO_TAG_MAP;
