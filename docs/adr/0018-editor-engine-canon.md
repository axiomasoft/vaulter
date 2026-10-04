# ADR-0018 — Pluggable editor-engine canon (kind-neutral handler contract)

**Status:** accepted

> Amended by ADR-0022 (proposed).

> Consolidates the design-and-execution record of **VLT-CANON** (`plans/archive/VLT-CANON/plan.md`,
> Decision Log D1–D12, phases P0–P3). Supersedes `plans/VLT-HSEAM/` in full — VLT-HSEAM's scope
> (leaks L1–L5, capability-policy, DX scaffolding, Q1/Q2) was absorbed and closed here; see
> "Supersession" below.

## Context

Vaulter models file editing on the Google-Drive pattern: double-click a node, get routed to the
editor registered for its mime/kind. A 2026-07-11 recon found the routing infrastructure already
existed as seams — `DocumentHandler`/`HandlerRegistry`/`NodeHandlerResolver` (mime/ext dispatch,
capabilities, priority, fallback `DownloadOnlyHandler`), a server→client descriptor
(`HandlerDescriptor{id,capabilities,ui}` via `GET nodes/{node}/handler`), and a front-end component
registry (`componentMap` keyed by `handler.id`/`ui.component` in `VaulterDocumentNode.vue`) — a
close match for the WOPI/Nextcloud/Filament class of pattern. What remained was TipTap (the
rich-text engine) leaking into names, contracts, and schema defaults that should have been
kind-neutral from the start, plus an unresolved packaging/naming question for third-party
front-end engines and a final structural canon pass over the monorepo. Package is pre-1.0:
renames are free under SemVer.

## Decision

**D1 — Do not rebuild the registry/descriptor/front-registry.** They already distill the
WOPI/Nextcloud/Drive pattern; VLT-CANON only finishes them (P0.1–P0.5).

**D2 — VLT-CANON absorbs VLT-HSEAM wholesale.** One owner-scope for "engines as plugins"; running
two plans against the same surface drifts. VLT-HSEAM is marked superseded here (P3.3).

**D3 — Layer-canon A (ADR-0017, VLT-REMED P4.9/P4.10) is the baseline, not reopened.**
VLT-CANON operates one topology level above it.

**D4 — Dynamic mime-based engine resolution; no handler-id persisted on `Node`.** WOPI/Nextcloud/
Drive all resolve dynamically; a persisted handler-id would go stale the moment a better engine
registers for the same mime.

**D5 — Bespoke narrow contract, not `Illuminate\Support\Manager`.** `Manager` couples to `$app`,
carries statefulness and `__call` magic unsuitable for a package's public contract; at most an
internal implementation detail.

**D6 — Filament is out of active scope.** ROADMAP-only placeholder (`vaulter-filament`
engine-aware resources); an explicit ADR-0017 exception, owner directive 2026-07-11.

**D7 — Kind-neutral contract naming: `FileHandler` in `Contracts/Handlers/`.** The contract
describes ANY file, not only editable ones — fallback handlers (`DownloadOnlyHandler`/
`PreviewHandler`) are not "engines." Executed P0.1: hard-renamed `DocumentHandler` →
`FileHandler`, `Documents/` namespace segments → `Handlers/`, across core, documents,
`@vaulter/vue` types, and docs (32 files, rename-only diff verified by `vaulter-api-surface`).

**D8 — Front-end engine packaging: single `@vaulter/vue` stays the shell/core.** The TipTap
engine ships inside it on one version-train with PHP; per-engine npm packages
(`@vaulter/vue-engine-<name>`) are introduced lazily, only for a real external consumer.
Confirmed by research (`findings/frontend-engine-packaging-research.md`): Filament v4, Nova 5,
and Statamic v5 do not publish a separate npm package per plugin; `awcodes/filament-tiptap-editor`
bundles TipTap + 15 extensions in one package. Executed P1.1–P1.3.

**D9 — `vaulter-media` stays a standalone package.** It is a reusable CAS engine (ADR-0008
topology) that `documents` does not require and `drive` already depends on; merging breaks "media
= pure engine" for no product gain and forces a BC namespace move. Executed P2.2 (no code change —
status quo confirmed).

**D10 — The handler registry engine moves to `core`.** `DocumentHandlerRegistry` /
`NodeHandlerResolver` / `DocumentFileDescriptorFactory` / `Capability` / baseline
`PreviewHandler`/`DownloadOnlyHandler` moved from `documents/src/Documents/` to
`core/src/Engines/*` / `core/src/Enums/Handlers/*`; `documents` keeps only the TipTap/Univer
drivers. **This explicitly supersedes two earlier design artifacts:** VLT-REMED finding H.5
("engine lives in documents, core fails loud without it") and the T5 Option-B topology allocation
(`docs/plan/p2-5-option-b-topology.md`: "`Documents\*` (registry/handlers/services) → docdrive").
Driving requirement: a **drive-only host** (files, no editing) must get a preview/download
descriptor without installing `vaulter-documents` — before P0.4, `NodeResource::handler()` silently
returned `null` for such a host. Executed P0.4: core `VaulterServiceProvider` binds the registry
unconditionally and tags a baseline handler set into `vaulter.handlers`; `documents` only adds
`TipTapHandler` (and `UniverHandler` behind its feature flag).

**D11 — `yjs_state` column keeps its name.** Nullable, unwritten by non-collab engines, discriminated
by `format` — renaming to `crdt_state` would be pure cosmetics with no functional gain. Executed
P0.5 (no code change — status quo confirmed).

