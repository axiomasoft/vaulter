# P5.3 negative controls (V80)

Each control: one temporary edit, focused SQLite run (`env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci …`), exact bytes restored from a copy, `sha256sum -c` OK for both files afterwards.

| # | Temporary defect | Test run | Result while broken |
|---|---|---|---|
| a | `OutboxRelay::claim()` also sets `status = 'completed'` in the claim transaction | `OutboxFanoutTest.php --filter="dies right after the claim"` | RED: 1 failed — `keeps a row pending when the relay dies right after the claim…`: expected `'pending'`, actual `'completed'` |
| b | `materializeChunk()` drops the `claim_token !== $token` check | `OutboxFanoutTest.php --filter="stale token"` | RED: 1 failed — `does not let a stale token write into a row another worker still fans out`: result not null (the stale worker wrote a chunk) |
| c | `OutboxStoreRepository::completedBefore()` matches `status in (completed, pending)` | `WebhookPruneSafetyTest.php` | RED: 1 of 3 failed — `never prunes a pending outbox row, however old, even half fanned out`: dry-run reported 2 rows instead of 1 |

Note on (b): the lost-lease test (`discards the chunk of a relay whose lease was taken over`) alone would not catch it, because the row is already `completed` when the stale chunk runs; the stale-token test covers the still-pending case.

After restore: V1 rerun green (see V1-output.txt).
