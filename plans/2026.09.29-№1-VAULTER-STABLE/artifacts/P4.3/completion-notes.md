# P4.3 — completion notes (draft by the Opus executor; the closing Sonnet session completes the check results)

## What changed

- **Types registry** `Vaulter\Documents\Types\DocumentTypeRegistry` (register/replace/has/get/find/all by key, key regex,
  reserved `vaulter/`, Duplicate, frozen on booted → core `RegistryFrozenException`); sources: `vaulter-documents.types`,
  tag `vaulter.document_types`, provider `register()`; `default_type` checked once every provider booted.
- **Contracts** `Documents\Contracts\{DocumentType,DocumentCodec,DocumentContent,SupportsCollaboration,UpgradesSchema}` (OPUS 06 §3).
- **Built-ins** `RichTextDocumentType` (TipTapCodec rich-text schema, Yjs), `MarkdownDocumentType` (closed markdown schema, Yjs),
  `SpreadsheetDocumentType` (OpaqueJsonCodec, opt-in), `UnknownDocumentType` (never registered, `vaulter/unsupported`).
- **Content** moved to `Content\*` / `Content\TipTap\*`; new `TipTapSchema`, `TipTapCodec` (strict decode + `decodeForRead()` with
  lossless flag), `OpaqueJsonCodec`, `ReadContent`, internal `ContentDecoder` (decode with size limit, read with upgrade-on-read,
  fallback, loss report).
- **Operations** create by registry + profile (type keys) + codec; update/snapshot/restore/export/collab refuse an uninstalled type
  (409 `document_type_unavailable`); update refuses degraded/newer-schema content (409 `document_content_degraded`); collab session
  is read-only for such content; content/revisions/copy/trash/purge work for an uninstalled type.
- **Presentation** core `Contracts\Presentation\KindPresenter` + tag `vaulter.kind_presenters`; `Documents\Presentation\DocumentPresenter`;
  `NodePresentation{id?, capabilities, ui, documentType, typeAvailable}`; `ContentCapabilities::richText` removed; `.docx/.md/.xlsx`
  files are preview/download only (V34). `DocumentHeadSnapshot.typeAvailable` from the registry (D44).
- **Removed** `Enums\Documents\DocumentType`, `TipTapHandler`, `UniverHandler`, `TiptapExtensions`, `HasTiptapExtensions`,
  `Registries\DocumentTypeRegistry`, `DocumentsConfig::enabledDocumentTypes()/spreadsheetsEnabled()`; legacy keys normalized in core
  `ConfigNormalizer` (strings only); core Arch guard: no TipTap/Univer in core/src.
- **Generator** `vaulter:make:document-type` + stub; **contract trait** `Testing\Contracts\DocumentTypeContractTests` (built-ins and a
  third-party type run through it).
- **Docs** guide/document-types (+ru), reference/configuration, guide/{configuration,errors,documents,architecture,recipes} (+ru where
  done), facade reference, UPGRADE.md, CHANGELOG.md (+ changelog-entry.md).

## Invariant self-check (P4-invariants)

- P4-I1 kind=document ⇔ one aggregate: CreateDocumentAction unchanged in shape (node + aggregate + checkpoint in one transaction);
  only type/schema_version now come from the input.
- P4-I4 single writer: all writes still go through DocumentStateWriter; create/update pass the type's schema version.
- P4-I8 sequence untouched: no new sequence calls.
- P4-I13 copy: lifecycle copies the payload verbatim (OpaqueContent), also for an uninstalled type (V10 test).
- P4-I15 export: Download + ContentEgress unchanged; type availability added before delegation.
- P4-I16 degraded content not editable: snapshot.editable=false, update 409, collab ro (DegradedContentTest).
- Interfering pairs: config change (extension switched off) ‖ update — the degraded check reads outside the D lock; a racing
  write is itself strictly validated, so it can only make content lossless, never silently lossy.

## Checks

To be filled by the closing session (V1, V2, V3, V80 incl. negative controls, V81) with receipts in `receipts/`.

## Post-audit session (Opus 5.5/high, 2026-10-08)

Receipts in `artifacts/P4-midaudit/receipts/` (head b68d2d63 + uncommitted edits below):

