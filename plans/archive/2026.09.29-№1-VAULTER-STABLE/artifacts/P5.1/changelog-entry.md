### One event pipeline: DomainEvent and EventRecorder (PLAN2.P5.1)

- Added: `Vaulter\Events\DomainEvent` (occurrence `eventId`, `occurredAt`, required `ActorRef $actor`
  and `correlationId`; `type()`, `scope()`, `subject()`, `sequence()`, `data()`), `EventRecorder`,
  `EventEnvelope`, the value objects `EventScope`, `EventSubject`, `ActivityTarget` and
  `VaulterDatabase::insertOnce()` (`ON CONFLICT DO NOTHING` / `ON DUPLICATE KEY UPDATE col = col`).
- Changed (breaking): every Vaulter event extends `DomainEvent`; `$actorId` is removed, `actor` and
  `correlationId` are required, and no event implements `ShouldDispatchAfterCommit` any more.
- Fixed (C05): a change, its activity row and its outbox row commit or roll back together — a
  failed write fails the operation instead of being logged and swallowed; host listeners run after
  the commit and a failing listener is reported (`vaulter_event_listener_failures_total`).
- Removed (breaking): the subscribers `RecordsActivity`, `RecordsOutbox`, `RecordsDriveActivity`,
  `RecordsDriveOutbox` and the per-event recorder methods.
- Changed (breaking): the outbox payload is the thin webhook envelope (`id, type, version, source,
  time, tenant_id, drive_id, subject, sequence, actor, correlation_id, changes, data`); names and
  token prefixes stay in the activity feed (PLAN2.D58).
- Added: `EventType` cases `drive.archived`, `drive.unarchived`, `drive.deleted`,
  `file.revision_deleted`, `document.created`, `document.updated`, `document.revision_created`,
  `document.restored`, `comment.added`, `comment.resolved`, `comment.deleted`, with
  `activityTypes()`, `isActivity()`, `isWebhook()`; document and comment events reach the activity
  feed and webhooks for the first time.
- Added: migration `2026_10_10_000001_event_pipeline` — unique `v_activity.event_id` (stops on
  existing duplicates) and `v_outbox.tenant_id`.
- Changed (breaking): `UploadSessionManager::abort()` takes an `ActorRef` and a correlation id.

