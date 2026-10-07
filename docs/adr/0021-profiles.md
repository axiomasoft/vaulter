# ADR-0021 — Profiles

**Status:** accepted

> Transfers decision D05 of the 2026-09-29 stable audit
> (`audits/2026-09-29-stable-audit/opus/02-decisions.md#d05`) into the project ADR set.
> **Amends [ADR-0009 — Storage Panels](/adr/0009-storage-panels):** Panel is renamed to Profile and the
> own-tables mode is removed. The rest of ADR-0009 (zero-migration default on the shared `v_*` schema)
> stays in force.

## Context

ADR-0009 introduced the Storage Panel as a config-first envelope over a shared schema, with an
opt-in own-tables mode. In practice most of the panel API is dead code (audit finding N12):
`Panel::pipes()/resource()` and `StoragePanelProvider::fillNode()/presentNode()/pipes()/booted()`
are never called; panel flags affect only own-table generation; `Panel::model()` is unused at
runtime and repositories take the global models. Own-tables therefore works only at the
migration-generation level — a false promise. The documented per-panel permission lane is not
implemented either.

The term "panel" also collides with Filament *panels*, for which the package ships a plugin.

## Decision

**1. Profile = runtime policy.** A profile (`Vaulter\Profiles\Profile`) is a named set of
**runtime policies** for drives: allowed owner classes, disk, default quota, `UploadPolicy`,
permission driver, allowed document types, enabling of grants/share links/comments, revision and
trash retention. Effective policy = global ∧ profile (a profile only narrows).

**2. Removed:**

- the own-tables mode — `tablePrefix()/tables()/idStrategy()/models()`, `vaulter:panel:migrations`,
  `PanelMigrationGenerator`, `HasVaulterPanel`, `PanelTableResolver`;
- the dead hooks `pipes()/resource()/fillNode()/presentNode()/booted()` (N12);
- the `storage_type` column.

## Consequences

- Panel → Profile across code and docs; ADR-0009's own-tables path and the per-panel `idStrategy`
  consequence no longer apply.
- Data isolation is provided by `vaulter.database.connection` (after the N01 fix) and strict
  tenancy, not by per-profile tables.
- If an own-tables mode is ever needed again, it is added additively.

## Alternatives rejected

- **Keep own-tables** — it only generates migrations while repositories and relations use the
  global models; keeping it preserves a promise the runtime does not honour.

## References

- `audits/2026-09-29-stable-audit/opus/02-decisions.md#d05`
- `audits/2026-09-29-stable-audit/opus/01-review.md` — N01, N12
- [ADR-0009 — Storage Panels](/adr/0009-storage-panels) (amended)
