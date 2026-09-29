import { options as baseOptions, setup, interactive, upload, download } from './scenarios/mixed.js';
import { TIER } from './lib/config.js';
import { thresholdsFor } from './lib/thresholds.js';
import { summaryOutputs } from './lib/summary.js';

// P2.6 — the wrapper `mixed.js` itself does not carry (Scope Excluded from P2.4/P2.5):
// class-A thresholds (harness.md §4 sustained-run requirement) and the `meta`-enriched
// `handleSummary` (slo-gates.md §4.1 baseline shape) both come from the profile, not
// from the scenario file.
export const options = {
    ...baseOptions,
    thresholds: thresholdsFor(__ENV.LOAD_PROFILE, TIER),
    summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'count'],
};

export function handleSummary(data) {
    const expectedClassBKeys = __ENV.LOAD_PROFILE === 'lab'
        ? Object.keys(thresholdsFor(__ENV.LOAD_PROFILE, TIER)).filter((key) => key.startsWith('http_req_duration{op:'))
        : [];
    return summaryOutputs(data, { profile: __ENV.LOAD_PROFILE, expectedClassBKeys });
}

export { setup, interactive, upload, download };
