# Review R2 — P3.9 OperationRunner (concurrency/transactions)

Reviewer: R2, session model Sonnet 5.5 (claude-sonnet-5-5), read-only. Date 2026-10-14. Base `04aa3640` + working tree.

## Verdict: APPROVE_WITH_NITS

No BLOCKER. Stage order, middleware contract, nesting and the whole-attempt retry match the P3.9 rules and ADR-0028.
Findings 1 (afterCommit exception re-runs a committed attempt) and 2 (singleton `depth` under coroutine Octane) are
HIGH/MEDIUM design risks worth fixing in Part B (small changes); the rest are MEDIUM/LOW or questions.

## Findings

### F1 — MEDIUM (borderline HIGH): an after-commit callback failing with a concurrency error re-runs an already committed attempt
- `packages/core/src/Operations/OperationRunner.php:118-124` + `packages/core/src/Database/VaulterDatabase.php:114-128` (`retryingOutermost`).
- Mechanism: in `ManagesTransactions::transaction()` (vendor) `$this->transactionsManager->commit(...)` runs the after-commit
  callbacks AFTER the PDO commit and outside the try/catch. An exception from a callback leaves `transaction()` and reaches
  `retryingOutermost`, where `transactionLevel() === 0` and `isConcurrencyFailure()` is true, so the closure runs again.
  `VaulterDatabase::$attempt->aborted` only covers a failed commit, not this case.
- Scenario: `DeleteDriveAction` registers `afterCommit(PurgeDriveJob::dispatch($driveId))` (drive/src/Actions/Drives/DeleteDriveAction.php:33).
  With a database queue connection the dispatch is an INSERT. If it fails with SQLSTATE 40001/40P01, the committed
  delete is repeated as attempt 2 (second set of events, `NotFound`/duplicate mutation). The same applies to
  `CompleteUploadAction` cleanup and `ScanWorkReconciler::publish` callbacks (those that touch the DB or a queue).
  `EventRecorder`'s callback catches `Throwable` (EventRecorder.php:99), so events are safe. The others are not.
- Violates: ADR-0028 stage 9 "commit, then after-commit callbacks, result never re-read" and "a repeated attempt repeats only DB work";
  R-invariant "exactly one set of events".
- Fix: record "committed" inside the closure (e.g. a flag set by the first `afterCommit`/`committed` hook, or wrap the
  `transaction()` call result: once `transaction()` has returned/committed, any later exception from callbacks must not
  be retried). Simplest: in `transact()` set `$committed = true` via `$this->connection()->afterCommit(...)` registered first
  (runs first), and have the retry predicate refuse a retry when `$committed`. Add a test with an after-commit callback throwing a 40001.

### F2 — MEDIUM: `OperationRunner` is a process singleton with mutable `$depth`; the package treats Octane as concurrent (WriteScope is `scoped`)
- `packages/core/src/Providers/BindingsRegistrar.php:67` (`singleton(OperationRunner::class)`), `OperationRunner.php:31,129-137`.
- Scenario (Octane Swoole/OpenSwoole with coroutines, or any Fiber-based concurrency): request A is inside its root transaction
  and yields on I/O (`depth=1`); request B calls `write()` on the same runner, sees `depth > 0`, takes the `nested` branch,
  runs its attempt with no transaction of its own (no retry, no claim, no commit boundary; `lockTree`/`advisoryLock` then throw
  `requireTransaction` LogicException, other writes go out unwrapped). The comment on WriteScope ("Octane tick") shows the
  project already treats this as a real case; `VaulterDatabase::$attempt` has the same shape (pre-existing).
- Question/fix: bind `OperationRunner` as `scoped` (Octane flushes per request/tick; `ModuleReadiness` stays singleton on purpose),
  or derive "nested" from per-connection state rather than a runner field. Without an Octane/coroutine target this is a latent risk, not a proven failure in the supported single-request-per-worker mode.

### F3 — MEDIUM: readiness catalog query on pgsql uses `current_schema()` only
- `packages/core/src/Operations/ModuleReadiness.php:85` (`table_schema = current_schema()`).
- Scenario: a pgsql connection with `search_path => 'tenant,public'` (or `schema => ['app','public']`) whose Vaulter tables live in the
  second schema. Laravel and `SchemaTablesCheck`-style `Schema::hasTable` find them; `current_schema()` is the first existing schema only,
  so `$tables` lacks them and EVERY write fails with `module_not_ready` (503) although the schema is migrated.
  Previously such a setup worked. Fix: `table_schema = any(current_schemas(false))`.
- Violates: P3.9 rule on readiness not blocking healthy installs (regression risk; pgsql run is Part B V2).

### F4 — LOW: an unsupported idempotency key surfaces as a 500, not a problem
- `NoOperationClaim.php:19-22` throws `InvalidArgumentException` (not a `ProblemException`). `HttpOperationContextFactory` does not read an
  `Idempotency-Key` header (grep found none in `core/src/Http`), so this is unreachable over HTTP today; a PHP caller that sets a key gets a raw exception, counted as `error` in telemetry.
  Question for P3.12: should it be a typed problem (e.g. 400/`idempotency_not_supported`)? The ADR text only says "refused before the transaction".

