Verdict: GREEN

The diff matches the P1.4 contract. All 23 `final readonly` events in core (4), drive (13), and documents (6) keep their existing constructor order and `ShouldDispatchAfterCommit` / `ShouldBroadcast` interfaces, and append an optional `?string $eventId = null`. Each assigns `public string $eventId` once, to the supplied id or `(string) Str::ulid()`.

`OutboxRecorder::stage()` still deduplicates with the same `event_id` exists-check, then inserts. The four kernel handlers and the ten `RecordsDriveOutbox` handlers pass `$event->eventId`. The column and `payload['event_id']` are set from that same value. Rename A→B→A→B, trash→restore→trash, same-object replay, grant created/revoked 2+2 with replay, and the HTTP retry all assert that identity. Snapshots freeze every constructor and property. `UPGRADE.md` documents ULID occurrence ids, legacy queued ids, and `stage(key:)` → `stage(eventId:)`.

No `DomainEvent`, CloudEvents, `EventRecorder`, idempotency-key, activity-schema, or transaction/concurrency redesign appears in the diff.

Optional notes (not findings): share and upload-session events follow the same `eventId` pattern, with acceptance coverage on the grant and Drive lifecycle paths. The force-delete test now checks distinct ULIDs, which matches occurrence identity.

Limitations: static review of the supplied diff only; no live tool verification. Cited gates are the ones you reported (core 596 pass / 11 skip / 17 risky, Drive 451 pass / 8 skip / 2 risky, documents 245 pass / 1 skip, V06 3 pass, Webhooks 81 pass / 3 skip, Pint/PHPStan exit 0).
