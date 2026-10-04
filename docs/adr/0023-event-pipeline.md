# ADR-0023 — Event pipeline

**Status:** proposed

> Transfers decisions D22–D23 of the 2026-09-29 stable audit
> (`audits/2026-09-29-stable-audit/opus/02-decisions.md#d22`, `#d23`) into the project ADR set.

## Context

Activity and outbox records are written by separate subscribers (`RecordsOutbox`,
`RecordsDriveOutbox`, `RecordsActivity`, `RecordsDriveActivity`) reacting to events that implement
`ShouldDispatchAfterCommit`. Two enums describe the same occurrences (`WebhookEvent`,
`ActivityVerb`), and the catalogue does not cover documents, revisions, comments and uploads.
Outbox deduplication must not merge distinct commands (audit finding E8).

## Decision

**1. One event pipeline (D22).**

- Base class `Vaulter\Events\DomainEvent` (abstract readonly): `eventId` (ULID, created in the
  constructor), `occurredAt`, `actor` (`ActorRef`), `driveId`, `correlationId`, `aggregateType`,
  `aggregateId`, `sequence`, `type()` → `EventType`.
- `EventRecorder::record(DomainEvent)` is called **inside** the Action transaction on the Vaulter
  connection: it writes activity (if the `activity` feature is on) and outbox (if `webhooks` is on)
  with `event_id = eventId`; then `afterCommit` dispatches the Laravel event for host listeners. A
  failure writing outbox/activity rolls the operation back (durable contract).
- Outbox deduplication is by occurrence id only (delivery retry); commands are always distinct
  (E8). Command idempotency is a separate mechanism (later, `Idempotency-Key`).
- `WebhookEvent` and `ActivityVerb` merge into `Vaulter\Enums\Events\EventType`; the catalogue is
  extended with documents, revisions, comments and uploads.
- The subscribers `RecordsOutbox`, `RecordsDriveOutbox`, `RecordsActivity`, `RecordsDriveActivity`
  are removed; events no longer implement `ShouldDispatchAfterCommit` (the recorder provides
  after-commit).
- The webhook payload is a CloudEvents-style envelope: `id, type, source, time, drive_id, subject,
  sequence, data`.

**2. `v_nodes.sequence`: one node version for ETag and ordering (D23).** An integer `sequence` is
incremented on every node mutation (rename, move, trash, restore, new revision, content change).
It is used as the ETag (strong `"{id}.{sequence}"`; a weak `W/"{sequence}"` is not needed), as
`expectedSequence` in the PHP API (`StaleNodeException`, 412) and as the event `sequence` (ordering
for webhook consumers).

## Consequences

- Activity, outbox and the domain change commit or roll back together; host listeners see an event
  only after commit.
- Every occurrence has one identity (`eventId`) shared by activity, outbox and the webhook
  envelope; delivery retries deduplicate on it.
- One `EventType` catalogue serves activity and webhooks.
- One counter drives HTTP concurrency control (ETag / `If-Match`), PHP optimistic checks and event
  ordering.

## Alternatives rejected

The source decisions D22–D23 record no rejected alternatives.

## References

- `audits/2026-09-29-stable-audit/opus/02-decisions.md#d22`
- `audits/2026-09-29-stable-audit/opus/02-decisions.md#d23`
- `audits/2026-09-29-stable-audit/opus/08-data-model-and-migration.md` §6 (event catalogue)
- [ADR-0020 — Authorized public API](/adr/0020-authorized-public-api) (Actions publish through `EventRecorder`)
