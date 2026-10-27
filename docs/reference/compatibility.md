---
title: API compatibility
description: What the Vaulter 1.0 compatibility promise covers — operations, snapshots, enums, exceptions, events, config keys and the upgrade path — and what it leaves out (models, Actions, tables).
outline: deep
---

# API compatibility

From 1.0 Vaulter follows [Semantic Versioning](https://semver.org). This page says what the promise
covers. Before 1.0 nothing is frozen: renames and removals are made outright and each has an entry in
[`UPGRADE.md`](https://github.com/axiomasoft/vaulter/blob/main/UPGRADE.md) (see
[ADR-0020](/adr/0020-authorized-public-api) and [ADR-0026](/adr/0026-public-api-snapshots)).

## What is the public contract

| Surface | Promise |
| --- | --- |
| Operations and the handle | The interfaces in `Vaulter\Contracts\**` (`DriveHandle`, `NodeOperations`, `FolderOperations`, `FileOperations`, `TrashOperations`, `AccessOperations`, `ShareOperations`, `ActivityOperations`), the `Vaulter` facade, and the documents operations. Method names, **parameter names** (callers use named arguments), parameter and return types. |
| Snapshots and pages | `NodeSnapshot`, `FileRevisionSnapshot`, `GrantSnapshot`, `ShareLinkSnapshot`, `ActivityEntrySnapshot`, `DriveSnapshot`, `QuotaSnapshot`, `MintedShareLink` and `Page<T>`: their properties, for **reading**. Constructors are `@internal`: Vaulter builds snapshots, a host never does. |
| Enums | Public enums are marked `@closed` (the case set is fixed: a new case is a MAJOR change) or `@extensible` (a client must handle an unknown value). Treat an unknown `NodeKind` or document type as "download only". |
| Exceptions | The classes and their machine codes (`node_not_found`, `quota_exceeded`, …) listed in [Errors](/guides/errors). A code never changes; a new code is MINOR. |
| Events | The Laravel event classes and their public fields. |
| Configuration | Keys of `config/vaulter.php` and the other packages. A deprecated key keeps working through the normalizer until the next MAJOR release. |
| Upgrade path | Upgrade migrations run in order from any 1.x schema; after 1.0 migrations are additive (expand, migrate, contract). |
| REST | The routes and JSON of the API version in the URL. v1 is frozen when it ships (see [REST reference](/reference/rest-api)). |

A change is **MAJOR** when it removes or renames something above, narrows a parameter type, widens a
return type a caller may rely on, changes a machine code, or removes an enum case. Adding an optional
parameter, a method to a result type, a new exception code, a new enum case of an `@extensible` enum, a
config key or an event is **MINOR**.

A new config key is added this way, for example the upload completion budget
`vaulter-drive.uploads.resumable.complete_timeout_seconds` (`VAULTER_UPLOAD_COMPLETE_TIMEOUT_SECONDS`,
integer 1..300, default 60, PLAN4-D19). It bounds one completion request, DB retries included; when it runs
out the completion is refused with `upload_conflict` (409), nothing is published and the staged bytes stay
for a repeat.

The budget is enforceable only on a local staging disk. Resumable completion (`tus`, `s3-multipart`; the names are protocols, not storage capabilities) on a remote, S3 or opaque custom disk is **unsupported**: it is refused with `upload_conflict` (409) before any storage I/O. Upgrade note: stage resumable uploads on a local disk; single-shot `direct` upload and the content-addressed store on S3 are unaffected. A session already committed before this change may still run its pending staged cleanup on the staged disk without a hard deadline (known limitation, owner runbook P8.2).

### I13: current PHP surface

After P6.3 the PHP API/SPI catalog follows the current source: I13 uses block mode with zero temporary exceptions and zero permanent host-input entries. Readonly results include `UploadSessionSnapshot`, change pages and nested snapshot facts; events carry snapshots and scalar facts instead of domain ORM models.

`NodeSnapshotContributor` remains a supported implement-SPI: `contribute()` receives model-free `list<NodeSnapshot>` before fragments, viewer state or reader keys are added. Load owned aggregates by node id. `HasDrives` remains an available trait, with an internal ORM `drives()` relation; supported listing uses `Vaulter::drives($actor)` returning `Page<DriveSnapshot>`. ORM configuration getters and native factories are internal; configuration keys remain valid. Shared BC qualification and the common guard/corpus belong to P6.2; this catalog update does not claim they passed.

## Packages and versions

Every PHP package is released with one version and one tag, and each split repository
(`axioma-studio/vaulter-core`, `-media`, `-drive`, `-documents`, `-webhooks`, `-filament`, plus the
`axioma-studio/vaulter` metapackage) receives the same tag. Packages require each other as
`self.version`, so mixing versions is not possible. `axioma-studio/vaulter-azgard` is released
only once AzGuard itself has a tagged version; until then it is left out of the release.
`@vaulter/vue` has its own semver line and declares the REST API line it speaks in `package.json`
(`"vaulter": { "api": "1" }`). The release set is listed in `release/packages.json`.

## What is not

- **Eloquent models** (`Vaulter\Models\**`), their columns, relations and scopes. They are `@internal`:
  the operations never return one, and a model written outside an operation is refused (see below).
  `DriveHandle::model()` exists for Vaulter's own packages and tests.
- **Actions, Queries, repositories** and everything else under `src/` that is not listed above.
- **Tables and columns.** The schema is stable (only additive migrations after 1.0), but it is reached
  through the PHP API; see [Database compatibility](/reference/compatibility).
- Any class or method with `@internal` in its docblock, and anything in a `Testing` namespace.

## The write guard

Vaulter models refuse `save()`, `delete()` and mass `update`, `delete`, `insert`, `upsert` and
`increment` outside a Vaulter operation and throw `WriteOutsideOperationException`
(`write_outside_operation`, see [Errors](/guides/errors#write-outside-operation)). It is a second line of
defence behind the invariants in the Actions, not a sandbox: `DB::table()` and raw SQL are not
intercepted. Set `vaulter.database.write_guard` to `false` (`VAULTER_DB_WRITE_GUARD=false`) only if a
migration of your own code needs more time.

## Writing code that survives upgrades

```php
$drive = Vaulter::drive($project)->actingAs($user);

$report = $drive->files()->upload($source);                    // NodeSnapshot
$page = $drive->nodes()->children($report->parentId);          // Page<NodeSnapshot>
$drive->nodes()->rename($report->id, 'Q3.pdf', expectedSequence: $report->sequence);

foreach ($page->items as $node) {
    // read $node->id, ->name, ->kind … never new up or mutate a snapshot
}
$next = $drive->nodes()->children($report->parentId, new ListOptions(cursor: $page->nextCursor));
```

- Pass `$node->id` (a string) or the snapshot; compare ids as strings.
- Keep the cursor opaque: hand `nextCursor` back unchanged.
- Handle unknown enum values and a `null` `document` head of a node whose type is unavailable.

## Database compatibility

Vaulter is **Postgres-first, portable to MySQL, and runs on SQLite**. The schema is one
shared `v_*` set of tables (see [Storage Panels](/concepts/drives-and-profiles)); nothing about it
is engine-locked, but a few features light up only on a real server. This page states what
each engine gives you and the handful of Postgres specifics the schema depends on.

### Canonical schema and upgrade

Fresh migrations create `v_drives`, `v_file_revisions`, `v_file_revision_blobs`
and every other core table regardless of enabled modules/features. The global
prefix is supported; per-table overrides are historical upgrade inputs only.
PostgreSQL 0.2 bigint and ULID dumps upgrade to the same schema as a fresh install,
including index names, partial predicates and foreign keys. Preflight refuses
unsafe soft-deleted rows, missing document revisions and own-tables deployments.
Back up and stop writes before changing code/schema. See
[the upgrade guide](https://github.com/axiomasoft/vaulter/blob/main/UPGRADE.md).

### Aggregate head integrity

A file's head (`v_files.current_revision_id`) and a document's head
(`v_documents.current_revision_id`) must name a revision of that same aggregate. The guarantee
differs per engine:

| Engine | Head integrity |
|---|---|
| **PostgreSQL**, **MySQL** | Composite foreign keys `v_files_head_fk` / `v_documents_head_fk`: `(current_revision_id, id) → revisions(id, file_id \| document_id) ON DELETE RESTRICT`. A foreign head and deleting a head are refused by the database. |
| **SQLite** | No head foreign key: SQLite cannot add a constraint to a table created before its revisions table. The Vaulter Actions (and the head-integrity tests) keep the invariant; writes that bypass the Actions are not checked. |

Purge releases the head before the node row is deleted: MySQL checks the RESTRICT key while the
node → aggregate → revision cascade runs, before the aggregate row is gone. Tables and columns
are not a public API — change Vaulter data only through its PHP API.

### Supported engines

| Engine | Status | Use it for |
|---|---|---|
| **PostgreSQL 14+** | **Recommended.** The full test suite runs against Postgres in CI across every id strategy. | Production. Everything below works natively. |
| **SQLite** | Fully supported. The default test lane runs on in-memory SQLite. | Local development, tests, small single-file installs. |
| **MySQL 8+** | Supported. Live siblings are unique by a stored `name_key_hash` (sha2 of the binary `name_key`, NULL when trashed); a functional unique index enforces the single drive root; cascading foreign keys are preserved. | Production where Postgres-only locking and index features are not required. |
| **MariaDB** | Host-key DDL is tested. A complete fresh installation is not guaranteed: the `mariadb` driver still guards live siblings and the single drive root with STORED generated columns over `parent_id`, the pattern that conflicts with cascading foreign keys on MySQL. The complete suite is not part of the required database matrix. | Evaluate against your target MariaDB version before production use. |

::: tip PHP extension
Postgres needs the `pdo_pgsql` extension. It ships with most PHP distributions; on a stock
image install it (e.g. `docker-php-ext-install pdo_pgsql`) before pointing Vaulter at Postgres.
:::

### Why Postgres is the recommended lane

A few parts of Vaulter are a **no-op on SQLite** and only do their real work on a server —
they are exercised on Postgres in CI so they cannot regress:

- **Content-addressed blob safety.** The race-free blob commit and the destructive
  garbage-collector run their critical section under `pg_advisory_xact_lock` + `FOR UPDATE`,
  and the GC sweep runs `SERIALIZABLE` with a retry on serialization failure. On SQLite these
  collapse to the single-writer guarantee. See [Storage & Blobs](/concepts/storage-and-blobs).
- **`jsonb` containment indexes.** The activity feed's `context` and the document
  version `content` are `jsonb` on Postgres with `gin (… jsonb_path_ops)` indexes for
  containment queries; other engines store plain `json`/text and skip the gin index.
- **Binary document state.** The protocol-specific checkpoint (`collab_state`) is a binary column. Vaulter
  stores it so it round-trips losslessly on every engine, including Postgres `bytea` (which
  otherwise truncates binary at the first NUL byte through a plain string bind). The collaboration update log
  (`v_collaboration_updates.payload`) is stored the same way.

None of this needs configuration — point Vaulter at Postgres and it applies automatically.


Stored and pending collaboration bindings are whole, nonblank groups with positive integer schema versions. Null checkpoints retain their binding. SQLite CHECK/rollback is covered locally; PostgreSQL/MySQL migration/backfill enforcement and races remain pending owner P8.2 qualification.

### Id strategy × engine

The primary-key strategy is configurable (see
[Configuration → id strategy](/guides/configuration)). All three work on every engine:

| Strategy | Column | Notes |
|---|---|---|
| `ulid` (default) | `char(26)` | Lexicographically sortable; lines up with the Corex/Azgard morph keys. |
| `bigint` | `bigserial` / auto-increment | Classic integer keys. |
| `uuid7` | native `uuid` on Postgres | Time-ordered UUIDs; uses Postgres' native `uuid` type. |

The recursive folder tree (`v_nodes.parent_id → v_nodes.id`) is a self-referencing foreign
key and migrates under all three strategies. PostgreSQL and SQLite use partial unique
indexes for live sibling names and the single root. MySQL 8 expresses the root predicate as
a functional unique index, so `ON DELETE CASCADE` remains valid without stored generated
columns based on foreign-key fields.

### Name uniqueness does not depend on collation

Sibling names are compared through `v_nodes.name_key` = NFC(full case folding(NFC(name))),
computed by PHP and compared byte for byte: `COLLATE "C"` in the PostgreSQL index, SQLite's
default `BINARY`, `utf8mb4_bin` plus a `sha2(name_key, 256)` generated column on MySQL (a
varchar(1024) key exceeds the 3072-byte index limit). The fold follows the Unicode version
of the running PHP mbstring/intl; a change of that version needs a recompute migration.

### Case-insensitive search

Node- and document-name search is **case-folded** (`lower(col) like lower(?)`), so a query
matches regardless of case on every engine — including Postgres, whose `like` is
case-sensitive (unlike SQLite/MySQL). No configuration needed.

### Host keys and Spatie role grants

Host-owned owner, subject, author, tenant and actor columns use `ids.host_keys` (AD25),
independently of Vaulter's `ulid`, `uuid7` or `bigint` entity IDs. The default `string`
is `varchar(64)` and preserves mixed UUID, ULID and integer host keys as strings.
For narrower types and engine mappings, see [host-key configuration](/guides/configuration#host-keys-ids-host-keys).

The upgrade migration changes all 27 host-key columns through the configured Vaulter
connection and legacy table-name map. PostgreSQL uses `ALTER COLUMN ... TYPE ... USING`;
MySQL/MariaDB uses `MODIFY` and keeps each column's nullability and indexes.
Fresh schemas already have the target type; repeated upgrades do no work. SQLite's
upgrade is a no-op because its columns are dynamically typed. Incompatible values cause
a driver error rather than being discarded. Rollback restores the configured Vaulter
ID strategy and can also fail when newly stored host keys no longer fit it.

Vaulter also ships an internal read-only `KeyColumnInspector`. It checks all 27 host-key
columns plus the REF and fixed-length string columns introduced or renamed by the 0.3
schema against `ids.host_keys` and `ids.strategy`. It reports missing columns and type or
length mismatches without changing the database; the doctor command uses this inventory
to diagnose drift.

If you use the optional [`spatie` permission driver](/extending/permission-drivers): a node grant
addressed to a spatie **role** stores that role's **primary key** in `v_node_grants.subject_id`
— the same typed handle every subject uses (a user's key, a team's key). So role grants fit the
typed `subject_id` column and work on **every database and every id strategy**, exactly like
user and permission grants. Address a role grant by the role's key (`$role->getKey()`), not its
name.

### Dedicated database connection

By default every Vaulter table and model reads/writes through the host application's default
Laravel database connection. Set `config('vaulter.database.connection')` (or `VAULTER_DB_CONNECTION`) to a
named connection from `config('database.connections')` to route the entire `v_*` schema — every
migration creates and every shipped model's `getConnectionName()` — onto that
connection instead, e.g. to shard Vaulter onto its own database or isolate a heavy CAS/activity
workload. Leaving the key `null` (the default) keeps the current behaviour exactly as it is today.

`Vaulter\Database\VaulterDatabase` routes transactions, tree and CAS locks, raw queries,
and after-commit callbacks through that same connection. Nested transactions use Laravel
savepoints; serialization failures and deadlocks use Laravel transaction retries (three
attempts by default). `lockTree()` and `advisoryLock()` require an open transaction on the
Vaulter connection. Resolve the singleton from the container when extending package behavior:

```php
use Vaulter\Database\VaulterDatabase;

$database = app(VaulterDatabase::class);
$storageId = 123;
$database->transaction(function () use ($database, $storageId): void {
    $database->lockTree($storageId);
    // Read and mutate Vaulter rows here.
    $database->afterCommit(fn () => dispatch(new RefreshStorageIndex($storageId)));
});
```



### Stored model types

A Vaulter model whose type is stored in a polymorphic column — today the target of an activity row — is
stored under a constant alias, not under its class name: `vaulter.node` for a node and `vaulter.drive`
for a drive. The alias does not change when a class moves between packages and a morph map of your
application does not rename it. `vaulter-drive` merges the two aliases into Laravel's morph map when it boots
(after your own map, never replacing an entry of it, and without calling `enforceMorphMap()`), so a host that
enforces its map needs no entry for them (a subclass you configure in `vaulter-drive.models.*` inherits the alias).
Your own models (a drive owner, a grant subject, a comment
author) stay under your morph policy.

### Schema change policy

Vaulter is pre-`v0.1.0` today, so every table is create-only or empty for every consumer — a
breaking `ALTER` costs nothing right now. That stops being true the moment the package tags its
first release, so future schema changes to the shipped `v_*` tables follow these rules:

- **New columns are always nullable or carry a `default`.** A migration that adds a column to an
  existing table must not require a backfill before existing rows become valid again.
- **Destructive operations (`drop column`, `drop table`, a `NOT NULL` added to a column that isn't
  brand new) only ship behind an explicitly named migration and a `CHANGELOG.md` entry** calling
  out the removal/tightening — never silently folded into an unrelated feature migration.
- **`NOT NULL` on a column of a non-empty table is a two-step change**: ship a migration that
  backfills the column, release it, THEN ship the constraining migration in a later release — never
  both in the same migration.
- **No blocking `ALTER TABLE` against a populated production table without a documented
  maintenance window.** If a change cannot avoid taking a table-level lock (adding a column with a
  volatile default, rewriting a column type), the release notes must say so explicitly.

This policy governs the six entity migrations Vaulter ships (`packages/core/database/
migrations`); it does not (yet) have runtime enforcement — it is a contributor/reviewer checklist,
not a lint rule.

### Running your own tests on Postgres

The package's test suite defaults to in-memory SQLite. To exercise it against Postgres (the
way CI does), point the test connection at a server with the standard `DB_*` variables:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret \
vendor/bin/pest
```

Each `TestCase` migrates its own schema on start, so run one schema group per invocation
(the CI `test-pgsql` job splits them into per-schema steps for exactly this reason).

## Public contract catalog

> PHP source inputs refreshed by P6.3 I13 closure; preliminary until P6.2 (BC gate). No row is a passed BC check; "not_checked" means no machine snapshot exists and none is invented.

| Surface | Machine source | 1.x promise | Detection | Qualification |
|:--|:--|:--|:--|:--|
| PHP API (`#[Api]`) | `packages/<p>/tests/Arch/__snapshots__/surface.{txt,json}` | no removal or narrowing of types, methods, parameters (parameter names are API: named arguments), constants, enum cases | machine drift | conservative BREAK-REVIEW guard (P6.2) |
| PHP SPI (`#[Spi]`) | same snapshots (`surface.json` is created by P6.2; today `surface.txt`) | no new required members on an implement-SPI; extend via a new interface or explicit decision | machine drift | owner qualification |
| Internal | `#[Internal]` / Internal namespace | none | attribute scan | n/a |
| REST (public) | `openapi/vaulter-v1.yaml` | oasdiff breaking = ERR is forbidden; includes confidential cursors and bounded grants/shares pages (paging to `next_cursor=null`, empty page with continuation) | machine drift (P6.2) | consumer-runtime |
| REST (private hub) | `packages/collaboration/openapi/hub.yaml`; protocol claim `protocol` | version via claim; private, no 1.x promise to hosts | machine drift (P6.2) | owner qualification |
| Problem codes | `vaulter:errors`, `x-vaulter-problems` | codes not removed, status unchanged | command output | manual / not_checked until P6.3 |
| Events | event catalog; no machine payload schema yet | fields not removed; new fields optional; envelope versioned | not_checked (no machine snapshot) | manual; no schema path invented |
| Config / env / publish tags | `tests/Repo/Arch/Fixtures/identities/*` | keys not removed without an alias cycle | identities-config drift | owner qualification |
| Container bindings / tags | documented provider `register()` bindings/tags plus an explicit container-contract fixture; no universal bindings snapshot (`identities-bindings.json` does not exist) | documented ids and tags stay resolvable | manual / not_checked | owner: `packages/core`, line 1.x; P6.3 |
| Commands | identities-cli | names, options, exit codes | names/options drift; exit codes not_checked | manual |
| DB schema | `packages/core/tests/Fixtures/Schema/expected/**` | additive migrations only in 1.x; upgrade fixtures from 1.0 | fixture drift | owner qualification |
| Operation catalog | `vaulter:operations`, `tests/Repo/Arch/MutationContractCoverageTest.php` | operation names stable; no second manual catalog | machine drift | consumer-runtime |
| Document type keys, viewer, protocols | identities-protocol / ledger | stable keys | machine drift | owner qualification |
| Storage key/layout and integrity versions | manual / not_checked (no machine snapshot) | layout and integrity version not changed in 1.x without migration | not_checked | owner: `packages/media` (CAS), line 1.x |
| Queued job / receipt serialization, retry/drain | manual / not_checked | serialized jobs and receipts from 1.x readable by later 1.x; retry/drain semantics unchanged. A passing signature check does not qualify rolling workers: mixed versions need a drain-old-workers policy or an owner tuple test | not_checked | owner: `packages/drive`/`core`, line 1.x; no BC pass claimed |
| Persisted document schema, protocol wire versions | manual / not_checked | versions only grow compatibly | not_checked | owner: `packages/documents`/`collaboration`, line 1.x |
| Hub/image environment variables | `packages/collaboration/hub` source/config and private docs; manual / not_checked, Docker runtime deferred to P8.2 | names not removed without alias | not_checked | owner: `packages/collaboration`, line 1.x |
| npm `@vaulter/vue` | `etc/*.api.{md,json}`, entrypoints, consumer type fixtures | exports, types, props, events; runtime behavior not machine-protected | machine drift for types | consumer-runtime |
| Root-only `composer.json` keys (D8) | root `composer.json` | split packages do not inherit root-only keys; not a package promise | not_checked | manual |

Named arguments are part of the API for `#[Api]` types; unstable constructors are `#[Internal]` (D27, `tests/Repo/Arch/SurfaceRulesTest.php`). Attributes do not guarantee behavior.

### Deprecation policy (1.x)

Deprecated items live at least one minor cycle, warn once per process (`E_USER_DEPRECATED` or log, never in the hot path), name the replacement and removal version; a config alias reads the old value and doctor reports the migration. Removal happens only in 2.0, except security exceptions with an advisory. See ADR-0037.
