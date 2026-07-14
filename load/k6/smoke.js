// VLT-LOAD P2.4 — contract smoke: every scenario runs ONCE against the live stand
// (--vus 1 --iterations 1, see load/scripts/run.sh) before any real load is generated.
// Code Guidance's "Анти-самообман №1": a green run against a broken harness is the
// worst outcome, so this does not just assert HTTP status — it returns the created ids
// so `run.sh smoke` can chain the DB/storage post-condition checks the item's
// Validation demands (sha256 match, moved parent_id, etc.) from OUTSIDE k6.
import { check } from 'k6';

import { summaryOutputs } from './lib/summary.js';
import { setup as tusUploadSetup, run as tusUploadRun } from './scenarios/tus-upload.js';
import { setup as s3MultipartSetup, run as s3MultipartRun } from './scenarios/s3-multipart.js';
import { setup as singleShotSetup, run as singleShotRun } from './scenarios/single-shot.js';
import { setup as treeSetup, run as treeRun } from './scenarios/tree.js';
import { setup as docsCollabSetup, run as docsCollabRun } from './scenarios/docs-collab.js';
import { setup as downloadSetup, run as downloadRun } from './scenarios/download.js';
import { interactive as mixedInteractive, upload as mixedUpload, download as mixedDownload, setup as mixedSetup } from './scenarios/mixed.js';

// VLT-LOAD P2.5 — `handleSummary` attaches `meta` (baseline format, slo-gates.md §4.1)
// so `summary.json` from a smoke run is already perf-assert.py-shaped. Deliberately NOT
// wired to `load/gates/thresholds.js`: those are class-A gates calibrated for a
// sustained `constant-vus` run (harness.md §4) — a single shared-iteration has no
// meaningful `med`/`min` distribution, and applying them here made the FUNCTIONAL
// contract smoke fail on real, healthy runs (found live while validating this item —
// see Known Deviations). The `smoke-perf` profile that DOES gate on these thresholds is
// P2.6's job, against a `constant-vus` run, not this 1-iteration contract smoke.
const LOAD_PROFILE = __ENV.LOAD_PROFILE || 'smoke-perf';

export const options = {
  scenarios: {
    smoke: { executor: 'shared-iterations', vus: 1, iterations: 1, exec: 'run' },
  },
};

export function handleSummary(data) {
  return summaryOutputs(data, { profile: LOAD_PROFILE });
}

export function setup() {
  return {
    download: downloadSetup(),
    docsCollab: docsCollabSetup(),
    mixed: mixedSetup(),
  };
}

export function run(data) {
  tusUploadSetup();
  const tus = tusUploadRun();
  check(tus, { 'smoke: tus-upload completed': (r) => r.complete === true });

  s3MultipartSetup();
  const s3 = s3MultipartRun();
  check(s3, {
    // The complete()-in-RAM finding (L-01) means a real red here is an accepted,
    // documented outcome (Code Guidance) — the smoke gate is "the scenario ran and
    // told the truth", not "complete() always succeeds".
    'smoke: s3-multipart ran': (r) => r.uploadId !== null,
  });

  const single = singleShotRun();
  check(single, { 'smoke: single-shot completed': (r) => r.complete === true });

  treeSetup();
  treeRun();

  const docsCollab = docsCollabRun(data.docsCollab);

  const download = downloadRun(data.download);

  mixedInteractive(data.mixed);
  mixedUpload(data.mixed);
  mixedDownload(data.mixed);

  return { tus, s3, single, docsCollab, download };
}
