// VLT-LOAD P2.4 — custom metric names, byte-for-byte from slo-gates.md §1 / harness.md
// §5.1. A typo here means a gate silently never fires (P2.5) — copy exactly, never
// rename for readability.
import { Trend, Counter } from 'k6/metrics';

export const streamThroughputMiBs = new Trend('stream_throughput_mibs');
export const uploadConflictTotal = new Counter('upload_conflict_total');
export const throttledTotal = new Counter('throttled_total');
export const ttfbMs = new Trend('ttfb_ms');
export const broadcastLagMs = new Trend('broadcast_lag_ms');

// Built-in data_sent/data_received do not materialize reliable per-request OP tags.
// These counters measure payload bytes accepted by the API, excluding framing.
export const acceptedSentBytes = new Counter('accepted_sent_bytes');
export const acceptedReceivedBytes = new Counter('accepted_received_bytes');
export const finalizedBytes = new Counter('finalized_bytes');
export const acceptedStreamDurationSeconds = new Trend('accepted_stream_duration_seconds');
export const streamAcceptedTotal = new Counter('stream_accepted_total');
export const streamRejectedTotal = new Counter('stream_rejected_total');

export function recordAcceptedStream(res, bytes, elapsedS, tags, statuses, direction) {
  if (!statuses.includes(res.status) || res.error_code) {
    streamRejectedTotal.add(1, { ...tags, status: String(res.status), error_code: String(res.error_code || 0) });
    return;
  }

  streamAcceptedTotal.add(1, tags);
  if (!Number.isFinite(bytes) || bytes <= 0 || !Number.isFinite(elapsedS) || elapsedS <= 0) {
    return;
  }

  acceptedStreamDurationSeconds.add(elapsedS, tags);
  if (direction === 'finalize') finalizedBytes.add(bytes, tags);
  if (direction === 'sent') acceptedSentBytes.add(bytes, tags);
  if (direction === 'received') acceptedReceivedBytes.add(bytes, tags);
  // OP-07 processes bytes already counted at OP-06; it does not send them again.
  streamThroughputMiBs.add(bytes / 1024 / 1024 / elapsedS, tags);
}
