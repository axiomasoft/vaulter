// The realistic mix: three independent executor
// groups (interactive / upload / download) running CONCURRENTLY, VU counts straight
// from the group split (never a scenario-local guess), each drawing from the
// per-group operation mix ("Доля в миксе"). The activity feed
// (OP-25/26, Scope Excluded from every other scenario) lives here, under the
// background writer (`load:activity-write`) — without it running alongside, the feed
// reads measure a fiction (Scope Included).
import http from 'k6/http';
import { check } from 'k6';
import { API, TIER, VUS_INTERACTIVE, VUS_UPLOAD, VUS_DOWNLOAD, DURATION, thinkTime } from '../lib/config.js';
import { STORAGE_ID, ACTOR_NORMAL } from '../lib/dataset.js';
import { actorHeaders } from '../lib/auth.js';
import { discoverDownloadableFiles, discoverDocumentNodeIds, mintShareToken } from '../lib/discovery.js';
import { trackProtocolStatus } from '../lib/expected.js';

import { run as tusUploadRun } from './tus-upload.js';
import { run as s3MultipartRun } from './s3-multipart.js';
import { run as singleShotRun } from './single-shot.js';
import { run as treeRun } from './tree.js';
import { run as docsCollabRun } from './docs-collab.js';
import { run as downloadRun } from './download.js';

const TAGS = { scenario: 'mixed', tier: TIER };

export const options = {
  scenarios: {
    mixed_interactive: {
      executor: 'constant-vus',
      vus: VUS_INTERACTIVE,
      duration: DURATION,
      exec: 'interactive',
    },
    mixed_upload: {
      executor: 'constant-vus',
      vus: VUS_UPLOAD,
      duration: DURATION,
      exec: 'upload',
    },
    mixed_download: {
      executor: 'constant-vus',
      vus: VUS_DOWNLOAD,
      duration: DURATION,
      exec: 'download',
    },
  },
};

export function setup() {
  const fileIds = discoverDownloadableFiles(50);

  return {
    fileIds,
    shareToken: fileIds.length > 0 ? mintShareToken(fileIds[0]) : null,
    documentNodeIds: discoverDocumentNodeIds(20),
  };
}

function activityFeed() {
  const headers = actorHeaders(ACTOR_NORMAL);

  const storageFeed = http.get(`${API}/storages/${STORAGE_ID}/activity`, {
    headers,
    tags: { ...TAGS, class: 'interactive', op: 'OP-25' },
  });
  trackProtocolStatus(storageFeed, { ...TAGS, class: 'interactive' });
  check(storageFeed, { 'mixed activity(storage) 200': (r) => r.status === 200 });
}

export function interactive(data) {
  // operations-catalog.md: tree navigation/mutation is the base rate, docs-collab a
  // close second (autosave is itself "Высокая"), the activity feed the low-frequency
  // read tail.
  const roll = Math.random();

  if (roll < 0.45) {
    treeRun();
  } else if (roll < 0.85) {
    docsCollabRun(data);
  } else {
    activityFeed();
  }

  thinkTime();
}

export function upload(data) {
  // operations-catalog.md §1/§2: tus dominates (the historical default protocol),
  // S3-multipart a secondary path, single-shot the small-file trickle.
  const roll = Math.random();

  if (roll < 0.5) {
    tusUploadRun();
  } else if (roll < 0.85) {
    s3MultipartRun();
  } else {
    singleShotRun();
  }

  thinkTime();
}

export function download(data) {
  downloadRun(data);
  thinkTime();
}
