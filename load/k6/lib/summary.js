// VLT-LOAD P2.5 — handleSummary(data) wrapper: writes the k6 summary object enriched
// with the `meta` header the baseline format requires (slo-gates.md §4.1: commit,
// profile, tier, env_fingerprint, dataset_id, runs). `handleSummary` is used INSTEAD OF
// `--summary-export` on purpose (RAG-8, findings/P1.4-rag-k6-threshold-syntax.md) — it is
// the only mechanism that lets custom fields ride along in the same JSON perf-assert.py
// later reads.
import { TIER } from './config.js';
import { DATASET_ID } from './dataset.js';

// `env_fingerprint` is NOT recomputed here: hashing cpuset/mem_limit/image versions
// requires Node-side tooling (crypto, docker inspect) that does not exist inside the k6
// sandbox. `load/scripts/run.sh` computes it on the host and passes it in as
// `ENV_FINGERPRINT` — same reasoning as staging `dataset.json`/`thresholds.js`: whatever
// k6 cannot compute itself, the host prepares before the container starts.
function meta(profile, runs) {
  return {
    commit: __ENV.GIT_COMMIT_SHA || __ENV.GITHUB_SHA || 'unknown',
    profile: profile || __ENV.LOAD_PROFILE || 'smoke-perf',
    tier: TIER,
    env_fingerprint: __ENV.ENV_FINGERPRINT || 'unset',
    dataset_id: DATASET_ID,
    runs: runs || 1,
  };
}

/**
 * @param {object} data — the object k6 passes into `handleSummary`.
 * @param {{profile?: string, runs?: number}} [opts]
 * @return {object} the same shape k6's default `handleSummary` returns (a map of output
 *   path -> string), so a scenario can spread this straight into its own `handleSummary`.
 */
export function summaryOutputs(data, opts = {}) {
  const enriched = { ...data, meta: meta(opts.profile, opts.runs) };

  return {
    // /results is a dedicated writable mount (docker-compose.load.yml) — `/scripts`
    // (where this file itself lives) is deliberately `:ro`.
    '/results/summary.json': JSON.stringify(enriched, null, 2),
    stdout: JSON.stringify(enriched.meta, null, 2) + '\n',
  };
}
