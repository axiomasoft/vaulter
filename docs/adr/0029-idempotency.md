# ADR-0029 — Idempotency

**Status:** accepted — builds on [ADR-0028](/adr/0028-extension-slots-and-operation-pipeline)

## Context

A client that times out on a write cannot tell whether the write happened. Repeating it may create a second
folder or rename twice; not repeating it may lose the write. The `idempotencyKey` field of the operation
context existed but nothing read it. A key-value cache in front of the write would not help: a cache entry
written outside the transaction of the mutation can survive a rollback (a key that claims a write that never
happened) or be lost after a commit (a second effect on retry), and a cached response returned without asking
the owner again would leak a resource to an actor whose access was revoked meanwhile.

The IETF `Idempotency-Key` draft expired unpublished (2026-04-18); its shape is common practice, its
conformance is not something to claim.

## Decision

**1. One transaction (D25).** A keyed write claims its key in the root transaction of the
[operation pipeline](/concepts/operations) (stage 4), before the owner authorizes and mutates. The claim is a
row of `v_idempotency_keys` inserted uncommitted; the receipt is written into the same row before the same
commit. A rollback removes claim and mutation together; a commit keeps both. Nothing is cached anywhere else.

**2. Exact uniqueness.** The row is unique by `scope_digest` only: SHA-256 over the tagged tuple
(tenant kind/id, scope kind/id, actor kind/id, operation, key), each field length-prefixed and a null spelled
apart, so a missing tenant or the system actor never weakens the constraint. The key is opaque bytes (1–255),
never trimmed or case-folded. The tuple is stored as well and compared on a hit: a digest collision is
refused like a reused key.

**3. Concurrency.** A second request with the same digest blocks on the unique index of the uncommitted claim
— it never reads an "in progress" row. The wait is bounded to two seconds (Postgres `lock_timeout` local to the
transaction, MySQL `innodb_lock_wait_timeout` restored after the insert); a timeout rolls the transaction back
as a whole and answers `409 operation_in_progress` with `Retry-After`. Postgres never continues an aborted
transaction: the conflict is an `ON CONFLICT DO NOTHING` insert followed by a locking read of the committed
receipt, or a rollback.

**4. Outcomes.**

| After the claim | Receipt |
|---|---|
| success | the result, encoded by the owner's `ReceiptCodec` |
| a refusal whose problem code is `receipt: cacheable` (412 stale, domain 409/413/422) | the problem; the attempt rolls back to a savepoint first, its mutation and event sinks with it |
| authentication, authorization, visibility, validation, 428, 429, 5xx, transient | none — everything rolls back with the claim |

The receipt policy is part of every problem code (`ProblemSpec`, [error codes](/reference/errors)).

**5. Replay.** The same key with a different request fingerprint is `422 idempotency_key_reused`. The same
request is replayed only after the owner's `ReplayAuthorizer` checks the current right: on the receipt's
resource where it is now (after a move, in its new place), else on the recorded authorization scope, else on
the drive. A revoked grant, a hidden resource or a gone scope answers exactly like a new request, and the
receipt stays. A replay decodes the stored result (`ReceiptCodec::decode`, versioned; an unknown version
throws) — no mutation, no event, no CAS work. Over HTTP it carries `Idempotent-Replayed: true`; its ETag is
historical while the node is still visible to the caller: the abilities inside the tag are those of the replay, so
for a node that is gone (purged, in the trash) the replayed ETag differs from the first one.

**6. The request fingerprint.** An operation fingerprints its input: resource ids, fields, semantic options and
preconditions, never a correlation id or a clock. The HTTP layer fingerprints the request itself (method,
route, parameters, query, body, `If-Match`, `X-Vaulter-Destination-If-Match`), so a retry is recognized even
after its `If-Match` went stale because the first request committed. That fingerprint is an HMAC-SHA-256 under a
key derived from the application key: a body may carry a secret (a share password), and the stored request hash
must not let a reader of the receipts table test guesses of it without that key. Rotating the application key
turns a retry within the TTL into `idempotency_key_reused`.

**7. Retention.** A receipt lives `vaulter.idempotency.ttl_seconds` (24 hours); for replay. For the following seven days, a retry returns `410 idempotency_key_expired`.
`vaulter:idempotency:prune` (scheduled daily) clears the stored response at active expiry and deletes the tombstone after seven more days.

**8. Coverage.** `OperationRunner::write()` takes an `IdempotentWrite` (codec, replay authorizer, fingerprint);
a key given to a write without one is refused before the transaction. In 1.0 `folders.create` and
`nodes.rename` declare it; the other writes follow (P6). A composed write has the one receipt of its root.

## Consequences

- A retry under the same key makes no second effect and never returns data to an actor who lost access.
- A replayed refusal reaches PHP callers as `ReplayedProblemException` with the stored code and status, not as
  the original exception class.
- The table is part of the kernel schema; every write requires it to exist (module readiness).
- Long or asynchronous operations (202 + durable operation) are out of scope until an operation needs them.
