# ADR-0017 — Internal package structure canon (layer-first + domain sub-namespace)

**Status:** accepted

## Context

vaulter is a monorepo of six PHP packages (`core`, `drive`, `media`, `documents`,
`filament`, `permissions-azgard`) over one shared kernel (ADR-0005 / ADR-0015).
Their internal `src/**` layout drifted into three incompatible schemes, which
breaks the layer-mirror navigation the monorepo depends on (VLT-REMED D10, owner
decision 2026-07-10):

1. **`vaulter-core` — layer-first + domain sub-namespace.** Technical layers
   (`Actions/`, `Models/`, `Repositories/`, `Enums/`, `Events/`, `Data/`,
   `Contracts/`, `Http/`, …) sit at the package root, and within each layer
   classes are grouped by a **domain** sub-namespace: `Actions/Sharing`,
   `Models/Drive`, `Enums/Webhooks`, `Http/Controllers/Access`. Cross-cutting
   domain services that do not fit a standard layer live in a top-level
   **domain-service folder** (`Access/`, `Sharing/`, `Webhooks/`, `Cas/`,
   `Activity/`). This is the reference scheme.
2. **`vaulter-drive` — domain-first island.** The `Uploads/` module carries its
   OWN nested layer tree (`Uploads/Actions`, `Uploads/Models`,
   `Uploads/Repositories`, `Uploads/Http`, `Uploads/Enums`, `Uploads/Data`,
   `Uploads/Contracts`) — the inverse of the core scheme (a layer nested UNDER a
   domain).
3. **`vaulter-documents` — flat layers.** Layers sit at the root (layer-first)
   but are **flat**: `Actions/` (9), `Events/` (6), `Data/` (6), `Enums/` (4),
   `Repositories/` (4), `Models/` (2) hold classes with no domain sub-namespace,
   plus a partly domain-first `Documents/` folder.

The full six-package inventory is in
`plans/archive/VLT-REMED/findings/P4.9-structure-inventory.md`.

This ADR **declares the canon**; it moves NO code. Enforcement is by Pest arch
tests added in the same change (VLT-REMED P4.9); the physical namespace refactor
that makes every package conform is a separate, reviewed step (VLT-REMED P4.10,
`php:namespace-refactor-safety`). Declaration and execution are split so the
canon is reviewed BEFORE the irreversible mass-move.

## Decision

**Canon A: layer-first placement + domain sub-namespace within layers.** The
reference is `vaulter-core`. Two structural laws, both machine-checked:

- **Law 1 — layer-first placement.** A recognized technical-layer directory
  (`Actions`, `Models`, `Repositories`, `Enums`, `Events`, `Data`, `Contracts`,
  `Http`) lives as the FIRST namespace segment under the package root
  (`Vaulter\<Pkg>\Actions\…`). A layer directory MUST NOT be nested inside a
  domain module folder (`src/Uploads/Actions` is a violation; `src/Actions/Uploads`
  is canonical). Sub-layers of `Http` (`Http/Controllers`, `Http/Requests`,
  `Http/Resources`, `Http/Middleware`, `Http/Support`) are structural, not
  domains, and are allowed.
- **Law 2 — domain sub-namespace within layers.** Inside a multi-domain layer,
  classes are grouped under a **domain** sub-namespace (`Actions/<Domain>/Class`),
  not placed flat directly in the layer root. A SHORT, explicit allow-list of
  cross-cutting root-citizens may stay flat at a layer root — currently
  `Contracts/IdStrategy` and `Exceptions/VaulterException` (the base exception).

**Allowed outside the two laws:**

- **Package entrypoints at `src/` root:** `*ServiceProvider`, `*Manager`,
  `*Context`, `*Plugin`, the facade seam, and a small models/config helper
  (`VaulterManager`, `DriveManager`, `DriveContext`, `StorageManager`,
  `DocumentsModels`, …).
- **Domain-service folders at `src/` root** for domain services/resolvers that do
  not belong to a standard layer — `core/src/{Access,Sharing,Webhooks,Cas,Activity}`
  is the reference shape. These are domain-named, NOT layer-named, so Law 1 does
  not apply to them.

**Target layers per package (the canonical set):** `Actions/`, `Models/`,
`Repositories/`, `Enums/`, `Events/`, `Data/`, `Contracts/`, `Http/` (with
`Controllers`/`Requests`/`Resources`/`Middleware`/`Support` sub-layers),
`Exceptions/`, `Jobs/`, `Policies/`, `Rules/`, `ValueObjects/`, `Console/`,
`Support/`, plus package-specific structural roots (`Drivers/`, `Schema/`,
`Panels/`, `Attributes/`, `Facades/`, `Testing/` in core).

**Exceptions (explicitly out of Canon A):**

- **`vaulter-filament`** follows the **Filament** plugin convention
  (`src/Resources/<XResource>/Pages/`), which is itself a canon owned by the
  framework. Canon A does NOT apply; P4.10 does not restructure it.
- **`vaulter-media`** and **`vaulter-permissions-azgard`** are minimal (a CAS
  domain-service folder / a guard adapter). They conform to Canon A in its narrow
  form; P4.10 only verifies them, it does not force a layer tree onto packages
  that have none.

**Enforcement (this change, P4.9):**

- `packages/core/tests/Arch/ArchTest.php` — Law 2 is already enforced green by
  the existing `split technical roots keep classes in domain subfolders` test;
  P4.9 adds an executable **Law 1 green-guard** tagged to this ADR.
- `packages/documents/tests/Arch/ArchTest.php` — a **Law 2** canon test, RED on
  the current flat layout, marked `->todo()` ("unblocked by P4.10 / ADR-0017").
- `packages/drive/tests/Arch/ArchTest.php` — a **Law 1** canon test, RED on the
  current `Uploads/*` layout, marked `->todo()` ("unblocked by P4.10 / ADR-0017").

The `->todo()` markers are the executable spec of the P4.10 scope: P4.10 makes
them pass by moving code, then removes the `->todo()`.

## Consequences

- The canon is an **executable specification**, not prose: a regression that
  drops a class back into a flat layer root (Law 2) or nests a layer under a
  domain (Law 1) trips an arch test naming the exact offender.
- `documents` and `drive` carry a KNOWN, encoded structural debt: their canon
  arch tests are red-in-principle (`->todo()`), so the debt is visible in the
  test report and cannot be silently forgotten. P4.10 discharges it.
- **Breaking change on execution (D3 — package unreleased, no BC obligation):**
  P4.10 changes public FQCNs (`Vaulter\Drive\Uploads\Actions\CommitUploadAction`
  → `Vaulter\Drive\Actions\Uploads\CommitUploadAction`, the documents flat
  classes gain a domain segment). Morph aliases, `config/vaulter.php` FQCN
  defaults, and docs references must move with the classes; recorded in
  `UPGRADE.md`. P4.9 itself changes NO code and breaks nothing.
- `filament` staying on the Filament convention is a deliberate, documented
  exception, not drift — future audits should not flag it against Canon A.
