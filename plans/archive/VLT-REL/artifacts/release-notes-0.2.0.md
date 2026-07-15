# Vaulter v0.2.0 — release notes (DRAFT)

> **Status: DRAFT / not cut.** This is the P5.1 draft produced by `vaulter-release-conductor`.
> The git tag `v0.2.0` and GitHub release are P5.2 — gated on owner approval (D8/D10). This
> document does NOT cut, push, or publish anything.
>
> **Authoritative facts (do not re-derive):** release number is **`0.2.0`** (git tag `v0.2.0`),
> per owner decision **D12** (`plans/VLT-REL/plan.md` §5). This is the **first git tag in the
> package's history** (`git tag` = 0) — there is no "since last tag" range; the content source
> is the `## [0.2.0]` section of `CHANGELOG.md`, not `git describe`. Version class is **`0.x`**
> (D2): no formal BC obligation yet — see the pre-1.0 note below.

---

```text
VERSION: 0.2.0 (bump: minor ; reason: first consolidated public surface tagged as 0.x —
         re-platform into embeddable virtual document storage; no BC obligation under 0.x [D2];
         number set to 0.2.0 by owner D12 to avoid collision with the never-tagged legacy
         [0.1.0] draft section — legacy retained as historical draft)
CHANGELOG: /home/vostrikov/projects/packages/vaulter/CHANGELOG.md
           (## [0.2.0] — 2026-07-15 ; Added / Changed / Removed populated ;
           Deprecated / Fixed / Security — none this release)
BREAKING: none for the 0.2.0 consumer — this is the first consolidated surface, BC clock
          starts AT this tag. The "BREAKING (pre-1.0)" CHANGELOG entries are history BEFORE
          the first tag; relevant only to anyone who tracked the untagged main. See §3.
DEPRECATIONS: none declared this release.
ANNOUNCE: /home/vostrikov/projects/packages/vaulter/plans/VLT-REL/artifacts/release-notes-0.2.0.md (§2)
NEXT: git tag v0.2.0 ; gh release create v0.2.0 ...   # TEXT-ONLY placeholder — P5.2, owner-gated. DO NOT RUN.
```

---

## 1. Release notes (Keep a Changelog highlights)

**Vaulter 0.2.0** is the first tagged release: an embeddable virtual document storage for
Laravel, shipped as **six Composer packages** —
`axioma-studio/vaulter-{core,media,drive,documents,filament,azgard}`.

> The npm client `@vaulter/vue` (`0.0.0`) ships **outside this git tag**, on its own independent
> npm track (D3). It is mentioned here only where the git-tagged surface touches it.

### Added

- **Config-first Storage Panels** — `Vaulter::for()`, per-panel table prefixes and an id strategy
  (`bigint` | `ulid` | `uuid7`), with a per-panel schema/migration generator.
- **Content-addressable blob store (CAS)** — scoped dedup (global/tenant/storage), race-safe
  commit (Postgres advisory lock), two-phase garbage collection and a reconcile command.
- **Composite ACL** — `AccessResolver` over node grants, host-role drivers and share links, with
  access-aware listing. **Four permission drivers**: Database (default), Gate, Spatie, AzGuard.
- **Real-time collaboration (node-attached)** — Hocuspocus driver, Yjs snapshot persistence and a
  TipTap projection; JWT-authenticated rooms bound to a single node.
- **Resumable uploads** — tus + S3-multipart, server-recomputed content hashes, a sweep command.
- **Append-only activity feed** and **transactional-outbox webhooks** with a single relay-sweep
  owner and idempotent delivery.
- **Handler-aware documents** — rich-text (TipTap) and opaque snapshots (e.g. the Univer
  spreadsheet handler); the create-path resolves the node's handler from its `mime`.
- **Pluggable export formats** — register a `\Vaulter\Contracts\DocumentExporter` via
  `config('vaulter.exporters')` or `Vaulter::registerExporter()` to add PDF/DOCX/… alongside the
  built-in HTML and Markdown; the export response carries the exporter's `mime_type` (binary
  exporters return base64 `content`).
- **Swappable share-link token generator** — `config('vaulter.share_token_generator')` +
  `\Vaulter\Contracts\ShareTokenGenerator` (default stays a 48-char random string).
- **Configurable table prefix** — `config('vaulter.table_prefix')` (env `VAULTER_TABLE_PREFIX`,
  default `v_`) prefixes every Vaulter table so the package coexists with host tables.
- **AzGuard bridge** (`axioma-studio/vaulter-azgard`) — a real `AzgardGuard` adapter delegating to
  AzGuard scoped roles and workspace-context checks, keeping core AzGuard-free.
- **Full Postgres portability lane** — the schema-portable suite runs against real Postgres
  alongside the default in-memory SQLite.
- *(npm, outside the git tag)* **`@vaulter/vue`** Drive client and an explicit engine-component
  registry (`registerEngineComponent` / `resolveEngineComponent` / …).

### Changed

- **Re-platformed into an embeddable virtual document storage** — the old document/folder model
  gave way to a **Drive** domain (storages + a recursive folder/file node tree) backed by a
  content-addressable blob store (CAS). Collaboration is now **node-attached** and standardised on
  **Hocuspocus** (Reverb is retained for notifications, not collaboration). The release artifacts
  are the six split Composer packages listed above.
