# ADR-0014 — Legacy DocVault: build parity, then excise from core

**Status:** accepted

## Context

Two document paths coexist in `packages/core` today.

- **Legacy DocVault** — six live tables (`v_documents`, `v_document_folders`,
  `v_document_versions`, `v_document_permissions`, `v_document_comments`,
  `v_document_shares`; migrations `000001`–`000006`), six swappable models under
  `Models/Documents/*`, and a full stack of repositories, controllers, actions,
  events, resources and a `Document`-bound `CollaborationDriver`, exercised by
  ~16 Feature tests. This is scaffolding carried over from the DocVault → Vaulter
  rename (commit `ac140fc`): the old product's document-centric schema, kept intact
  through the re-platform, doc-only (content is inline TipTap JSON, no binary files).
- **Canonical node tree** — the unified `v_nodes` adjacency tree (ADR-0005) where a
  `kind` column discriminates `folder | file | document | shortcut`, with satellite
  tables `v_files`/`v_file_versions`/`v_blobs` (CAS, ADR-0006),
  `v_node_document_versions` (Yjs truth + TipTap projection), `v_node_grants` +
  `AccessResolver` (ADR-0007), and `v_node_shares` (ADR-0013), served by a
  `Node`-bound `NodeCollaborationDriver`.

ADR-0005 already frames the legacy two-tree schema as the thing being unified away,
and Decision-Table D10 states documents "evolve from `v_documents`". Plan item
P4 #9 forces the call: strangler-migrate and deprecate, or keep the legacy path.
Reconnaissance plus an owner decision establish four load-bearing facts.

1. **The node path is a strict superset of legacy for every concept except one.**
   folder, document, version, ACL (`DocumentPermission` → `v_node_grants`, which
   adds inheritance, allow/deny, subtree scope, expiry and host-role folding) and
   share (`DocumentShare` → `v_node_shares`, which adds hashed bearer tokens,
   password/login/discovery gates, a use-budget and item-safety). The single gap is
   **comments**: there is no `NodeComment` — the node path only carries the
   `Ability::Comment` flag and `GrantLevel::Commenter` (both READ bits with nothing
   to persist against), whereas legacy `DocumentComment` has threading (`parent_id`),
   a text-range `anchor` and `resolved_at` with live broadcast. Deleting legacy before
   this exists would regress a real feature, not remove a crutch.

2. **"Keep it as a bridge for the FlexCRM legacy editor-collection (medialibrary)"
   rests on a false coupling.** `spatie/laravel-medialibrary` is not a dependency of
   any package here (not even a `suggest`), no medialibrary code exists in-repo, and
   the `vaulter-medialibrary-bridge` (ADR-0008) is an unbuilt, additive **input**
   importer that maps spatie media **into `v_nodes`** — never into `v_documents`.
   Legacy DocVault is not, and never was, that bridge. FlexCRM is an external
   Laravel-based consumer referenced only in docs.

3. **There is no live DocVault data to preserve anywhere** (owner-confirmed). The
   package is pre-1.0 and has never shipped a tagged release (no `version` field,
   `branch-alias dev-main → 0.1.x-dev`, CHANGELOG `[Unreleased]`, first target
   `v0.1.0`); the `Models/Documents/*` API was never published under a stable tag; no
   consumer holds `v_documents` rows that must survive. So there is **nothing to
   back-fill and no released consumer to protect** — an in-core deprecation window
   would guard no one.

4. **Core must stay azguard-free** — talk only through the `AzGuardGuard` seam /
   `HostRoleDriver`. (spatie/laravel-medialibrary current stable is 11.22.1,
   PHP 8.2+ / Laravel 10–13 — verified 2026-07, but irrelevant to the coupling.)

## Decision

**Build node-attached comments to reach full parity, then excise the entire legacy
DocVault from core — leaving one canonical `v_nodes` path.** Concretely:

- **Close the one parity gap first.** Add a node-attached comments capability
  (`v_node_comments` keyed on `node_id`, with threading / anchor / resolve + broadcast).
  This is a genuine feature, and a hard prerequisite of removal — otherwise excision
  regresses commenting.
