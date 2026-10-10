# grok/grok-4.7 session 01a123f0-1a6e-7d72-8b81-c6a47327e311

VERDICT: FINDINGS

### P5.3-1
- **Severity:** major
- **File:** `packages/core/src/Repositories/Webhooks/OutboxStoreRepository.php:83`
- **Defect:** `pruneCompletedBefore()` selects completed outbox ids, then deletes with `whereKey($ids)` only. `replayLegacy()` (line 134) can turn that same row back to `pending` after the select and before the delete. The delete still removes it. That drops the only copy of a legacy event that had no deliveries, which breaks P5-I6 (a pending outbox row is never pruned). `WebhookPruneSafetyTest` never changes the row between the select and the delete, so it stays green.
- **Reproduction:** Scratch Pest test on SQLite (`/usr/bin/php8.4 vendor/bin/pest --ci /tmp/P53PruneRaceTest.php`; `scripts/php84` could not symlink under `build/`). A completed `legacy_fanout` row with `fanned_out_at` 40 days ago was selected by `pruneCompletedBefore(now()->subDays(30))`. `DB::beforeExecuting` called `replayLegacy()` on the delete statement; the row’s status was `pending`, then the delete ran. Pest exited 1: `Failed asserting that 1 is identical to 0.` (2 assertions: status-at-delete was `pending`, deleted count was 1). Existing `OutboxFanoutTest`, `WebhookPruneSafetyTest`, and `LegacyFanoutCommandTest` passed (21 tests).
- **Suggested fix:** Delete only rows that still match the completed predicate, for example `completedBefore(...)->whereKey($ids)->delete()`, so a row that replay has already set to `pending` is left alone. Do the same if a later round selects a new id set.

**Plausible:** `v_webhook_deliveries.event_id` is `string(64)` while `v_outbox.event_id` is `string` (255) and `DomainEvent` accepts any id. On MySQL/PostgreSQL a longer id would throw inside `createDeliveries()`, roll the chunk back, and leave the outbox `pending` forever. SQLite does not enforce VARCHAR length, so this was not shown here.

**Not covered:** PostgreSQL/MySQL `SKIP LOCKED` races, `SchemaParity`, two OS processes, and the P5.4 signing/SSRF path.