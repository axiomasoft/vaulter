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
