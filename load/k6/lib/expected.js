// VLT-LOAD P2.4 — 409 (NOWAIT advisory-lock) and 429 (`throttle:vaulter-public`) are
// PROTOCOL, not error (slo.md §0.4): declaring them expected keeps them out of
// `http_req_failed`, which would otherwise conflate legitimate parallel-client
// contention with a broken request. They still count toward G-4/G-5 (slo-gates.md §2)
// via the counters below — being "expected" does not make them free.
import http from 'k6/http';
import { uploadConflictTotal, throttledTotal } from './metrics.js';

http.setResponseCallback(http.expectedStatuses({ min: 200, max: 399 }, 409, 429));

export function trackProtocolStatus(res, tags) {
  if (res.status === 409) {
    uploadConflictTotal.add(1, tags);
  }

  if (res.status === 429) {
    throttledTotal.add(1, tags);
  }
}
