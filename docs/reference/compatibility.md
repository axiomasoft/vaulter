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
integer 1..300, default 60). It bounds one completion request, DB retries included; when it runs
out the completion is refused with `upload_conflict` (409), nothing is published and the staged bytes stay
for a repeat.

The budget is enforceable only on a local staging disk. Resumable completion (`tus`, `s3-multipart`; the names are protocols, not storage capabilities) on a remote, S3 or opaque custom disk is **unsupported**: it is refused with `upload_conflict` (409) before any storage I/O. Upgrade note: stage resumable uploads on a local disk; single-shot `direct` upload and the content-addressed store on S3 are unaffected. A session already committed before this change may still run its pending staged cleanup on the staged disk without a hard deadline (known limitation).

### Current PHP surface

The PHP API/SPI catalog follows the current source: the `scan-i13` boundary scan runs in block mode with no temporary exceptions and no permanent host-input entries. Readonly results include `UploadSessionSnapshot`, change pages and nested snapshot facts; events carry snapshots and scalar facts instead of domain ORM models.

`NodeSnapshotContributor` remains a supported implement-SPI: `contribute()` receives model-free `list<NodeSnapshot>` before fragments, viewer state or reader keys are added. Load owned aggregates by node id. `HasDrives` remains an available trait, with an internal ORM `drives()` relation; supported listing uses `Vaulter::drives($actor)` returning `Page<DriveSnapshot>`. ORM configuration getters and native factories are internal; configuration keys remain valid.

## Packages and versions

Every PHP package is released with one version and one tag, and each split repository
(`axiomasoft/vaulter-core`, `-media`, `-drive`, `-documents`, `-webhooks`, `-filament`, plus the
`axiomasoft/vaulter` metapackage) receives the same tag. Packages require each other as
`self.version`, so mixing versions is not possible. `axiomasoft/vaulter-azguard` is released
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

Install 1.0 on an empty database. Each offered module installs its tables even when
disabled at runtime: one create migration per table, with a rollback that drops it.
`vaulter.database.table_prefix` applies to every table; per-table overrides are unsupported.

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
| **PostgreSQL 18** | **Recommended.** CI qualifies this version, across every id strategy. | Production. Everything below works natively. |
| **SQLite** | Fully supported. The default test lane runs on in-memory SQLite. | Local development, tests, small single-file installs. |
| **MySQL 9.7 LTS** | Supported. CI qualifies this version. Live siblings are unique by a stored `name_key_hash` (sha2 of the binary `name_key`, NULL when trashed); a functional unique index enforces the single drive root; cascading foreign keys are preserved. | Production where Postgres-only locking and index features are not required. |
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
  otherwise truncates binary at the first NUL byte through a plain string bind).

None of this needs configuration — point Vaulter at Postgres and it applies automatically.


The fresh schema uses CHECK constraints to keep stored and pending collaboration bindings
complete and nonblank, with positive integer schema versions. A bound document may have
a null checkpoint.

### Id strategy × engine

The primary-key strategy is configurable (see
[Configuration → id strategy](/guides/configuration)). All three work on every engine:

| Strategy | Column | Notes |
|---|---|---|
| `ulid` (default) | `char(26)` | Lexicographically sortable; lines up with the AzGuard morph keys. |
| `bigint` | `bigserial` / auto-increment | Classic integer keys. |
| `uuid7` | native `uuid` on Postgres | Time-ordered UUIDs; uses Postgres' native `uuid` type. |

With `bigint`, built-in Vaulter model primary keys are PHP integers when they fit PHP's integer range.
MySQL unsigned values above `PHP_INT_MAX` remain exact decimal strings. Snapshot and REST
resource identifiers are strings, preserving values above JavaScript's safe integer range.

The recursive folder tree (`v_nodes.parent_id → v_nodes.id`) is a self-referencing foreign
key and migrates under all three strategies. PostgreSQL and SQLite use partial unique
indexes for live sibling names and the single root. MySQL expresses the root predicate as
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

Fresh migrations create host-key columns with the configured type. Set `ids.host_keys`
before installing; changing the configuration does not rewrite existing columns.

The internal read-only `KeyColumnInspector` checks the host-key, REF and fixed-length
string columns declared by the offered modules against `ids.host_keys` and `ids.strategy`.
It reports missing columns and type or length mismatches without changing the database;
the doctor command uses this inventory to diagnose drift.

If you use the optional [`spatie` permission driver](/extending/permission-drivers): a node grant
addressed to a Spatie **role** stores that role's **primary key** in `v_node_grants.subject_id`.
Its storage type follows `ids.host_keys`, independently of `ids.strategy`, just like user
and team subjects. Choose a host-key type that holds the role's key, and address the grant
by `$role->getKey()`.

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