- **Then remove the legacy DocVault outright, before `v0.1.0`.** Delete the six models,
  their tables/migrations, repositories, controllers, routes, actions, events,
  resources, the `Document`-bound `CollaborationDriver`, the swappable-model config
  keys, and the legacy tests. **No backfill command and no in-core deprecation window**
  — there is nothing to migrate and no released consumer to protect (Context 3). The
  first tagged release ships with zero legacy.
- **Reject "keep as a medialibrary bridge."** It is a false coupling (Context 2); the
  medialibrary bridge remains a separate, orthogonal, still-unbuilt importer into
  `v_nodes` (ADR-0008 stands unchanged and de-coupled from this decision).
- **Out of scope, deferred:** should a future host ever surface with real DocVault-era
  data, a one-off `v_documents → v_nodes` migrator can be built **then**, as an optional
  separate bridge package (mirroring `vaulter-medialibrary-bridge`) — **never** back in
  clean core.

## Migration plan (build-parity-then-excise)

Two atomic, independently-shippable steps; each green before the next.

1. **Node-attached comments** — `v_node_comments` (`node_id`, `parent_id` thread,
   author morph, `body`, `anchor` jsonb, `resolved_at`), model, read/store repos,
   `AddNodeComment` / `ResolveNodeComment` / `DeleteNodeComment` actions,
   `nodes/{node}/comments` routes gated on a `comments` feature, broadcast events,
   tests. **Done:** node-path comment parity with legacy `DocumentComment` (threading,
   anchor, resolve, broadcast) — verified by tests.
2. **Excise legacy DocVault** — delete migrations `000001`–`000006` (or ship a
   `drop-legacy-docvault` migration for any dev DB that already ran them), the six
   `Models/Documents/*` models, their repositories / controllers / routes / actions /
   events / resources, the `Document`-bound `CollaborationDriver` and its
   implementations, the `config('vaulter.models.*')` legacy keys, and the ~16 legacy
   Feature tests. **Done:** `grep -r 'Models\Documents\Document' packages/core/src`
   returns only `NodeDocumentVersion`; the gate is green; core carries a single
   document path; shipped in / before `v0.1.0` with a CHANGELOG note.

**Rollback.** Step 1 is purely additive (a new table + feature). Step 2 is a single
excision commit — because no data is preserved and no consumer depends on the removed
API, rollback is plain VCS revert, not a data-recovery exercise. Nothing is one-way at
the data level.

## Consequences

- Core reaches `v0.1.0` with **one** document path to authorize, list, version,
  collaborate on and test. The dual-stack tax (two version tables, two collaboration
  drivers, two ACL models, two share models, duplicated controllers / actions / events)
  is gone — the clean first release the owner asked for.
- The only real work before the win is **building node-attached comments** — a genuine
  new capability, not a mechanical migration. Everything else is deletion.
- No backfill, no deprecation window, no bridge package now — justified precisely
  because nothing is released and no data exists to preserve (Context 3). If that
  assumption ever breaks (a host adopts vaulter from a DocVault-era schema), the
  migrator is built then as an optional bridge, keeping core pristine.
- The product's **file + document hybrid** is preserved untouched — and is the reason
  to consolidate here, not away from it: binary uploads live as `kind=file` nodes over
  the CAS (`v_files`/`v_file_versions`/`v_blobs`), while editable documents (e.g. a
  `.docx` opened as a TipTap document) live as `kind=document` nodes on the Yjs/TipTap
  collaborative path. Legacy DocVault was **doc-only** and never had the file half, so
  excising it removes the *less* capable path. Future formats and editors
  (Univer/XLSX, OnlyOffice/Collabora, PDF/DOCX exporters) plug in through the
  `DocumentHandlerRegistry` seam (D21, ADR-0004) — a handler class + registration, no
  migration and no core change — so the hybrid stays organically extensible.
- Comments code stays azguard-free (talks only through `AzGuardGuard` / `HostRoleDriver`),
  preserving the core invariant.
- ADR-0008 (medialibrary) is untouched and explicitly de-coupled here; the
  `vaulter-medialibrary-bridge` remains a separate future import path into `v_nodes`.
- Precedent for pre-1.0: replacement-parity first, then delete outright — deprecation
  windows are for *released* surfaces, not pre-release scaffolding. Worth a line in the
  release policy so this isn't re-litigated per legacy artifact.
