# ADR-0009 — Storage Panels

**Status:** accepted

> Amended by ADR-0021 (proposed).

## Context

ADR-0003 made Vaulter embeddable through swappable models, a dual key type and an
optional tenant column. Re-platforming raised a sharper requirement: mount a
storage on **any** host entity (project, task, organization, user) without a
migration per entity, while still allowing a rare advanced domain to own its
tables. The reference package `chatom` proved per-panel physical tables are an
ops-heavy weight, not a sane default.

## Decision

A **Storage Panel** is a config-first *envelope* over a shared schema, not a table
generator (master plan §1.3 / D11–D12).

- **Zero-migration default.** A panel is a named configuration (host model +
  feature flags + permission driver + format handlers + id strategy + fill/present
  hooks). By default every panel and every storage lives in the **same** shared
  `v_*` schema, distinguished by a `panel` discriminator and a polymorphic
  `owner_type/owner_id` on `v_storages`. `Vaulter::for($anyEntity, panel: '…')`
  mounts a disk with **no migration** — like Nextcloud's single `oc_filecache`
  keyed by `storage`.
- **Own-tables is opt-in and advanced.** A panel that declares
  `->tablePrefix()/->tables()` gets its own table set via `vaulter:panel:migrations`;
  `PanelMigrationGenerator` *rejects* a panel without own tables. This is the
  rare path for schema-divergent domains, documented as a deliberate choice.
- **One schema definition, no drift.** `PanelTablesBuilder::define*` is the single
  place real domain schema lives, shared by the shipped `v_*` migrations and any
  generated per-panel ones. Registration is **FQCN-only** in config (config-cache
  safe, no closures), validated on boot; resolved per-panel state is keyed by
  panel id (no single memoization), and panel context survives queue/broadcast by
  value (a serialized string, never the panel object).

## Consequences

- Attaching a storage to any host entity costs zero migrations — the headline
  requirement, and the direct answer to chatom's per-entity migration sprawl.
- A heavyweight isolation mode (own tables, typed FKs, isolated vacuum) remains
  available without forcing it on the 99% case.
- Per-panel `idStrategy` is only legal in own-tables mode — one shared `v_nodes`
  has one PK type, so the registry rejects it on the shared schema (ADR-0011).
