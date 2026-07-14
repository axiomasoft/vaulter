// VLT-LOAD P2.4 — custom metric names, byte-for-byte from slo-gates.md §1 / harness.md
// §5.1. A typo here means a gate silently never fires (P2.5) — copy exactly, never
// rename for readability.
import { Trend, Counter } from 'k6/metrics';

export const streamThroughputMiBs = new Trend('stream_throughput_mibs');
export const uploadConflictTotal = new Counter('upload_conflict_total');
export const throttledTotal = new Counter('throttled_total');
export const ttfbMs = new Trend('ttfb_ms');
export const broadcastLagMs = new Trend('broadcast_lag_ms');
