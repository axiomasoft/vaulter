# P1.10 — legacy-inventory (0.x removal, D21)

Decision rule: a hit is **remove** only when its *intent* is to read, translate, repair or prove 0.x data/config/schema
(the data path exists only through an upgrade). It is **keep** when current behaviour depends on it, and **move** when a
live assertion sits inside a historical test and is carried into a live test first. A keyword (`legacy`, `upgrade`,
`backfill`, `deprecated`) alone never decides. Ties are kept and listed here.

Baseline of the tree: `c64bba25` (product tree identical, original schema captured at `b3dfe7f1`).

## 1. Migrations (`packages/core/database/migrations`)

| Item | Class | Evidence |
| --- | --- | --- |
| 43-file chain (16 shell creates, `drop_legacy_docvault_tables`, preflight, renames, host-key widening, backfills, FK/index rewrites, rollback guards) | **remove**, replaced by 25 one-table `create` migrations | schema equal per preset: `scripts/schema-snapshot.sh --compare=original` (sqlite/pgsql/mysql × 48) |
| `SchemaBuilder::createTable/dropTable` per entity (current definitions, head FK in the last create of a cycle) | **keep** | the new migrations are thin shells over them |
| Pgsql chain failure on 20 presets (`bigint.uuid.*`, `uuid7.*`) | recorded | original for those rows is the builder schema (`original/metadata.json` `builder_fallback`) |

## 2. Source — remove (historical only)

| Path / symbol | Why historical |
| --- | --- |
| `core/src/Schema/UpgradePreflight.php`, `Exceptions/Schema/UpgradePreflightException.php`, problem code `upgrade_preflight_failed` (`CoreProblemCodes`, `resources/lang/*/problems.php`, public-api snapshot, errors docs) | proves 0.2 data before the 0.3 rename; no preflight exists in a create chain |
| `core/src/Console/UpgradeInspectCommand.php`, `ConsoleRegistrar` entry | read-only report of 0.2 data |
| `core/src/Console/LegacyFanoutCommand.php`, `OutboxStoreRepository::ambiguousLegacy()/replayLegacy()` | replays pre-1.0 relay rows; no such rows are created by 1.0 code |
| `DatabaseConfig::legacyTable()` and config key `vaulter.table_names` | read only by upgrade migrations/preflight |
| `ConfigNormalizer` `keyMap()`, `translate()`, `deprecate()`, `normalizeDocumentTypes()` (0.2 `enabled_document_types`/`spreadsheets`), `normalizeUploadLimit()` (0.2 `max_bytes`/`max_upload_kb`), ignored-removed-key warnings, `usedDeprecatedKeys()`; `Doctor\Checks\DeprecatedKeysCheck` (+ registrar) | translate 0.x config keys; the published config carries only canonical keys |
| `DocumentsConfig` exporters `format => class` map deprecation | 0.2 exporter shape |
| `SchemaBuilder::DOCUMENTS_SLOT` branches | the frozen 0.2 shells named the document slot by the old entity |
| `SchemaBuilder::addDriveChanges()/removeDriveChanges()/addScanEvidenceAndWork()/removeScanEvidenceAndWork()/addNodeSequence()/removeNodeSequence()` as *additive upgrade steps* | called only by the additive 0.3 migrations; `createTable()` creates the same objects. `addNodeSequence()` stays as a private step of `afterCreate('nodes')` |
| `media/src/Console/RepairBlobScopesCommand.php`, `media/src/Doctor/LegacyScopesCheck.php` | move 0.x `legacy:snull`/`legacy:tnull`/`global` blobs |
| `core/src/ValueObjects/Cas/BlobScope`: `legacy()`, `isLegacy()`, `global` kind, legacy segment parsing; `media/src/Cas/Sha256ContentStore` legacy shard | parse 0.x scope keys/layout; `tenant`/`drive`/`off` and the current key format stay |

## 3. Source — keep (not 0.x)

