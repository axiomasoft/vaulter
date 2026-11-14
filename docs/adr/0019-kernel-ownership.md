# ADR-0019 — Kernel ownership

**Status:** superseded by [ADR-0027](/adr/0027-kernel-boundaries-and-modules)

> Transfers decision D02 and the ownership table §2 of the 2026-09-29 stable audit
> (`audits/2026-09-29-stable-audit/opus/02-decisions.md#d02`,
> `audits/2026-09-29-stable-audit/opus/04-packages-and-layout.md`) into the project ADR set.
> Implementation belongs to later work; this record fixes the rule.

## Context

The package set is right, but its declared dependencies are not honest:

- `vaulter-documents` cannot create a drive without `vaulter-drive` (its tests boot
  `VaulterDriveServiceProvider`), while the README claims the opposite (audit finding N15).
- `vaulter-filament` calls the drive facade but `composer.json` requires only core (N14).
- AzGuard-specific code (`AzGuardGuard`, the AzGuard permission driver, `AzGuardConfig`, `azguard.*`
  keys) lives in core, and the bridge requires `azguard-core: dev-main` (N21).

An installation of "core without drive" does not exist: without `DriveProvisioner` no drive can be
created. There was also no written rule for which package owns schema, models, contracts, HTTP and
config.

## Decision

**1. Package set is kept.** Composer packages stay `vaulter-core`, `vaulter-media`, `vaulter-drive`,
`vaulter-documents`, `vaulter-filament`, `vaulter-azguard`; npm — `@vaulter/vue`; plus
the Node hub (now `@vaulter/collaboration-hub`, [ADR-0031](/adr/0031-collaboration-package-and-durability)). A metapackage **`axioma-studio/vaulter`** (`core + media + drive + documents`)
is added.

**2. Dependencies are honest.** `vaulter-documents` **requires** `vaulter-drive` (N15);
`vaulter-filament` **requires** `vaulter-drive` (N14).

**3. Ownership rule.**

- **core (kernel)** owns the whole database schema and migrations, the shared models (`Drive`,
  `Node`, `File`, `FileRevision`, `Blob`, `NodeGrant`, `NodeShare`, `Activity`, `Outbox`,
  `Webhook*`), access, sharing, audit, events, profiles, the file-handler registry, seam contracts
  and the typed kernel config;
- a **module** (drive, documents) owns its use cases (Operations/Actions/Queries), HTTP, commands,
  module config and the models only it reads and writes (`UploadSession`, `UploadPart`, `Document`,
  `DocumentRevision`, `NodeComment`);
- **media** is the CAS engine (content store, storage driver, GC, reconcile, repair);
- **bridges** (azguard, filament) contain no domain logic.

| Artifact | Owner | Rule |
| --- | --- | --- |
| All migrations and `SchemaBuilder` | core | One migration history; tables are created regardless of installed modules |
| Models `Drive`, `Node`, `File`, `FileRevision`, `FileRevisionBlob`, `Blob`, `NodeGrant`, `NodeShare`, `Activity`, `Outbox`, `WebhookEndpoint`, `WebhookDelivery` | core | Used by kernel services or by several modules |
| `UploadSession`, `UploadPart` | drive | Read and written only by drive |
| `Document`, `DocumentRevision`, `NodeComment` | documents | Read and written only by documents |
| Config | package owning the key | Keys that affect the schema live in `vaulter.php` (core) |
| HTTP routes | module of the operation | Prefix, version and middleware stacks come from core |
| Seam contracts called by core | core | `DriveHandle`, drive-level `*Operations`, `NodeContentLifecycle`, `BlobStore`, `FileHandler` |
| Contracts called only by a module | module | `DocumentType`, `DocumentExporter`, `CollaborationDriver`, `UploadProtocolHandler` |
| Kernel HTTP (grants, shares, activity, public links) | core | Works through `Vaulter::drive()`; core registers these routes only if `DriveHandleFactory` is bound (vaulter-drive installed). Webhook endpoints — always |

**4. AzGuard specifics leave core.** Everything AzGuard-specific (`AzGuardGuard`, the driver, the
permission map, `azguard.*` keys) moves from core to `vaulter-azguard` (N21).

## Consequences

- Composer metadata states the real install graph; a host that installs documents or filament gets
  drive transitively instead of a runtime failure.
- Schema is defined in one place and one migration history, independent of which modules are
  installed.
- Module-private models and contracts stay inside their module; core depends only on seam
  contracts it calls itself.
- Bridges become thin adapters; the AzGuard bridge carries all AzGuard coupling.

## Alternatives rejected

- **Merge drive into core** — a huge namespace move (and a reversal of the earlier core/drive
  split); honest `require` is cheaper.
- **New packages `contracts` / `kernel` / `sharing` / `webhooks` / `collaboration`** — none of them
  has an independent installation.

## References

- `audits/2026-09-29-stable-audit/opus/02-decisions.md#d02`
- `audits/2026-09-29-stable-audit/opus/04-packages-and-layout.md` §2
- `audits/2026-09-29-stable-audit/opus/01-review.md` — N14, N15, N21
- [ADR-0017 — Internal package structure canon](/adr/0017-package-structure-canon)