> Structural adapters and CI wiring are implemented. No row is a runtime qualification; "not_checked" means no machine snapshot exists and none is invented.

| Surface | Machine source | 1.x promise | Detection | Qualification |
|:--|:--|:--|:--|:--|
| PHP API (`#[Api]`) | `packages/<p>/tests/Arch/__snapshots__/surface.{txt,json}` | no removal or narrowing of types, methods, parameters (parameter names are API: named arguments), constants, enum cases | machine drift | conservative BREAK-REVIEW guard |
| PHP SPI (`#[Spi]`) | same snapshots (`surface.json` and readable `surface.txt`) | no new required members on an implement-SPI; extend via a new interface or explicit decision | machine drift | owner qualification |
| Internal | `#[Internal]` / Internal namespace | none | attribute scan | n/a |
| REST (public) | `openapi/vaulter-v1.yaml` | oasdiff breaking = ERR is forbidden; includes confidential cursors and bounded grants/shares pages (paging to `next_cursor=null`, empty page with continuation) | machine drift | consumer-runtime |
| Problem codes | `vaulter:errors`, `x-vaulter-problems` | codes not removed, status unchanged | command output | manual / not_checked |
| Events | event catalog; no machine payload schema yet | fields not removed; new fields optional; envelope versioned | not_checked (no machine snapshot) | manual; no schema path invented |
| Config / env / publish tags | `tests/Repo/Arch/Fixtures/identities/*` | keys not removed without an alias cycle | identities-config drift | owner qualification |
| Container bindings / tags | documented provider `register()` bindings/tags plus an explicit container-contract fixture; no universal bindings snapshot (`identities-bindings.json` does not exist) | documented ids and tags stay resolvable | manual / not_checked | owner: `packages/core`, line 1.x |
| Commands | identities-cli | names, options, exit codes | names/options drift; exit codes not_checked | manual |
| DB schema | `packages/core/tests/Fixtures/Schema/expected/**` | additive migrations only in 1.x; upgrade fixtures from 1.0 | fixture drift | owner qualification |
| Operation catalog | `vaulter:operations`, `tests/Repo/Arch/MutationContractCoverageTest.php` | operation names stable; no second manual catalog | machine drift | consumer-runtime |
| Document type keys, viewer, protocols | identities-protocol / ledger | stable keys | machine drift | owner qualification |
| Storage key/layout and integrity versions | manual / not_checked (no machine snapshot) | layout and integrity version not changed in 1.x without migration | not_checked | owner: `packages/media` (CAS), line 1.x |
| Queued job / receipt serialization, retry/drain | manual / not_checked | serialized jobs and receipts from 1.x readable by later 1.x; retry/drain semantics unchanged. A passing signature check does not qualify rolling workers: mixed versions need a drain-old-workers policy or an owner tuple test | not_checked | owner: `packages/drive`/`core`, line 1.x; no BC pass claimed |
| Persisted document schema, protocol wire versions | manual / not_checked | versions only grow compatibly | not_checked | owner: `packages/documents`, line 1.x |
| npm `@vaulter/vue` | each package’s `etc/index.api.md`, `etc/components.surface.txt`, public entrypoints and consumer type fixtures | removed exports are BREAK; changed declarations, required props or emits are REVIEW | `api:surface:check` checks declaration freshness in each Vue CI job; the trusted `npm-surface` coordinator compares with the independent release-line predecessor | declaration guard only; packed/runtime qualification is owner work |
| Root-only `composer.json` keys | root `composer.json` | split packages do not inherit root-only keys; not a package promise | not_checked | manual |

Named arguments are part of the API for `#[Api]` types; unstable constructors are `#[Internal]` (`tests/Repo/Arch/SurfaceRulesTest.php`). Attributes do not guarantee behavior.

### Deprecation policy (1.x)

Deprecated items live at least one minor cycle, warn once per process (`E_USER_DEPRECATED` or log, never in the hot path), name the replacement and removal version; a config alias reads the old value and doctor reports the migration. Removal happens only in 2.0, except security exceptions with an advisory. See ADR-0037.


### BC gate: source, freeze and trusted tool identities

| Identity | Authority and purpose | Boundary |
|:--|:--|:--|
| S | Committed candidate source SHA; `--root` supplies bytes and static snapshots only | Candidate autoload, PHP, hooks, plugins and tests supply no approval authority |
| F | Freeze metadata commit, sole parent S; only the release manifest differs | F is not a replacement release source or a rebuild |
| T | Externally approved policy and complete tool revision, read-only after provisioning | Parser, checker, helpers, locks and ignored vendor/autoload inputs belong to T |
| A | Protected external exact approval bundle for S, policy, exceptions and tool closure | Identical surface hashes do not transfer approval to another S |