**D12 — The server-side descriptor (`GET nodes/{node}/handler`) is kept, but is a WOPI-class
pattern, not a universal norm.** Adversarial verify refuted "Drive/Box/Dropbox all map mime→viewer
client-side" as a universal claim. The honest basis: (a) the WOPI CheckFileInfo model is confirmed
precedent; (b) registry statics (mime/priority/capabilities, ≈ `discovery.xml`) are deliberately
kept separate from per-file/per-user dynamics, which live in `AccessResolver`, not in
`capabilities`; (c) the stale-mime→editor-association bug class from persisting a resolved handler
is confirmed real, reinforcing D4.

## Supporting canon closed alongside D1–D12 (P0–P2 execution)

- **Kind-neutral capability defaults (leak L4).** `HandlerCapabilities::$richText` default flipped
  `true` → `false`: an undeclared capability is absent, not assumed rich-text. Capability axes are
  additive and open-ended; clients must ignore unknown keys (P0.5 `CapabilityPolicyTest`).
- **Opaque exporter contract (leak L1).** `DocumentExporter::export()` takes an opaque
  `DocumentContent`, not the concrete `TipTapContent`; implementations narrow internally and reject
  unsupported content via `UnsupportedDocumentContentException` (P0.2). The read path
  (`NodeExportService::contentOf()`) became fully kind-neutral in P0.5, branching on `richText`
  instead of assuming TipTap.
- **TipTap forms isolated in the driver package (leak L2).** `TipTapContent`,
  `LinkSchemeValidator`, `OpaqueDocumentContent`, `TiptapExtensions`,
  `InvalidTipTapContentException` moved `core` → `documents` (P0.3); the XSS allow-list/decay
  barrier (VLT-REMED R-01/R-11/R-12) carried over byte-for-byte, verified by diff against
  pre-move blobs.
- **`format` column has no `default('tiptap')` (leak L3).** Every write path now stamps `format`
  explicitly (P0.4); the column remains a mixed value-space (`DocumentType` values plus
  handler-ids like `univer`) without a cast/backfill — a known, documented residual, not solved by
  this ADR (see Consequences).
- **DX scaffolding for third-party engines.** `Vaulter\Engines\Handlers\AbstractFileHandler`
  (optional convenience base, built-in handlers stay `final`) plus
  `docs/guide/writing-an-engine.md` — capability-policy rules, payload-asymmetry guidance
  (WOPI/office-class engines return a fundamentally different payload shape than TipTap's), a
  `schemaVersion` recommendation, and the `@univerjs/*` vs `@univerjs-pro/*` licensing boundary
  (P0.5).
- **Final structural canon (Part 3, P2).** `permissions-azgard` → `azgard` package/namespace
  rename (P2.1); `vaulter-media` kept standalone (D9, P2.2); catch-all folders (`Documents/`,
  `Uploads/`, `Support/`) resolved per ADR-0017 (P2.3); Access/Uploads structural sweep (P2.4).
- **Arch-test regression net (P3.2).** `core/tests/Arch/ArchTest.php` gained: a file-scan asserting
  zero concrete TipTap class references in `core/src` (CamelCase pattern, comment-stripped scan;
  the lower-case `config('vaulter.tiptap.*')` host passthrough is a documented, distinct
  invariant and is intentionally excluded), and a namespace-graph test asserting
  `Vaulter\Contracts\Handlers` never depends on `Vaulter\Documents` (D7 regression guard).

## Consequences

- Any third-party editor engine binds through the same `FileHandler`/`HandlerRegistry` seam TipTap
  and Univer use — no privileged built-in path.
- `core` has zero source-level dependency on `vaulter-documents`; a drive-only host gets working
  preview/download descriptors without installing the documents package (D10).
- Capability axes are additive by contract: adding a new capability key must not break a client
  that does not recognize it. This is arch/behaviour-tested (P0.5 `CapabilityPolicyTest`), not just
  documented.
- **Known residual debt, deliberately left open by this ADR** (tracked in
  `plans/archive/VLT-CANON/handoff.md` "Open risks", not assigned to any closed item):
  1. `format` column mixed value-space, no cast/validation, no backfill for pre-P0.4 rows.
  2. CI gate-scope gap: the XSS allow-list/decay barrier now lives in `packages/documents`, whose
     CI lane (`documents.yml`) runs `pest --ci` without an enforcing `--coverage`/mutation
     threshold — `packages/core`'s `tests.yml`/`mutation.yml` thresholds no longer cover it.
  3. `docs/ru/reference/contracts.md` lags the English `HandlerRegistry`/`NodeHandlerResolver`
     sections.
  4. `docs/plan/*` design-phase artifacts (`00-master-plan.md`, `p2-5-*`) still name the pre-rename
     `permissions-azgard`/`Documents\Documents` FQCNs; they are historical design records, not in
     the `docs:build` navigation, and were left as-is.
- Pre-1.0 status made every rename in this ADR (D7, D10, package rename D9/P2.1) a free, immediate
  hard rename — no alias phase, recorded in `UPGRADE.md`/`CHANGELOG.md [Unreleased]` per item.

## Supersession

**`plans/VLT-HSEAM/` is superseded by this plan/ADR, effective VLT-CANON P3.3.** VLT-HSEAM's own
`plan.md` already carried a cross-notice ("this plan is absorbed, do not execute it") since
VLT-CANON's approval; this ADR is the consolidating record that closes the formal marking VLT-HSEAM
promised at "after the consolidating ADR." VLT-HSEAM's `root/adr-file-handler-seam.md` remains as
historical design record; its scope (L1–L5, Q1/Q2) is fully accounted for in D7–D12 above.