### F5 — LOW: `OperationSpec::$mutating` is never read
- `OperationSpec.php:19`, no consumer in `packages/*/src` (grep). Every spec opens a root transaction. Either drop the flag (public API snapshot affected) or make it meaningful. Dead public surface just before the 1.0 freeze (D-api).

### F6 — LOW: a middleware that calls `$next` twice leaves a committed write behind an `extension_failed` 500
- `OperationRunner.php:80-82`. The second call is refused before it runs (good), but the first already committed; the caller gets a 500 for a succeeded write. Inherent to the contract; document in the middleware slot docs (it is only partly stated in ADR "never retried") so a client does not blindly retry without idempotency.

### F7 — LOW: telemetry counts nested writes as separate operations
- `TelemetryMiddleware.php:55-56`: `outcome`/`operation` labels carry no `nested` marker, so a composition (outer+inner) doubles `vaulter_operations_total` and nests durations in the histogram. The span has the `nested` attribute, the metrics do not. Also a throwing `MetricsRecorder` in `finally` would mask the operation's exception (default recorder is null, so theoretical).

### F8 — LOW (test gaps, no failure shown)
- No test for: retry exhaustion → `ConcurrentUpdateException` after 3 attempts; non-concurrency error not retried; `depth` reset after a throwing attempt (`inOperation()` false afterwards); behaviour inside a host transaction (`transactionLevel() > 0`: no retry, callbacks wait for the host commit — correct by code, untested); nested write throwing a deadlock inside an outer write (retried once at level 0); `attemptNo` reaches the middleware as 1 only (by design).
- `ModuleReadinessTest` and `TelemetryMiddlewareTest` report 3 "risky" tests under scripts/t (parallel run); check whether they are risky because of listener leakage (`Event::listen(QueryExecuted)` in `readinessQueries` is never removed).

## Questions (no provable scenario)
- Q1: `ModuleReadiness` checks core + the operation's own module tables only (`OWNED_ENTITIES`: core = outbox). A `documents` write does not require the `drive` tables (nodes) or `media` blobs although documents depends on them; is the dependency closure intended to be out of scope? (Failure would be a late SQL error instead of 503.)
- Q2: Nested detection is `depth > 0` regardless of the real DB level; a write called from inside a user `DB::transaction()` is a root write at level>=1 (no retry, afterCommit deferred to the host commit). Confirm this is the documented behaviour (not in ADR).
- Q3: `kernel->assertValid()` and `readiness->assertReady()` run before the middleware chain, so refused writes produce no telemetry span/metric. Intended?

## Checked and found correct
- Stage order (graph -> readiness -> middleware -> root tx -> claim -> attempt) in `OperationRunner::write/transact`; admission precedes any transaction.
- Middleware: exactly one `$next` (second call rejected before running), `===` on result, `$passed === $given`, veto before `$next` leaves no mutation (test), swallowed failure -> `extension_failed` (not a silent success), order by contribution.
- Nested: no claim, no `retryingOutermost`, no own transaction (only `enter()`), callbacks belong to the outer attempt; rollback of nested with outer (tests).
- Retry: only around the whole attempt at level 0 with `transaction(attempts: 1)` so Laravel never re-runs the callback itself; `attemptNo` increments; events/callbacks of the rolled-back attempt are dropped (one set - test with injected 40001).
- `depth` is restored in `finally` on exceptions (code reading; not tested directly).
- Readiness: no query at construction/boot; first write one query; memo per ready module; not-ready module re-checked and `tables` cache dropped on miss; prefix handled via `getTablePrefix()` + `DatabaseConfig::table`; mysql `database()` branch correct; sqlite branch correct.
- `InvalidExtensionResult` / `ModuleNotReadyException` implement `ProblemException` with `extension_failed` 500 / `module_not_ready` 503; lang keys present in en (problems.php:38, :89); `SchemaTablesCheck::OWNED_ENTITIES` is checked against the doctor list by a test.
- Telemetry attributes come from `context->telemetry()` (test: no user id, tenant id, idempotency key), outcome classification `ok/rejected/error`.
- ADR-0028 text matches the code (stages, retry budget 3, readiness).

## How checked
- Read in full: OperationRunner, Operation, OperationSpec, OperationMiddleware, OperationClaim, NoOperationClaim, ModuleReadiness, TelemetryMiddleware, InvalidExtensionResult, ModuleNotReadyException, VaulterDatabase (transaction/afterCommit/retryingOutermost), diffs of CoreModule / BindingsRegistrar / SchemaTablesCheck, OperationRunnerContractTest, ModuleReadinessTest, TelemetryMiddlewareTest, ProbeMiddleware, ADR-0028 (stages/consequences), Laravel `ManagesTransactions::transaction` (vendor) for F1.
- `plan.py context ... P3.9 --stdout` (rules/scope/validation).
- Ran: `scripts/t sqlite -p core packages/core/tests/Operations/ModuleReadinessTest.php packages/core/tests/Operations/OperationRunnerContractTest.php packages/core/tests/Observability/TelemetryMiddlewareTest.php` -> 14 + 9 tests passed (3 risky).
- Not run (by rules): pgsql/mysql, full suites. F1 and F3 are from code reading and vendor source, not executed; confirm with a pgsql test in Part B.
