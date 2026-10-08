# P4.3 — check-and-close prompt for Sonnet 5.5 (medium)

Paste everything below the line into a fresh Claude Code session started in
`/home/vostrikov/projects/packages/vaulter` on **Sonnet 5.5, effort medium**.

---

You finish plan item **P4.3** of plan `2026.09.29-№1-VAULTER-STABLE` ("Document type registry, codecs and a safe
fallback"). Code, tests and most docs are already written (uncommitted) by an Opus 5.5/high session that ran only
`php -l` and Pint on changed files (owner instruction: no long runs there). Your job: run the checks, fix **small**
defects, finish two leftover doc mirrors, finish and commit. Do not redesign.

`plan.py` = `python3 /home/vostrikov/.claude/plugins/cache/swissknifeman/task/0.28.3/scripts/plan.py`;
`PL=2026.09.29-№1-VAULTER-STABLE`; artifacts `A=plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P4.3`.
Actor for every `plan.py log|finish|commit`: `--actor anthropic/claude-sonnet-5-5/medium`.
Do **not** run `plan.py start` (already started; your route is below the item's and would be refused). Never
`git add -A`, `--no-verify`, amend or push. Leave the owner's dirty files alone: `.swissknife/analyst/**`,
`plans/…/views/**`, `packages/documents/.claude/`. No review is requested for this item.

## 0. Read first (short)

- `$A/check-profile.json`, `$A/completion-notes.md`, `$A/negative-controls.md`
- `grep '"P4.3"' plans/2026.09.29-№1-VAULTER-STABLE/log.jsonl` — recorded deviations/assumptions (do not undo them)
- `git status --short`; on a failing test, the related part of `git diff`.

Intended behaviour (do not "fix" back):
- Types come from `vaulter-documents.types` (default rich-text + markdown), tag `vaulter.document_types`, or provider
  `register()`; the registry freezes after boot; keys `vendor/name`; `vaulter/` reserved; duplicates throw.
- Spreadsheets need `SpreadsheetDocumentType` in `vaulter-documents.types` (the `spreadsheets` flag is legacy).
- Document nodes are presented by their type: `handler.id === null`, `document_type`, `type_available`, viewer
  `vaulter/tiptap` (`props.preset`), `vaulter/univer` or `vaulter/unsupported`; capabilities have no `rich_text`;
  documents report `downloadable: false`. Files `.docx/.md/.xlsx` are preview/download, never editable.
- Every document node mime is `CreateDocumentInput::NODE_MIME` (`application/vnd.vaulter.document+json`).
- Unknown type: content readable (OpaqueContent), `editable=false`; update/snapshot/restore/export/collab → 409
  `document_type_unavailable`; copy/trash/purge work. Degraded or newer-schema content: `editable=false`, update → 409
  `document_content_degraded`.
- `DocumentOperations::create($name, ?string $type = null, …)`; profiles name type keys (`'vaulter/markdown'`).
- Tests that need extra types swap the registry (`TypesTestCase::installTypes()` or a fresh
  `DocumentTypeRegistry(app())` bound with `app()->instance()`) — that simulates the next request after a host
  config change; keep it.

## 1. Goldens (once, after reading)

```bash
cd packages/documents
UPDATE_SNAPSHOT=1 env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Arch/PublicApiSnapshotTest.php
git diff tests/Arch/__snapshots__/public-api.txt
cd ../.. && UPDATE_SNAPSHOT=1 env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci packages/core/tests/Arch/PublicApiSnapshotTest.php
git diff packages/core/tests/Arch/__snapshots__/
```
Expected documents diff: new blocks for the contracts (`DocumentType`, `DocumentCodec`, `DocumentContent`,
`SupportsCollaboration`, `UpgradesSchema`), `DocumentsConfig` (`types`, `defaultType` added; `enabledDocumentTypes`,
`spreadsheetsEnabled` removed), the `Types\*`, `Content\*`, `DocumentPresenter`, `DocumentTypeContractTests` blocks,
`DocumentOperations::create` with `?string $type = null`, `updateContent` taking `array`, `createDocument` gone,
`DocumentsManager::types()`/facade returning `Types\DocumentTypeRegistry`, `DocumentSnapshot::$content` typed as
`Vaulter\Documents\Contracts\DocumentContent`. Expected core diff: `KindPresenter` added, `ContentCapabilities` without
`richText`, `NodePresentation` with nullable `id` + `documentType`/`typeAvailable`, `RegistryFrozenException` added,
`Exceptions\Documents\*` gone. Any other changed block → stop and report. (If the core golden test has no
`UPDATE_SNAPSHOT` path or the file name differs, look at the test header for its regeneration command.)

## 2. Acceptance checks (full output to `$A/receipts/<id>.txt`; report counts only)

| id | cwd | command |
|---|---|---|
| V1 | packages/documents | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Types tests/Document tests/Unit tests/Operations tests/Arch` |
| V1x | packages/documents | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Feature tests/Profiles tests/Providers tests/Configuration tests/Collaboration tests/Comment tests/Schema` |
| V2 | . | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci packages/core/tests/Arch packages/core/tests/Configuration packages/core/tests/Profiles` |
| V2x | . | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci packages/core/tests/Feature packages/core/tests/Unit packages/core/tests/Support packages/core/tests/Content packages/core/tests/ObservabilityDocuments packages/core/tests/E2E packages/core/tests/Providers` |
| V3 | packages/filament | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Feature` |
| V80 | . / packages/documents / packages/filament | `scripts/php84 vendor/bin/pint --test packages/core && scripts/php84 vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G --no-progress`; in packages/documents and packages/filament: `../../scripts/php84 vendor/bin/pint --test && ../../scripts/php84 vendor/bin/phpstan analyse --memory-limit=512M --no-progress` |
| V81 | . | `python3 plans/2026.09.29-№1-VAULTER-STABLE/artifacts/coverage/opus-coverage.py check` |

V1x/V2x are impact checks of edited callers (not plan acceptance ids) — report them in the notes.

Likely small failures and how to treat them:
- **Pint**: run Pint in the package's own cwd (`packages/documents`, `packages/filament`) — the root config is only for core.
- **PHPStan**: generics/array shapes (`array<string, mixed>` vs `array<array-key, mixed>`, `list<class-string>`), the
  `@param DocumentType|class-string<DocumentType>|string` union in `DocumentTypeRegistry`, `$type::class` on a
  resolved object, `Document::query()->first([...])` column subsets, PHPUnit `Assert` in `src/Testing` (the trait is
  meant to run inside a host test suite). Fix with precise types; no `@phpstan-ignore` unless that exact pattern is
  already ignored in the package.
- **A test still expecting the old world**: `handler.id = 'vaulter/tiptap'`/`'vaulter/univer'`, `rich_text` keys, the
  xlsx mime on a spreadsheet node, `format:`/`mimeType:` on `CreateDocumentInput`, `enabled_document_types`, profile
  keys `'markdown'` → update the expectation to the intended behaviour above.
- **Pest global function/const name clash** between test files (they share one process) → rename the helper.
- **Registry frozen in a test** (`RegistryFrozenException`) → the test must swap in a fresh registry as described, not
  register into the booted one.
- **ConfigNormalizer tests** (`packages/core/tests/Configuration/*`): new cases for `enabled_document_types` /
  `features.spreadsheets`; the published 0.2 fixture keeps `enabled_document_types` when documents is not installed
  (core TestCase has no documents provider) — that is intended.
- **service-locator ratchet** (`packages/core/tests/Arch/ServiceLocatorRatchetTest.php`): the baseline was moved by hand
  (TipTapSchema 1, UpdateDocumentContentRequest 1, old TipTapContent/HasTiptapExtensions entries removed). If it still
  reports a *lowered* count, regenerate with `UPDATE_SNAPSHOT=1`; if it reports a *grown* count, inject the dependency
  instead.
Anything needing a change of authorization semantics, a public signature or the design → do NOT improvise:
`plan.py log $PL P4.3 --type blocker --summary "…" --actor …` and stop with a report.
Every fix: one line `plan.py log $PL P4.3 --type difficulty --summary "…" --actor …`. Re-run only the checks whose
inputs your fix touched.

## 3. Negative controls (V80 part)

Execute NC1–NC5 from `$A/negative-controls.md` exactly: break → run only the named test → record the red output line
→ restore the exact bytes (`git diff -- <file>` back to the pre-control state) → rerun green. Fill the table.

## 4. Leftover docs (same item, small)

The English pages are updated; finish the mirrors and the two handler pages, same meaning, no new claims:
- `docs/ru/guide/architecture.md` (vaulter-documents row and the VaulterManager list — mirror
  `docs/guide/architecture.md`), `docs/ru/guide/recipes.md` (drop the `TipTapHandler::class` line, as in the en page),
  `docs/ru/reference/facade.md` (mirror `docs/reference/facade.md`: no `registerDocumentType`/`documentTypes`;
  types are `Documents::types()`), `docs/ru/reference/configuration.md` (mirror the en "Document handlers" and
  "Documents — vaulter-documents.types…" sections and drop `features.spreadsheets` from the features table).
- `docs/guide/document-handlers.md` + `docs/ru/guide/document-handlers.md` and `docs/guide/writing-an-engine.md` +
  ru mirror: remove what no longer exists — `TipTapHandler`, `UniverHandler`, the `DocumentType` enum,
  `#[TiptapExtensions]`, `richText`/`rich_text`, the `spreadsheets` flag — and say instead that file handlers serve
  **files** only, document nodes are presented by their [document type](./document-types.md), and an office/markdown
  file is preview/download until a file → document import exists. Keep everything else.
Find leftovers with:
`grep -rn -i "tiptaphandler\|univerhandler\|registerDocumentType\|richText\|rich_text\|TiptapExtensions\|Engines\\\\Handlers\|enabled_document_types\|features.spreadsheets" docs README.md packages/*/README.md`
(the migration notes in `docs/{,ru/}guide/configuration.md`, `docs/{,ru/}reference/configuration.md`,
`docs/{,ru/}guide/document-types.md` and UPGRADE/CHANGELOG mention the legacy keys on purpose).

## 5. Finish and commit

Append results (commands, exit codes, test counts, fixes, NC table done) to `$A/completion-notes.md` under
"Check session results". Then:

```bash
plan.py finish $PL P4.3 --status done_with_deviations \
  --check V1=0 --check V2=0 --check V3=0 --check V80=0 --check V81=0 \
  --deviation "See P4.3 log: core DocumentContent kept as parent until P4.4, create(?type) uses default_type, export reads content via type, presentation names kept (id/ui) + kind presenter tag, service-locator baseline moved, HTTP type field, files beyond task_files" \
  --notes "Document type registry (config/tag/provider, frozen, vendor/name, vaulter/ reserved), built-in rich-text/markdown/spreadsheet, TipTapCodec with lossless read, unknown-type fallback (V10), degraded content read-only, documents presented by type (V34), generator + contract tests" \
  --actor anthropic/claude-sonnet-5-5/medium
plan.py commit $PL P4.3 --role executor --actor anthropic/claude-sonnet-5-5/medium --leave-outside --dry-run
```
In the dry run every P4.3 path of `git status` must be selected; add each missing one with `--path` (expected extras:
`packages/core/config/vaulter.php`, the core src comment-only files, the core tests listed in the log, documents
`src/{Http,Models,Actions,Collaboration,Data,Export,Support,Facades,Exceptions}` files, the staged renames/deletions
(`git status` shows them as `R`/`D` — include both sides), `packages/documents/tests/**` files outside the task glob,
`docs/.vitepress/config.ts`, `docs/**` pages, both goldens, `$A/**`). Leave `.swissknife/analyst/**`,
`plans/…/views/**` and `packages/documents/.claude/` out. Then run the same command without `--dry-run`, report the
printed sha, then `plan.py next $PL`.

Final report: checks with counts, fixes made (one line each), anything left for the owner, the sha, the `next` line.
Hard risk of this item: public API + data integrity (content never saved lossy) → recommend a `task:review` pass to the
owner in the final report; do not run it yourself.
