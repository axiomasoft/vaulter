# ADR-0003 — Embeddability & data model

**Status:** superseded — its embeddability mechanics evolve into Storage Panels
(ADR-0009) and its dual key type into the id-strategy seam (ADR-0011).

## Context

Vaulter targets both plain Laravel apps (auto-increment keys, single tenant) and multi-tenant
CoreX SaaS (ULID primary keys, `tenant_id`, global tenant scope). A single hard-coded schema
cannot serve both.

## Decision

- **Swappable models.** All four models are resolved through `config('vaulter.models')`.
  Hosts subclass them to add tenant scoping, ULID behaviour, or audit. Package code never
  references a concrete model class directly (always via `Support\Config`).
- **Dual key type.** `config('vaulter.id_type')` = `id` | `ulid`. The published migrations
  (via `Support\VaulterSchema`) emit either auto-increment + `morphs()` + `foreignId`, or
  `ulid` + `ulidMorphs()` + `foreignUlid`. The default models switch key handling through the
  `HasVaulterKeys` concern.
- **Optional tenant column.** `config('vaulter.tenant')` adds a `tenant_id` column; Vaulter
  does **not** scope by it — the host's swapped model applies its own `TenantScope`.
- **Polymorphic workspace.** Documents/folders attach to any host entity via
  `workspace_type` / `workspace_id`.

## Consequences

- One package, two deployment shapes, no fork.
- Vaulter stays out of the tenancy-enforcement business (the host owns it), avoiding
  cross-tenant-leak responsibility it cannot guarantee.
- Models are not `final` (they must be extendable); other domain classes remain `final`.

> **Superseded by:** the swappable-model + polymorphic-workspace idea generalises
> into the config-first **Storage Panels** envelope (ADR-0009), and the binary
> `id_type` toggle is replaced by the `bigint | ulid | uuid7` **id-strategy** seam
> (ADR-0011). The host-owned tenancy stance carries forward unchanged.