| Check | Result |
|---|---|
| V82 `tests/Providers/InvalidDefaultDocumentTypeTest.php` (new) | 4 passed — fresh-app boot refuses a missing default_type, accepts config and provider-boot registration on either side |
| G1 goldens (core, documents) regenerated | diff = expected surface only (additions; removed enabledDocumentTypes/spreadsheetsEnabled, old ctor/create) |
| C1 core Arch/Support/WriteGuard | 166/167 → fixed DocsDriftGuard (errors.md anchor `invalid-document-content`, en+ru) → rerun green |
| C1 drive Arch/WriteGuard | 23 passed |
| C1 documents Arch | 1 failure (host-App guard vs MakeDocumentTypeCommand) → allowlisted like core MakeProfileCommand → 23 passed |
| C2 drive admission + PublicApi | 145 passed |
| V1 documents Types/Document/Unit/Operations | 420 passed, 3 skipped (PG/pcntl ForkRace ×2, SQLite FK cascade), 1 failed (LegacyNodeSequenceTest used the removed array registry API) → rewritten; file rerun 4 passed. **Full V1 rerun pending** |
| V2 core Configuration/Profiles | 348 passed |
| V3 filament Feature | 95 passed, 1 skipped (Filament 5 + Livewire page render, pre-existing) |

Also done: en/ru doc leftovers (document-handlers, writing-an-engine, architecture, recipes, facade, reference/configuration);
D55 (P4.3 executable contract, EV320–EV329) accepted; D56 (AzGuard v1, no legacy) accepted by the owner; new item P4.14
(port vaulter-azgard to AzGuard v1), P4.6 depends on it. The AzGuard lane is no longer part of P4.3 closing.

## Check session results (Sonnet 5.5/medium, 2026-10-08)

Receipts: `artifacts/P4-midaudit/receipts/` (head b68d2d63 + uncommitted edits).

| Check | Result |
|---|---|
| V1 documents Types/Document/Unit/Operations/Arch | exit 0 — 444 passed, 3 skipped |
| V1x documents Feature/Profiles/Providers/Configuration/Collaboration/Comment/Schema | exit 0 — 70 passed |
| V2 core Configuration/Profiles/Arch | exit 0 — 440 passed |
| V2x core Feature/Unit/Content/ObservabilityDocuments/E2E/Providers/Support | exit 0 — 247 passed |
| V3 filament Feature | exit 0 — 95 passed, 1 skipped |
| C3 guard drive | exit 0 |
| C3 guard documents | exit 0 — 164 passed |
| C3 guard media | exit 0 — 11 passed, 1 skipped (PG) |
| V80 core / documents / drive / filament / media | pint + PHPStan exit 0 |
| V81 | **FAIL** — see blocker below |

Fixes made in the session (all small):
- `ConfigNormalizer::normalizeDocumentTypes`: filter `$types` to strings (PHPStan array_unique, 2 errors).
- `OpaqueJsonCodec::decode`: `@param array<array-key, mixed>` + explicit string-key object (JSON `{"1":…}` has int keys); impossible `array_is_list` finding.
- `VaulterDocumentsServiceProvider::bootDocumentTypes`: tagged items narrowed to `DocumentType|string` before `register()`.
- `packages/documents/phpstan.neon`: scoped `trait.unused` ignore for the shipped `DocumentTypeContractTests` trait.
- `DegradedContentTest`: direct `Document` update wrapped in `VaulterDatabase::write` (guard lane).
- media `CasTestHelpers`/`BlobGcBatchTest`: fixture writes go through `CasTestHelpers::write` (guard lane was red before: seeding wrote outside an operation; production code untouched).

### V81 blocker
`opus-coverage.py check` → `P4.14: нет дословного входа knowledge/artifacts-P4-design-P4.14-opus.json`
(1 violation). The new item P4.14 (D56) has no render input; this is plan authoring, not P4.3 code, so it was not
fabricated here.

### Negative controls
NC1–NC5: see `negative-controls.md`. A-NC:

| ID | Break | Actual RED | Restored + green |
|---|---|---|---|
| A-NC1 | drop `$query->writeScope = …` | WriteOutsideOperationException not thrown (WriteScopeTest) | bytes restored; green |
| A-NC2 | drop `assertFileSize` in CopyNodeAction | FileSizeLimitExceededException not thrown (1 failed) | bytes restored; 1 passed |
| A-NC3 | drop generated-name policy check | InvalidNodeNameException not thrown (1 failed) | bytes restored; 1 passed |
| A-NC4 | `NodeSnapshot::$sizeBytes` → `?int` | golden diff `-int $sizeBytes / +?int $sizeBytes` | bytes restored; green |

V81 closed (Opus 5.5/high, 2026-10-08): D57 hands the AzGuard-bridge OPUS units to P4.14 in `ledger-P4.tsv` (0.x read as AzGuard v1), `knowledge/artifacts-P4-design-P4.14-opus` rendered; `opus-coverage.py check` and `plan.py check` exit 0 (`receipts/V81.txt`).
