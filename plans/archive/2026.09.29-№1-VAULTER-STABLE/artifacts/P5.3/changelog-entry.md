## [Unreleased]

### Recoverable webhook fan-out (PLAN2.P5.3)

- Fixed (C06): an outbox row becomes `completed` only together with its last deliveries. The relay
  leases rows (`claim_token`, `claim_generation`, `claim_until`; `FOR UPDATE SKIP LOCKED` on
  PostgreSQL/MySQL), writes `webhooks.relay.fanout_chunk` endpoints per transaction behind a fenced
  token and a `fanout_cursor`, and queues jobs after commit; a crashed relay is resumed after the
  lease and a superseded one writes nothing. Zero subscribers complete with `delivery_count` 0.
- Changed (breaking): outbox statuses are `pending | completed`; `dispatched_at` is replaced by
  `fanned_out_at`; `OutboxStoreRepository::pruneDispatchedBefore()`/`countDispatchedBefore()` are
  renamed `pruneCompletedBefore()`/`countCompletedBefore()`. `vaulter:prune-webhooks` never deletes
  a pending outbox row or a pending/failed delivery.
- Added: `v_webhook_deliveries.event_id` with `UNIQUE(event_id, endpoint_id)`; every
  (event, endpoint) pair is materialized at most once.
- Added: endpoints scoped by `tenant_id` (null-safe) and an optional `drive_id` filter
  (`RegisterWebhookEndpointInput`/`UpdateWebhookEndpointInput::$driveId`, HTTP `drive_id`); the
  drive must be visible in the current tenant. Endpoints created after an event do not receive it.
- Added: the retry sweep re-queues a pending delivery whose first job was lost
  (`WebhookDelivery::scopeDueForRetry(int $pendingGraceSeconds = 0)`).
- Added: `php artisan vaulter:webhooks:legacy-fanout {--since=} {--until=} {--replay} {--dry-run}`
  for pre-1.0 `dispatched` rows, which the migration marks `completed` + `legacy_fanout`.
- Added: config `webhooks.relay.claim_seconds` (60), `webhooks.relay.fanout_chunk` (100, 1..1000);
  migration `2026_10_10_000002_webhook_fanout`.
