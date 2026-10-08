# P4.13 — independent review (read-only), one round

- Reviewer: grok/grok-4.7, effort high, headless via `plan.py bridge orch launch` (read-only wrapper); session `01a11ba9-fb3c-7ee2-899c-dd7cb493104b`; stream `review-grok-stream.jsonl` (first launch aborted at the owner's request because its brief allowed running tests: `review-grok-stream-aborted.jsonl`, discarded). Second launch: code reading only, nothing executed (owner instruction).
- Executor session: anthropic/claude-sonnet-5-5/high, `cbbae3a7-243e-44f6-9827-4e473d61dafe` — separate sessions.
- Model/effort are as launched by the bridge; the reviewer's own context window was not verified against `contextWindowTokens` (no run allowed to inspect it further).

## Findings
1. **HIGH, CONFIRMED_BY_READING — REST timestamps.** Snapshot times are `DateTimeImmutable`; the resources put them in the JSON array as objects, where Carbon used to serialise as an ISO string. Affected: created_at, updated_at, trashed_at, content_modified_at, expires_at, revoked_at (NodeResource, NodeGrantResource, NodeShareResource, ActivityResource). No test asserted these keys. **Fixed by the executor** (`SnapshotValue::iso()`, UTC, microseconds, trailing Z = Carbon's `jsonSerialize`), by reading, **not re-run** (owner: no runs after the review).
2. **LOW, CONFIRMED_BY_READING — guard coverage.** `GuardedBuilder` does not override `insertOrIgnoreReturning` (newer Laravel only) and `Query\Builder::newQuery()` builds an unbound builder, so a pivot statement built through it is not guarded. No Vaulter production path uses either. **Not fixed**: overriding a method that older supported Laravel versions lack would break them (`#[Override]`); recorded as a known gap.

Everything else the reviewer checked (authorization rebinding by id, WriteScope on production writers, snapshot fields vs spec, public signatures vs deviations) matched.