- **Native Pest mutation testing with real thresholds across every package lane** — mutation
  testing moved off Infection (which shipped no Pest adapter and had never actually executed a
  mutant under `|| true`) onto `pest --mutate`. `core` and all four package lanes now run a real
  threshold with **no `|| true`** and score **100.00%** at the measured baseline. The four
  `infection.json5` files and the `infection/infection` dev-dependency are gone.
- **Handler surface is kind-neutral** — the format-handler contract now describes *any* file
  (image / pdf / download-only), not only editable documents; capabilities no longer assume rich
  text by default, and a drive-only host resolves a real preview/download handler for every node
  instead of a silent `null`.

### Removed

- **Split of the monolith into standalone packages** — the CAS engine → `vaulter-media`; the
  file/folder tree, storage manager and resumable uploads → `vaulter-drive`; document editing,
  version history, comments and realtime collaboration → `vaulter-documents`. A host that used to
  rely on `vaulter-core` alone must now also `require` the relevant package(s). Contract seams and
  schemas that hosts bind against remain in core.
- **Legacy `document_id`-bound DocVault path excised** — the `Document` / `DocumentFolder` /
  `DocumentVersion` / `DocumentPermission` / `DocumentComment` / `DocumentShare` models, their
  `v_document*` tables and the legacy repositories/controllers/Actions/events/resources were
  removed in favour of the single canonical `v_nodes` document path. Use `Vaulter::for()` and the
  node/document endpoints instead.
- *(npm, outside the git tag)* `@vaulter/vue` dropped its legacy document-centric surface in favour
  of the Drive surface.

### Deprecated / Fixed / Security

- None declared for 0.2.0.

> **Full detail, including every pre-1.0 FQCN move and migration table, lives in the `## [0.2.0]`
> section of `CHANGELOG.md` and in `UPGRADE.md`.** The highlights above are the user-facing
> summary.

---

## 2. Release announcement (draft)

**Vaulter 0.2.0 — first tagged release.** Vaulter is now an embeddable virtual document storage
for Laravel: a Drive domain (storages + a recursive node tree) over a content-addressable blob
store, with node-attached realtime collaboration, a composite ACL and transactional-outbox
webhooks — shipped as six focused Composer packages.

**Highlights**
- Config-first Storage Panels (`Vaulter::for()`), pluggable id strategy and table prefix.
- Content-addressable blob store with scoped dedup, race-safe commit and two-phase GC.
- Composite ACL with four permission drivers (Database / Gate / Spatie / AzGuard).
- Node-attached realtime collaboration over Hocuspocus + Yjs.
- Resumable uploads (tus + S3-multipart) and idempotent transactional-outbox webhooks.
- Native Pest mutation testing at a real threshold across every package lane (100% baseline).

**Breaking for 0.2.0: none.** This tag is the entry point — you adopt one consolidated surface,
not a series of migrations. (The `BREAKING (pre-1.0)` notes in the changelog are pre-tag history;
see the breaking summary below.)

**Pre-1.0 note.** 0.2.0 is a `0.x` release: it signals "ready to adopt" but carries **no formal
backward-compatibility obligation** (semver allows breaking changes in a `0.x` minor). Stability
is held in practice by a frozen public-API arch/snapshot gate, not by the version number. Known
adoption conditions are catalogued in `plans/VLT-REL/root/known-limitations.md`; the forward
compatibility path is in `plans/VLT-REL/root/upgrade-path.md`.

*Thanks to everyone who contributed to the re-platform and the split.* (Fill contributor
handles from `git shortlog -sn` for the range at publish time — currently a single-author tree.)

---

## 3. Breaking-change summary

**For the 0.2.0 consumer: there are no breaking changes.** This is the first consolidated public
surface Vaulter ships, and the backward-compatibility clock **starts at this tag**. You adopt
0.2.0 as a fresh baseline — there is nothing older, tagged, to break against.

The `## [0.2.0]` CHANGELOG section carries a series of **`BREAKING (pre-1.0)`** entries (CAS →
`vaulter-media`, tree/uploads → `vaulter-drive`, documents/collab → `vaulter-documents`, the
kind-neutral handler renames, the legacy DocVault excision, the AzGuard bridge rename, …). Those
are **history from BEFORE the first tag** — the internal migration path taken on the never-tagged
`main`. They are relevant **only to anyone who tracked untagged `main`** (via a path/VCS Composer
repo or by importing internal FQCNs directly). For the 0.2.0 consumer they are informational
context, not an upgrade burden.

**Deprecations:** none declared this release. Nothing is scheduled for removal in a future major.

**Pre-1.0 risk (D2):** because 0.2.0 is `0.x`, a future minor *may* still carry a breaking change.
Vaulter's policy is to **avoid** breaks on the frozen public surface and to catch them at the
arch/snapshot gate; any real, unavoidable break lands as a `0.y.0` **with an explicit `BREAKING`
label in the changelog and an upgrade note** (`plans/VLT-REL/root/upgrade-path.md`,
`root/contracts/AMEND.md`). Do not read `0.x` as an implicit BC guarantee.

---

## 4. Open questions for the maintainer

- **None on the version number** — `0.2.0` / class `0.x` is authoritatively fixed by D12/D2.
- **Contributor line in the announcement** (§2) is a placeholder — populate handles at publish
  time from `git shortlog -sn` for the release range.
- **Publish scope reminder (not a blocker):** git tag + GitHub release now; **Packagist is
  deferred** (D9) and the tag-triggered `monorepo-split` workflow is expected to run and fail
  harmlessly against not-yet-provisioned split repos. Cutting the tag itself is **P5.2**, gated on
  explicit owner approval — not performed here.
