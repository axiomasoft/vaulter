# P4.4 — completion notes

Executor: anthropic/claude-sonnet-5-5/medium. Review: none (D51).

## Result
- `Contracts\DocumentExporter` (06 §4), `Export\DocumentExporterRegistry` (register/replace/for/formatsFor/all, Duplicate on key or (type, format) pair, freeze after boot, tag `vaulter.exporters`), `Export\{Html,Markdown}Exporter` with `key/documentTypes`.
- `Actions\Export\ExportDocumentAction` + `Data\Export\ExportDocumentInput`; `ValueObjects\DocumentExport` = bytes/filename/mimeType/format/sourceEpoch/sourceStateVersion/sourceRevisionId.
- `DocumentOperations::export()` — Download + ContentEgress, installed type, supported format (422 `unknown_export_format` + `allowed`).
- HTTP returns the file (`Content-Disposition` with `filename*`, `private, no-store`, `nosniff`); Vue `documentNodes.export()` reads a blob.
- Removed: core `DocumentExporter` + `DocumentContent`, `NodeExportService`, `ExportFormat`, old registry, `TipTapRenderer::render()`. Added `vaulter:make:exporter`.
- Docs en/ru, UPGRADE, CHANGELOG, golden `public-api.txt` (documents and core) updated.

## Deviations / assumptions
- Checkpoints store no epoch, so for a chosen revision `sourceEpoch` is the document's current `collab_epoch`; `sourceStateVersion` and `sourceRevisionId` are the revision's own.
- The Vue method keeps its existing name `documentNodes.export()` (the plan names it `exportDocument()`); `useDocumentNode().exportAs()` follows.
- Core test files naming removed classes were edited (FakeExporter fixture, VaulterManagerExporterHandlerSeamTest, EgressInventoryTest, P83DocumentsMetricsTest, ArchTest finality line, service-locator and public-api goldens): required by the core deletions listed in scope.
- Legacy `format => class` exporter maps are read with a deprecation; a host class overriding a built-in format now collides at boot (documented in UPGRADE).
- Authorization stays in `DocumentOperations` (as for every other operation); the action is a read-only evaluator.

## Self-check (P4 invariants)
Export is read-only (test: revision count, state_version, epoch, checkpoint pointers unchanged); source follows the chosen revision or the working state without a hidden checkpoint; error messages list only formats allowed for the document's own type; exporters cannot import storage/blob/HTTP (Arch + NC6).

## Hard risk
Content egress + public contract: recommend `task:review` on P4.4.
