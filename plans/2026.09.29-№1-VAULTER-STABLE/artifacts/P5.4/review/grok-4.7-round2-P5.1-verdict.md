# grok/grok-4.7 session 01a123f0-1a6e-7052-bf44-7bb82ff7d174

VERDICT: FINDINGS

### P5.1-1
- **Severity:** major
- **File:** `packages/core/src/Events/EventRecorder.php:81` (retry entry: `packages/core/src/Database/VaulterDatabase.php:43`)
- **Defect:** A commit-time concurrency failure retries the Action and still dispatches the rolled-back attempt. `VaulterDatabase::transaction()` passes `attempts: 3` into Laravel's `Connection::transaction()`. If `PDO::commit()` throws a concurrency error, `handleCommitTransactionException()` rolls the PDO transaction back and decrements the level without telling `DatabaseTransactionsManager`. The `afterCommit` closure registered for that attempt stays pending and runs on the next successful commit, so host listeners observe an occurrence that has no activity or outbox row. `UPGRADE.md` says listeners run after the commit, exactly once per occurrence (P5-I4).
- **Reproduction:** `/tmp/p51-repro.php` (removed after the run) listened for `NodeRenamed`, threw `PDOException('database is locked')` once from `TransactionCommitting` (same `try` as `PDO::commit()`), then renamed a folder. `scripts/php84` could not create its shim (`Permission denied`); the run was `/usr/bin/php8.4 vendor/bin/pest --ci /tmp/p51-repro.php` with `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:`. Failed: `$seen` was `[01M4J05BMW6NPWAMP5H5WNAKBK, 01M4J05BN2E45JRD19SS79DVZ2]` and the outbox held only `01M4J05BN2E45JRD19SS79DVZ2`.
- **Suggested fix:** Do not retry inside Laravel's `transaction()`. Run one attempt, and on a concurrency failure clear that attempt's `DatabaseTransactionsManager` callbacks before starting the next one (`Connection::rollBack()` cannot, because the handler has already set the level to 0). Gate the closure at `EventRecorder.php:81` on a per-attempt token captured in `record()` so a stale callback does not dispatch.

### P5.1-2
- **Severity:** major
- **File:** `packages/core/src/Models/Webhooks/Outbox.php:87` (built at `packages/core/src/Webhooks/EventEnvelope.php:52`, signed at `packages/core/src/Jobs/Webhooks/DeliverWebhook.php:193`)
- **Defect:** Empty `data` is stored as `{}` and delivered as `[]`. `EventEnvelope::fromEvent()` uses `stdClass` so an empty `data()` encodes as an object, which the design requires for `node.renamed`, `node.trashed`, `drive.archived`, `drive.unarchived`, and `drive.deleted`. The `payload` cast is `'array'`, so a read does `json_decode(..., true)` and `{}` becomes `[]`. `OutboxRelay` copies `$message->payload` onto the delivery, and `DeliverWebhook` signs `json_encode` of that array. The signed body is not the envelope that was stored.
- **Reproduction:** Same Pest run, test "delivers an empty data object, not an array", after renaming a folder. The raw `v_outbox.payload` contains `"data":{}`. `json_encode` of the cast attribute does not. The failure string was `..."changes":null,"data":[]}'` (length 336) against `"data":{}`.
- **Suggested fix:** Persist the envelope as the exact JSON string (empty `data` stays `{}`, with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR`) and copy that string into the delivery body. Do not round-trip it through an associative `array` cast.

**Plausible:** `EventEnvelope::fromEvent()` never walks `FORBIDDEN_KEYS`. No event `data()` at `b9b3cbad` contains those keys, so this was not executed.

**Not covered:** PostgreSQL and MySQL `insertOnce` affected-row counts, two-connection races, SchemaParity, the full suite, and the journal lock (P5.5; `record()` does not take it yet).