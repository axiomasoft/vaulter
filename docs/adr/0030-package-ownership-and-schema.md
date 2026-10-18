# ADR-0030 — Package ownership of schema, models and configuration

**Status:** accepted — builds on [ADR-0027](/adr/0027-kernel-boundaries-and-modules); completes the ownership move that
replaced [ADR-0019](/adr/0019-kernel-ownership)

## Context

[ADR-0027](/adr/0027-kernel-boundaries-and-modules) decided what the kernel is, but until the packages were split
`vaulter-core` still *held* every table, model, route, command and config key of the product, and the modules only
declared the slices they owned. A package could not be installed, tested or released without the monorepo around it
(`../core/tests` was autoloaded by every package), a blob could be deleted by a garbage collector that read the
drive's tables, and the webhooks tables, routes and commands could not be left out of an installation. Nothing was
installed anywhere yet (1.0 is the starting point: no 0.x upgrade path), so the split could change identities
directly, without shims.

## Decision

**1. One owner per table, model and key.** A table belongs to exactly one package; only that package creates it,
writes to it and reads it through its own models. A foreign key points from the package that depends to the package it
depends on, never back. The dependency graph is the Composer graph:

```text
core  <-  media  <-  drive  <-  documents  <-  collaboration (ADR-0031)
                      ^   ^   ^
          webhooks  azgard  filament
```

