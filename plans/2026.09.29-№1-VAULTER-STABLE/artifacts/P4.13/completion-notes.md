# P4.13 — completion notes

## Result
Public Operations (`Node/Folder/File/Trash/Access/Share/Activity`) and `DriveHandle` take `NodeSnapshot|string|int` (and revision/grant/share snapshots) and return immutable snapshots or `Page<T>`; HTTP resources and Filament actions follow; `WriteScope` guards every Vaulter model write; `docs/guide/compatibility.md` (+ru), ADR-0026, UPGRADE/CHANGELOG written.

## Checks (D51 mode L, SQLite only; receipts in `receipts/`)
V1 core full · V2 drive full · V3 core guard lane (`VAULTER_TEST_WRITE_GUARD=1`) · V4 drive guard lane · V5 filament Feature · V80 pint (core, drive, media, documents, filament, azgard) + PHPStan (root/core, drive, media, documents, filament) · V81 opus-coverage. Negative controls: `negative-controls.md`.

## Not run / not green (honest list)
- azgard: tests (18) and PHPStan fail in this environment because the AzGuard dependency is not installed; untouched by P4.13 except `tests/TestCase.php` (write_guard config line).
- The write guard was proven on the V3/V4 lanes only, not on every full suite with the guard on. Production entry points that write outside a transaction were wrapped by reading the code and by the lane runs: console commands (`OpensWriteScope`), the three jobs, the activity/outbox recorders, share redemption, stars, upload initiation. Tests that drive internal handlers/repositories directly hold the scope open (`DriveTestHelpers::insideOperation()`), and fixtures wrap direct row writes in `VaulterDatabase::write()`. Other packages' full suites with the guard on (media, documents) were not run.
- media suite: `BlobGcBatchTest` regression (a `saving` listener on Blob disabled the batched GC) was found and fixed by removing the model events from the guard; the rest of media was run once before that fix and showed only that failure.
- Review (grok/grok-4.7, read-only, one round): see `review-verdict.md`. Finding 1 (JSON timestamps emitted as `DateTimeImmutable`) was fixed after the review by reading the code (`SnapshotValue::iso()`) and **was not re-run** — the owner ruled out any run after the review; V1–V5 receipts predate this edit. Finding 2 (guard misses `insertOrIgnoreReturning`/unbound `newQuery()`) is a known low gap, not fixed.
- A REST test asserting the ISO timestamp keys does not exist yet; add one in the next item that runs HTTP tests.

## Invariants / correction rows
- Authorization: Operations look nodes up by id in the handle's drive (`NodeLocator`), snapshot fields other than `id` are never trusted (test `rebinds a stale node snapshot by its id…`); `OperationMatrixTest` and the copy/activity security tests pass unchanged in their expectations (D29).
- Locks/transactions: no Action changed; the guard adds a counter only. P4-I* lock order untouched.
- Public surface: golden `PublicApiSnapshotTest` regenerated (`UPDATE_SNAPSHOT=1`), `UPGRADE.md`, `CHANGELOG.md`.

## Beyond the dossier
See the item log (`plan.py show … P4.13`): Snapshot field deviations (status string, scalar actors, `fileId`, `Page.scanned`), guard on the base query builder without model events, `DriveHandle::model()` kept `@internal`, document-head seam, repaired P4.12 leftovers (OperationsContractTest, HttpRegistrarTest, BindingsRegistrarTest, service-locator baseline).