| Path / symbol | Why live |
| --- | --- |
| `v_outbox.legacy_fanout` column, `Outbox` cast/property | DDL parity with the unchanged schema (P1.10); removed with the neutral-schema decision D29 in P3. `WebhookPruneSafetyTest`, `CoreDoctorChecksTest` rows use the column |
| `ScanBackfillRun`/`ScanBackfillReconciler`, scan requests/obligations, scanner retry/replay, `ReconcileScanWorkCommand`, `ScanScopeCheck`/`ScanPolicyCheck` | scan policy activation backfills existing content at runtime (`ScanLifecycleContractTests`) |
| `CompleteUploadAction::backfillLegacy()` | covers a committed session whose fingerprint was cleared; asserted by `CompleteUploadIdempotencyTest` (keep, listed because the name says "legacy") |
| `Document::legacyFormat()`, `DocumentTypeKey` `legacy/` namespace | current JSON key and the "unknown type keeps its value" compatibility of `DocumentType` |
| `ContentDecoder` / `UpgradesSchema` | upgrade of stored document *content schema versions* on read — a live document-type contract, unrelated to 0.x installs |
| `Profile` upload `maxFileSize` carry into `DrivePolicy` | current profile builder API |
| `IdStrategy`/`AutoIncrementIds` docblocks, `NodeAbilities`, `ShareContentGuard`, `ShareResolver` null-issuer, `FileRevisionStoreRepository`, `Node` docblocks, `TipTap*` "legacy snapshot" sanitising, `BinaryString` | comments or defensive reads of current data; no 0.x path |
| `Collaboration*`, ADR-0014 "legacy DocVault strangler" references in comments | historical rationale in comments, no code path |
| `ConfigNormalizer`: package defaults registration, deferred loading, merge of missing nested defaults, replacement maps/lists (`ids.strategies`, `handlers`, `vaulter-documents.types`, `access.*` maps) | canonical behaviour, `ConfigDefaultsLoadingTest` |

## 4. Tests

| Path | Class |
| --- | --- |
| `core/tests/Upgrade/*` (19 test files, `LoadsV02Fixture`, `FixtureTestCase`), `tests/fixtures/v0.2.0`, `scripts/fixtures/*`, `Fixtures/config/vaulter-0.2.php` | **remove** (historical fixtures and upgrade proofs) |
| `Upgrade/SchemaParityTest` serializer (partial predicates, expression indexes, collations) | **move** → `Support/Schema/SchemaSnapshot` (raw catalog oracle) |
| `Upgrade/FreshFeatureFlagsMigrationTest` (all features off, rollback) | **move** → `schema-snapshot.sh --rollback` per preset and `SchemaSnapshotTest` (schema independent of feature flags) |
| `Upgrade/DriveChangesUpgradeTest` "first mutation is seq 1" | live behaviour covered by `Changes/ChangeJournalTest`; the fixture variant is historical |
| `Upgrade/OffDedupRollbackSafetyTest`, `BlobScopeKeyMigrationTest` | guards of the removed 0.x migrations |
| `Console/UpgradeInspectCommandTest`, `Webhooks/LegacyFanoutCommandTest`, `media/tests/Cas/RepairBlobScopesCommandTest` | **remove** with their commands |
| `Configuration/PublishedConfigV02Test`, 0.x branches of `ConfigNormalizerTest`/`ConfigDefaultsLoadingTest`/`CoreDoctorChecksTest`/`VaulterConfigTest`/`DocumentsConfigTest`, `ConfigurationArchitectureGuard::assertPublishedV02Fixture` | **remove** the 0.x cases; **keep** defaults/merge/replacement-map cases |
| `Support/UpgradeGuideCoverageTest` | **remove**: it demands one `UPGRADE.md` section per 0.x breaking change; UPGRADE.md has no 0.x content (D21) |
| `Unit/BlobScopeTest`, `Cas/ScopeKeyReconcileTest`, `Cas/MutationContractsTest` legacy-scope cases | **remove** the legacy cases, keep tenant/drive/off |
| `Schema/CanonicalSchemaTest` "legacy table overrides" | **keep**, `table_names` is simply ignored |

## 5. Docs and baselines

`UPGRADE.md` → "no migration bridge" (CHANGELOG keeps 0.x history). Command/config/error/webhook/storage/troubleshooting docs
lose the removed commands, `table_names` and the preflight code (en + ru). `tests/baseline/CHANGES.json` records every
removed test name with D21 and a live replacement.

## 6. Outcome

- Migrations: 43 → 25 `create_v_<table>_table`; `scripts/schema-snapshot.sh --compare=original` is equal on sqlite, pgsql and mysql in all 48 presets (144 rows) with `--rollback` leaving an empty schema. 20 pgsql rows have a builder-sourced original (the 0.x chain fails on an empty database there).
- Removed tests (ledgered in `tests/baseline/CHANGES.json`, decision D21): core 392, media 18, drive 1 (renamed), documents 1. Moved live assertions: schema oracle → `Support/Schema/SchemaSnapshot`; fresh/rollback → `schema-snapshot.sh --rollback`; signed 64-bit sequence, scan constraints, host-key DDL → their schema tests on the created tables.
- Kept although named "legacy" (see §3): `legacy_fanout` column, `backfillLegacy` upload branch, `Document::legacyFormat`, document content `UpgradesSchema`, scan backfill.
- Not part of P1.10 and left as found: `EventTypeTest` (enum changed in PLAN2.P5.2), `ProblemMappingTest::render()`, `P83DocumentsMetricsTest` (`users`), azgard real-AzGuard stubs (`PanelBuilder::scopes()`), the `load` suite (`Vaulter\Support\Config`, redis/otel), parallel-only flakes (`ErrorsReferenceTest` helper defined in another file, `CompleteUploadIdempotencyTest` serial group).