| Package | Namespace | Owns |
|---|---|---|
| `vaulter-core` | `Vaulter\` | `v_outbox`, `v_outbox_consumers`, `v_outbox_deliveries`, `v_idempotency_keys`; the kernel only |
| `vaulter-media` | `Vaulter\Media\` | `v_blobs`, `v_blob_references` (the pins), the CAS contracts, GC and reconcile |
| `vaulter-drive` | `Vaulter\Drive\` | drives, nodes, files, revisions, scan state, grants, shares, uploads, activity, change journal, quotas, profiles, the entry point `Vaulter\Drive\Vaulter` and its facade |
| `vaulter-documents` | `Vaulter\Documents\` | documents, document revisions, comments, exporters |
| `vaulter-webhooks` | `Vaulter\Webhooks\` | `v_webhook_endpoints`, `v_webhook_deliveries`, the fan-out consumer, signing, URL guard, retries |

`vaulter-azgard` and `vaulter-filament` own no tables. The metapackage `axioma-studio/vaulter` is core, media, drive
and documents; webhooks is installed separately because it opens outbound network traffic (opt-in, ADR-0027).

**2. Schema lives with its owner, in create migrations.** Each package ships `database/migrations/` with one create
migration per table (or table and its side tables) and runs them from `vendor/`. They are **not published**: there is
no publish tag and no copy to drift. File names carry a block of numbers per package, so that sorting by name equals
the dependency graph and no migration coordinator is needed:

| Block | Package |
|---|---|
| `0001_01_01_0000NN` | core |
| `0001_01_01_0001NN` | media |
| `0001_01_01_0002NN` | drive |
| `0001_01_01_0003NN` | documents |
| `0001_01_01_0004NN` | webhooks |
| `0001_01_01_0005NN` | collaboration ([ADR-0031](/adr/0031-collaboration-package-and-durability)) |

The definitions are schema classes of the owner (`MediaSchema`, `DriveSchema`, `DocumentsSchema`, `WebhooksSchema`);
core keeps only generic helpers (`Vaulter\Schema\*`: id strategies, host key columns, `SchemaHelpers`, the kernel's own
tables) and no domain DDL.
Every manifest implements `OwnsSchema`, and the core service `ModuleSchemas` walks the compiled modules in
dependency order (create) and in reverse (drop). Until 1.0 a schema improvement edits the create migration directly;
after 1.0 only new migrations in the owning package (expand/contract).

**3. Blob liveness belongs to media (D23).** `v_blob_references` holds *pins*: `blob_id` with a foreign key to
`v_blobs`, an opaque `owner_type` (`<module>.<what>`), a `reference_key` derived from the owner's own row, unique on
`(blob_id, owner_type, reference_key)`. The drive keeps its revision-to-blob links in its own table and, through the
public `Vaulter\Media\Contracts\Cas\BlobPins` contract, pins and unpins in the same transaction as the link, under the
per-(scope, sha256) lock. Garbage collection and `ref_count` reconciliation read only media tables; `ref_count` is a
cache, the pins are the truth. A pin to a tombstoned blob is accepted only while its bytes still exist; a pin to a
deleting or deleted blob never. Pins survive the drive being switched off or removed from Composer. Only the owner's
own purge or rollback releases its pins (`releaseOwner`); a generic command never touches another module's pins.
`v_blobs.drive_id` is replaced by the neutral `storage_id`, so media has no reverse edge to the drive. The scan
tables belong to the drive (D28): scanner evidence has no foreign key to media.

**4. Configuration is one root per package.** `vaulter` (kernel: database, ids, tenancy, modules, routes, outbox,
idempotency, schedule, observability, outbox model), `vaulter-media`, `vaulter-drive` (profiles, features, access,
sharing, downloads, scan, uploads, trash, changes, handlers, drive models), `vaulter-documents`, `vaulter-webhooks`,
`vaulter-azgard`, `vaulter-filament`. A key moved with its concept without an alias: `vaulter.access.*` is
`vaulter-drive.access.*`, `vaulter.webhooks.*` is `vaulter-webhooks.*`, `vaulter.models.blob` is
`vaulter-media.models.blob`. Typed readers sit with the keys they read (`Vaulter\Drive\Configuration\DriveConfig`,
`Vaulter\Media\Configuration\MediaConfig`, `Vaulter\Webhooks\Configuration\WebhooksConfig`); the kernel validates
only its own root. Each package publishes its config under its own tag (`vaulter-config`, `vaulter-drive-config`,
`vaulter-webhooks-config`, …).

**5. The kernel knows no route, command or label of a module.** A module contributes its routes with the
`vaulter.module:<id>` middleware, its commands, its doctor checks and its metric labels (the `RouteLabelSource` slot,
which the drive and documents implement) through the manifest. The entry point and facade are the drive's:
`Vaulter\Drive\Vaulter`, `Vaulter\Drive\Facades\Vaulter`, with the host alias `Vaulter` declared by `vaulter-drive`.
The old FQCNs are removed without `class_alias`.
Event types are enums of their module (`DriveEventType`, `DocumentEventType`) registered with the kernel catalogue; the
string values did not change. Models of Vaulter that appear in a polymorphic column report a stable alias
(`vaulter.node`, `vaulter.drive`, D34) instead of their class name, so a later class move does not change stored data.

**6. Order of the move (D40).** Tooling and test kits first, then the DDL of the owners, then all create migrations at
once (so the name order always equals the graph), then the code: media, pins, drive, webhooks, explicit `require`
entries, Filament, core clean-up. Every step left the packages green; the invariants I1, I2, I3, I5 and I9 became
blocking when their allowlists were empty (the named collaboration remainder of D32 leaves with the collaboration
package).

**7. Each package tests itself (D14).** Every package has its own `TestCase` on the public `Vaulter\Testing` kit of
core plus the kit of the packages it depends on (`Vaulter\Drive\Testing`, `Vaulter\Documents\Testing`,
`Vaulter\Webhooks\Testing`, …); no package reaches `../core/tests`. The clean-consumer harness
`scripts/release/consumer-check.sh <package>` builds one package in an empty directory with only its declared
dependencies and runs its fast set; the blocking CI job `consumer-isolation` (core, media, drive, documents, webhooks)
runs it. Qualifying the built archives is a release step, not a per-change one.

**8. Removal is the owner's job.** `php artisan vaulter:module:uninstall <module>` is a dry run by default (migrations,
tables, rows, dependent modules, foreign keys others hold on its tables, pins). `--force` rolls the owner's migrations
back, refused while its tables hold rows or while it keeps pins; `--purge` (with `--force`) deletes its rows and releases
its *own* pins first. It never drops or deletes anything of another owner. On sqlite and PostgreSQL the purge and the
rollbacks are one transaction; where DDL commits (MySQL) a failure after the purge exits `3` (applied partially,
repeat `--force`). Exit codes: `0` planned or done, `1` refused or failed with nothing changed, `2` usage, `3` partial.

## Consequences

- A host installs the packages it needs; a table of a package that is absent is never created, and a package
  switched off keeps its data. Rolling back a downward-closed set of packages leaves no foreign key or pin behind (the
  clean-install matrix proves it on sqlite; the server engines are qualified separately).
- Moving a type changes its FQCN. Documentation and tests change in the same release; there is no compatibility alias.
  Every moved identity (class, config key, command, route source, migration name, morph alias) is recorded with its
  reason in the identity ledger (`tests/Repo/Arch/Fixtures/identities/changes.json`), which CI checks against the
  scanned code.
- The drive and webhooks packages are independently releasable (`release/packages.json`, one split repository
  each). Collaboration, when extracted, takes the next free block (`0005NN`) and the same rules.
- Core is small enough to be audited as a kernel: it contains the module system, operations, the outbox with its
  relay, idempotency, errors, identity, tenancy, the HTTP/config/doctor frameworks, telemetry and the testing kit.
