# P1.5 whole-work review remediation

Initial whole-work review: grok-4.7/high, 500000 context; Verdict RED. Findings preserved in grok-review-first.md.

1. Major: repair sorted CAS keys lexically while batched GC sorted PostgreSQL hashtext keys. Both now use BlobLock::acquireMany, sorting distinct actual advisory lock values. On MySQL both take row locks through the primary index in ascending primary-key order before individual repository access. The repair scan uses the primary index to include a not-yet-created target and prevent opposite source/target order; MySQL was inspected, not concurrency-tested locally.
2. Minor: dry-run acceptance now asserts exactly two move table rows and both target drive identifiers.
3. Minor: opening diagram and ContentStore::keyFor documentation now show the new one-shard layout while retaining the legacy-layout contract.
4. Evidence gap: V01 creates two real rowless objects and asserts exact orphan keys in all nine ID/scope combinations.

Regression: a forked repair runs while GC owns the lower actual PostgreSQL lock. The barrier waits for the repair's real pg_stat_activity advisory wait, then GC marks its batch. The old implementation deadlocks and retries (red log: two target acquisitions versus one); the new implementation completes once and preserves revision pointer, target references and readable bytes. PostgreSQL activity snapshots are refreshed during the bounded wait. No sleep-based scheduling assumption.

The first review packet included first-run failures, corrected before review completed. Current validation.json and all referenced final logs are supplied for fix verification, together with numeric-coercion red/restore evidence. No failed run is promoted to green.
