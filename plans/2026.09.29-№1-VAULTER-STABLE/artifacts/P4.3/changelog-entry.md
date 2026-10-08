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

