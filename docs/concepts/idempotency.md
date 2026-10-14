---
title: Idempotency
description: Retrying a Vaulter write safely with an idempotency key — one effect per key, a fresh authorization on every replay, which refusals are stored, and how long.
outline: deep
---

# Idempotency

A client that times out on a write cannot know whether it happened. With an idempotency key it can simply
repeat the request: Vaulter makes at most one effect per key and answers every repeat with the outcome of the
first request ([ADR-0029](/adr/0029-idempotency)).

## Using a key

From PHP, give the handle a key; from HTTP, send the `Idempotency-Key` header.

```php
$folder = Vaulter::drive($project)
    ->actingAs($user)
    ->withIdempotencyKey($request->header('X-Client-Request-Id'))
    ->folders()
    ->create('Reports');
```

```http
POST /api/vaulter/v1/drives/01J…/folders
Idempotency-Key: "8e03978e-40d5-43e8-bc93-6894a57f9324"
Content-Type: application/json

{"name": "Reports"}
```

A key is opaque: 1–255 bytes, compared exactly — never trimmed or case-folded. Over HTTP it is one Structured
Field String (`"…"`) or one token without spaces, controls or commas; anything else is `422 validation_failed`.
Use a fresh random key (a UUID) per logical request and reuse it only for retries of that request.

In 1.0 `folders()->create()` (`POST …/folders`) and `nodes()->rename()` (`PATCH v1/nodes/{node}`) accept a key;
the other writes refuse one in PHP and ignore the header over HTTP until they declare idempotency.

## What a retry gets

| Situation | Answer |
|---|---|
| same key, same request, first one committed | the stored result; HTTP adds `Idempotent-Replayed: true` |
| same key, another request (other name, parent, `If-Match`, …) | `422 idempotency_key_reused`, nothing runs |
| same key while the first request is still running | it waits up to 2 seconds, then `409 operation_in_progress` with `Retry-After` |
| the first request failed and rolled back | nothing was stored: the retry runs as a new request |
| more than 24 hours later | the receipt expired: the retry runs as a new request |

A replay never runs the write again: no second folder, no second event, no webhook. Its ETag is the one the
first response had while the node is still visible to you (the abilities in the tag are recomputed; for a purged or
trashed node they are all off, so the tag differs); it does not satisfy a later `If-Match` by itself.

The key is scoped to the actor, the tenant, the drive and the operation: another user, or the same user on
another drive, never sees your receipt.

## Authorization on every replay

A replay is answered only after the owner checks the actor's current right, as if the request were new:

- the resource still exists → the right on it **where it is now** (moved into a folder you cannot see → `404`);
- the resource is gone → the right on the folder it was written in, else on the drive;
- the grant was revoked, the share expired or the drive is gone → the same `404`/`403` a new request gets.

The stored receipt is not changed by a denial.

## Which refusals are stored

Every [error code](/reference/errors) declares its **receipt** policy. A deterministic refusal of the request
— `cacheable`: a stale `If-Match` (`412`), a name conflict (`409`), a quota or size limit (`413`), an invalid name
(`422`) — is stored and replayed, so a retry cannot succeed by racing a concurrent change. The write rolls back
to a savepoint first: nothing of it remains except the receipt.

Authentication, authorization and visibility refusals, `validation_failed`, `precondition_required`,
`rate_limited`, `dependency_unavailable`, `5xx` and transient failures are `never` stored: the claim of the key
rolls back with them and the next retry runs again.

## Retention

`vaulter.idempotency.ttl_seconds` (86400 — 24 hours) is how long a receipt answers retries. The scheduled
`vaulter:idempotency:prune` (`vaulter.schedule.idempotency_prune`, daily at 03:30) deletes expired receipts; an
expired receipt is ignored even before it is pruned.

## For module authors

An operation becomes idempotent by passing an `IdempotentWrite` to `OperationRunner::write()`: a versioned
`ReceiptCodec` of its result, a `ReplayAuthorizer` that repeats its authorization for a replay (a codec is never
accepted without one), and the fingerprint of its input — resource ids, fields, semantic options and
preconditions, never a correlation id or a clock. A composed write has the one receipt of its root.
