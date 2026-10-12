# CHANGELOG [Unreleased] entry (applied to CHANGELOG.md in this item)

### Document export by type and format (PLAN2.D28, D34–D37; PLAN2.P4.4)

- Added `Documents::exporters()` as `Export\DocumentExporterRegistry` (`register`, `replace`, `for`,
  `formatsFor`, `all`), resolving by (document type, format), filled from
  `vaulter-documents.exporters` (now a list of classes; the old map is read with a deprecation), the
  tag `vaulter.exporters` and providers, frozen after boot; two exporters for one pair throw
  `DuplicateDocumentExporterException`.
- Added `Contracts\DocumentExporter` (`key`, `format`, `mimeType`, `extension`, `documentTypes`,
  `export(DocumentContent, DocumentType)`), `Actions\Export\ExportDocumentAction`,
  `Data\Export\ExportDocumentInput` and `vaulter:make:exporter`.
- Changed `DocumentExport` to `bytes`, `filename`, `mimeType`, `format`, `sourceEpoch`,
  `sourceStateVersion`, `sourceRevisionId`; the export renders the working state or one chosen
  revision and never creates a revision. `DocumentOperations::export()` needs Download and content
  egress, an installed type and a format the type supports (`422 unknown_export_format` with
  `allowed`).
- Changed `GET nodes/{node}/export` to answer the file (`Content-Disposition: attachment` with
  `filename*`, `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`) instead of JSON;
  `@vaulter/vue` `documentNodes.export()` resolves `{ blob, filename, mimeType }`.
- Removed core `Contracts\Handlers\DocumentExporter` and `Contracts\Handlers\DocumentContent`,
  `Export\NodeExportService`, `Enums\Export\ExportFormat`, `Registries\DocumentExporterRegistry`
  and `TipTapRenderer::render()`.

