# ADR-0003 — Embeddability & data model

**Status:** accepted

## Context

DocVault targets both plain Laravel apps (auto-increment keys, single tenant) and multi-tenant
CoreX SaaS (ULID primary keys, `tenant_id`, global tenant scope). A single hard-coded schema
cannot serve both.

## Decision

- **Swappable models.** All four models are resolved through `config('docvault.models')`.
  Hosts subclass them to add tenant scoping, ULID behaviour, or audit. Package code never
  references a concrete model class directly (always via `Support\Config`).
- **Dual key type.** `config('docvault.id_type')` = `id` | `ulid`. The published migrations
  (via `Support\DocVaultSchema`) emit either auto-increment + `morphs()` + `foreignId`, or
  `ulid` + `ulidMorphs()` + `foreignUlid`. The default models switch key handling through the
  `HasDocVaultKeys` concern.
- **Optional tenant column.** `config('docvault.tenant')` adds a `tenant_id` column; DocVault
  does **not** scope by it — the host's swapped model applies its own `TenantScope`.
- **Polymorphic workspace.** Documents/folders attach to any host entity via
  `workspace_type` / `workspace_id`.

## Consequences

- One package, two deployment shapes, no fork.
- DocVault stays out of the tenancy-enforcement business (the host owns it), avoiding
  cross-tenant-leak responsibility it cannot guarantee.
- Models are not `final` (they must be extendable); other domain classes remain `final`.
