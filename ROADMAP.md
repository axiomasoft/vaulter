# Roadmap — future ideas (not scheduled)

This is a parking lot for features that are **architecturally scoped but deliberately not
scheduled**. Everything here was either explicitly deferred by the maintainer or flagged
"optional" in the historical working plan (`docs/plan/00-master-plan.md`). It is outside
the archived evidence and remediation queue in
[plans/archive/REMAINING-WORK/REMAINING-WORK.md](plans/archive/REMAINING-WORK/REMAINING-WORK.md);
a future owner decision is needed to schedule an idea.

Each entry records the extension seam and historical source. Check both against current code
and ADRs before reviving an item; the older seam description is not execution approval.

## Content ingestion & editing

### `vaulter-medialibrary-bridge`
An additive **input-only** import bridge: `spatie/laravel-medialibrary` media → `v_nodes` /
`v_blobs`. Not a dependency of core (not even `suggest`) — a separate, optional package a host
installs only if it needs to migrate existing MediaLibrary collections into Drive.
- Seam: none needed beyond the existing `Contracts\Cas\*` / node-tree write path — a bridge
  package would just call into `Vaulter::for()` / the CAS commit path like any other consumer.
- Explicitly **not** related to the legacy DocVault excision (ADR-0014) — different data source
  entirely.
- Source: `docs/plan/00-master-plan.md` item P2 #6; decision D4 (`docs/plan/00-master-plan.md`
  Decision Table).

### XLSX / spreadsheet collaboration (Univer)
`UniverHandler` (Apache-2.0 SDK) exists as a **single-user** feature-flagged handler
(`spreadsheets` feature) — creating/autosaving an opaque Univer workbook snapshot works today.
What's deferred: **mounting the Univer editor UI** in a consumer app (that's host-side, not
this repo) and **multi-user Univer collaboration** (Univer's own OT engine, not Yjs — a
separate real-time transport from the Hocuspocus/Yjs path TipTap uses).
- Seam: `DocumentHandlerRegistry` (format-extensibility, H.5) — a new capability/driver slots
  in without core changes.
- Source: `docs/plan/00-master-plan.md` §7 / M5, line ~540 ("XLSX — Univer ... v1 single-user").

### OnlyOffice / Collabora (WOPI) handlers
High-fidelity OOXML editing via WOPI, as an opt-in `FileHandler` implementation. BYO-licence
(OnlyOffice Developer / Collabora are not bundled dependencies).
- Seam: `Contracts\Handlers\FileHandler` (same registry as TipTap/Preview/Univer).
- Source: `docs/plan/00-master-plan.md` §7 / M5; Decision D4 area.

### PDF / DOCX exporters
`Contracts\Handlers\DocumentExporter` seam exists (HTML/Markdown ship today); PDF/DOCX are
unbuilt but need no core change — same registry, `config('vaulter.exporters')` or
`Vaulter::registerExporter()`.
- Source: `docs/plan/00-master-plan.md` item P4 #12.

## Performance / scale (build when profiling demands it)

### ACL cache / materialized effective-grants read-model (H.4)
Today access resolution is an on-read CTE walk over ancestors. At scale, add a
version-stamped cache (`acl_version`/`tree_version`/`subject_version`/`model_version`,
length-prefixed cache key — **not** `:`-joined, per CVE-2026-48096) plus a materialized
effective-grants or ltree-closure read-model.
- Explicitly deferred in the plan as "when profiling requires it" — not a known bottleneck
  today.
- Source: `docs/plan/00-master-plan.md` item P4 #10; H.4 section.

### Activity hash-chaining / partitioning
The append-only `v_activity` feed is flat today (one row per event, §2.9). Hash-chaining
(tamper-evidence) and time-based partitioning are noted as future, not needed at current
scale.
- Source: `docs/plan/00-master-plan.md` item P4 #14(в).

### Realtime collab: Redis multi-instance, per-tenant concurrency caps
The Hocuspocus hub currently runs single-instance. `VAULTER_COLLAB_REDIS_URL` would enable the
Redis extension for horizontal scaling; per-tenant/per-document concurrent-connection caps are
backlog, not built.
- Source: `docs/plan/00-master-plan.md` Addendum A (hocuspocus-server), env-contract section.

## Admin UI

### `vaulter-filament` — engine-aware resources
The Filament panel's `Node`/`Document` resources are unaware of the pluggable-engine model
(`Contracts\Handlers\FileHandler`, `Vaulter\Engines\DocumentHandlerRegistry` — see
[Document handlers](/extending/file-handlers)): they don't surface which handler resolved a node,
let an admin browse registered handlers, or route a row action through a handler-specific
preview/export. Deliberately deferred — VLT-CANON scoped the engine canon itself and excluded
Filament from its scope (D6); this is the follow-up once a real consumer needs it.
- Seam: same `HandlerRegistry`/`NodeHandlerResolver` core seam the Vue client already consumes —
  a Filament resource would call `Vaulter::handlers()` / `app(NodeHandlerResolver::class)` like
  any other consumer, no core changes needed.
- Source: `plans/archive/VLT-CANON/plan.md` D6; ADR-00XX (editor engine canon, see P3.3).

### `vaulter-livewire` (native Livewire components)
An alternative to the Vue client for hosts that want native Livewire/Blade components instead
of a Vue SPA island. Not started — Filament (admin) and Vue (end-user Drive UI) cover the
current product surface.
- Source: `docs/plan/00-master-plan.md` item P4 #14(б).

## Docs

### Granular docs IA (`/concepts/ /usage/ /api/` split)
The docs site is currently a flat `docs/guide/*` + `docs/reference/*`, and it builds fine.
Splitting into a deeper information architecture is optional — only worth doing if the flat
structure becomes hard to navigate as content grows.
- Source: `docs/plan/00-master-plan.md` item P3 #8.

### Full ru translation
The core path (introduction/quick-start/installation/configuration/collaboration + concepts)
is translated. `reference/*`, recipes, filament, vue and internals guides remain English-only.
Large, mechanical, not release-blocking.
- Source: `docs/plan/00-master-plan.md` item P3 #7.

## Infra

### Distroless hocuspocus image
After the current Alpine-based image proves stable in production, consider a distroless base
for a smaller attack surface.
- Source: `docs/plan/00-master-plan.md` Addendum A, closing notes.
