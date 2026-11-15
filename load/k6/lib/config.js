// Tier/base-url/VU raccounting, straight from harness.md §4 (a literal
// copy of the per-group VU table, which is itself a literal copy of slo.md §0.1 —
// disagreeing here would be a finding, not a fix, see harness.md §4).
import { sleep } from 'k6';

export const TIER = __ENV.TIER || 'S0';
export const BASE_URL = (__ENV.BASE_URL || 'http://app:8080').replace(/\/+$/, '');
export const ROUTE_PREFIX = '/api/vaulter';
export const API = `${BASE_URL}${ROUTE_PREFIX}`;

const VUS_BY_TIER = {
  S0: { interactive: 4, upload: 2, download: 2 },
  S: { interactive: 4, upload: 2, download: 2 },
  M: { interactive: 16, upload: 8, download: 8 },
  L: { interactive: 48, upload: 24, download: 24 },
};

function vusFor(group) {
  const envKey = `VUS_${group.toUpperCase()}`;
  const override = __ENV[envKey];

  if (override) {
    return parseInt(override, 10);
  }

  const row = VUS_BY_TIER[TIER] || VUS_BY_TIER.S0;

  return row[group];
}

export const VUS_INTERACTIVE = vusFor('interactive');
export const VUS_UPLOAD = vusFor('upload');
export const VUS_DOWNLOAD = vusFor('download');

export const DURATION = __ENV.DURATION || '30s';
const THINK_TIME_MS = parseInt(__ENV.THINK_TIME_MS || '200', 10);

// The shared fixture sizes: tus chunk / S3 part. Never a disk file, never
// k6/experimental/fs.
export const TUS_CHUNK_BYTES = 8 * 1024 * 1024;
export const S3_PART_BYTES = 64 * 1024 * 1024;

// Declared upload total per session — env-parametrised (Implementation Rules: no
// zeroed-in concurrency/size numbers). Defaults keep a multi-chunk/multi-part session
// without demanding a large default smoke run.
export const TUS_FILE_SIZE_BYTES = parseInt(__ENV.TUS_FILE_SIZE || String(20 * 1024 * 1024), 10);
export const S3_FILE_SIZE_BYTES = parseInt(__ENV.S3_FILE_SIZE || String(160 * 1024 * 1024), 10);
export const SINGLE_SHOT_FILE_SIZE_BYTES = parseInt(__ENV.SINGLE_SHOT_FILE_SIZE || String(1 * 1024 * 1024), 10);

export function thinkTime() {
  if (THINK_TIME_MS <= 0) {
    return;
  }

  sleep(THINK_TIME_MS / 1000);
}
