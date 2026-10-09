# CHANGELOG [Unreleased] entry (applied to CHANGELOG.md in this item)

### Document type registry and safe fallback (PLAN2.D14, D15, D34, D44; PLAN2.P4.3)

- Added the document type registry `Documents::types()` (`Types\DocumentTypeRegistry`: `register`,
  `replace`, `has`, `get`, `find`, `all` by key), filled from `vaulter-documents.types`, the tag
  `vaulter.document_types` and providers, frozen after boot; contracts `DocumentType`,
  `DocumentCodec`, `DocumentContent`, `SupportsCollaboration`, `UpgradesSchema`; built-in
  `RichTextDocumentType`, `MarkdownDocumentType` (closed Markdown schema) and
  `SpreadsheetDocumentType`; `TipTapCodec`, `TipTapSchema`, `OpaqueJsonCodec`;
  `vaulter-documents.default_type`; `vaulter:make:document-type`; `DocumentTypeContractTests`.
- Added `DocumentTypeUnavailableException` (`document_type_unavailable`, 409),
  `DocumentContentDegradedException` (`document_content_degraded`, 409),
  `DuplicateDocumentTypeException` (`duplicate_registration`) and core
  `RegistryFrozenException` (`registry_frozen`).
- Added core `Contracts\Presentation\KindPresenter`; documents are presented by their type
  (`document_type`, `type_available`, the type's viewer; `handler.id` is `null`).
- Changed: a document whose type is not installed reads as opaque JSON and refuses edits, exports
  and collaboration; content read with parts dropped is read-only (was: silently saved without
  them). Older schema versions are upgraded on read.
- Changed `DocumentOperations::create()` to take `?string $type` (default from config) and decode
  through the type; `CreateDocumentInput` takes `type`/`schemaVersion`; profiles name type keys.
- Moved `Content\*` classes (was `ValueObjects\*`, `Support\*`) and the export exceptions from
  core to documents.
- Removed `Enums\Documents\DocumentType`, `TipTapHandler`, `UniverHandler`, `TiptapExtensions`,
  `HasTiptapExtensions`, `Registries\DocumentTypeRegistry`, `DocumentsConfig::enabledDocumentTypes()`
  and `spreadsheetsEnabled()`, `ContentCapabilities::$richText`; `.docx`/`.md`/`.xlsx` files are no
  longer declared editable. `vaulter.enabled_document_types` and `vaulter.features.spreadsheets` are
  deprecated (normalized into `vaulter-documents.types`).

#### Round 2 (Grok 4.7 review) — not yet in CHANGELOG.md, for the owner to transfer

- Fixed: the collaboration hub could write over content the installation cannot save back.
  `collab/authenticate` answers `readOnly: true` and `collab/store` answers 409
  (`document_type_unavailable` / `document_content_degraded`, checked on the locked row) for an
  uninstalled type, a newer schema or a degraded read; neither Yjs nor JSON changes.
- Fixed: a Yjs-only `collab/store` no longer resets `schema_version` to 1; a projection is
  decoded by the document's type (its schema, codec and size limit) and written in its schema
  version. A projection the type rejects answers 422 (was: ignored unless a TipTap doc).
- Fixed: the autosave re-checks editability on the locked row, so a racing restore of degraded
  content is not overwritten.
- Changed: TipTap JSON is one shape for write and read. `TipTapContent::fromArray()` rejects
  fields a node does not carry, non-object `attrs` and non-string `text`
  (`InvalidDocumentContentException::malformedNode()`, reason `invalid_tiptap`), and checks marks
  on every node; the lenient read keeps marks on any node and reports the rest as lossy.
- Added: `PATCH nodes/{node}/content` takes an optional `state_version` (412
  `document_state_mismatch` on a lost update); `DocumentRevisionResource` carries `state_version`.
- Fixed: an item of the `vaulter.document_types` tag that is neither a type nor a class name fails
  boot with `InvalidConfigurationException` (was: skipped).