`contract-bc` participates in CI lane selection, `needs` and the final `CI gate` verdict.
A required failed, cancelled, missing or skipped job blocks the gate. The lane is required
only on a repository whose owner provisioned the trusted host: when neither
`BC_HOST_BOOTSTRAP` nor `BC_HOST_PYTHON` is set as a repository variable, the plan reports
`contract_bc_provisioned=false`, the job is not run, and `CI gate` accepts that skip with a
notice. Such a run makes no BC claim. Once either variable is set the lane runs and
blocks, and an incomplete setup fails it. The standalone
OpenAPI workflow calls the same externally provisioned host bootstrap. Both public
`openapi/vaulter-v1.yaml` uses the resolved public PHP release base. Local referenced files and
JSON-pointer fragments must resolve on both sides, including shared schemas. Remote
refs, missing inputs and an unavailable oasdiff are `not_checked` / exit 2 in CI.
The transitional tested pin is `tufin/oasdiff:v1.33.0`, in the trusted wrapper's
`Contract/openapi-check.py`; there is no pins manifest yet.
Synthetic oasdiff records argv and exit propagation only; it proves no REST compatibility.

Owner host provisioning is required before this lane can pass: provision an externally
managed absolute Python and approved external copy of `Contract/trusted-host.py`, then
supply `BC_HOST_PYTHON`, `BC_HOST_BOOTSTRAP`, `BC_TOOL_ROOT`, `BC_TOOL_REV`,
`BC_SHA256SUMS`, `BC_RUNTIME_APPROVAL`, and `BC_RUNTIME_APPROVAL_SHA256` through the
protected control plane. Never obtain approval from candidate files. No such external
provisioning or branch-protection enforcement is claimed by these repository fixtures.
Partial provisioning blocks, rather than qualifying a release.

The bootstrap is invoked with isolated Python (`-I`) outside the candidate cwd. Its
external SHA256SUMS must enumerate exactly every regular file in T, including ignored
dependencies/autoload; only root `.git` metadata is excluded. Normalized relative paths,
unique entries, no symlinks, no missing/extra/changed files, and HEAD=T are mandatory.
The checksum inventory and runtime approval live outside T. The runtime JSON contains
`php` (absolute binary), `sha256`, exact `configuration` (`version`, `ini`, `scanned`,
`extensions`), `config_files` (absolute config paths and hashes), and exact `environment`
(including absolute `BC_PYTHON` for the wrapper). PHP 8.4 and matching configuration,
extensions, environment and bytes are required. PHP children use that approved binary
(`PHP_BINARY` in PHP fixtures); the host never invokes `scripts/php84`, which writes a
shim inside its checkout. Scratch files live outside T. Git metadata integrity, protected
mounts, the external bootstrap and runtime approval remain owner prerequisites.

The trusted default policy `Contract/bc-trust-policy.json` requires four independent
release lines and contains no approvals:

| Required line | Tag family | Pinned major |
|:--|:--|:--|
| `php-public` | `v<version>` | 1 |
| `npm-vue` | `@vaulter/vue@<version>` | 1 |

The npm majors follow the actual independent package versions (currently 1.0.0 and
0.0.0); the release manifest’s `api: 1` does not make the private package version 1.x.
Advancing a major requires an explicit trusted policy update. Tag prefixes and channels
come from `release/packages.json`; an npm tag never selects a PHP or neighboring npm base.
The host requires all four policy and report lines, even when a selector requests only PHP.
`BC_SELECTED_LINES=all` selects the complete trusted set; a reduced `BC_REQUIRED_LINES`
or selection, missing line, skipped/deferred line, or incoherent process status blocks.
The aggregate preserves any coordinator BREAK/REVIEW or tooling error even if OpenAPI
returns success. CI’s planned required/selected line outputs and actual job dependencies
participate in the final gate; a Vue-only change still requires this four-line coordinator.

Both Vue jobs execute `api:surface:check` as a blocking step in an unprivileged job,
without secrets or persisted checkout credentials. This regenerates and checks the
committed declaration reports against source; it does not approve a report change.
The trusted coordinator separately compares the base/candidate reports with `npm-surface`
and applies the exact exception policy. The public `api:check` continues to check generated
OpenAPI types and is not a replacement for either declaration check.

A known missing release is a tooling error. No predecessor is
`initial_bootstrap`, never a self-baseline BC pass; only an external exact approval (A)
can admit an initial release. Reports distinguish changes, errors, selected bases,
policy/source SHAs, `not_checked`, and pending qualification. Structural signatures,
identity/schema and npm declaration guards do not qualify runtime semantics, serial
workers, actual tgz/ZIP consumers, Docker, installs or upgrades. Those remain pending
owner runs; actual runtime qualification is false in synthetic reports.
