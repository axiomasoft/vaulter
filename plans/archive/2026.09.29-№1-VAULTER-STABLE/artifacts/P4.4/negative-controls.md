# P4.4 — negative controls (V80)

Each control: temporary break → run only the named test → red line → exact restore (file moved back
from a byte copy) → rerun green. Cwd `packages/documents`, `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:`.
Full output: `artifacts/P4.4/negative-controls-output.txt`.

| # | Guard | Temporary break | Test | Red output | Restored + green |
|---|---|---|---|---|---|
| NC1 | one exporter per (type, format) | `src/Export/DocumentExporterRegistry.php` `assertNoPairCollision()`: skip condition → `if (true)` | `tests/Export/DocumentExporterRegistryTest.php` | `DuplicateDocumentExporterException` not thrown — 1 failed, 5 passed | 6 passed |
| NC2 | export needs Download | `src/Operations/DocumentOperations.php` `export()`: `Ability::Download` → `Ability::View` | `tests/Export/DocumentExportAuthorizationTest.php` | `PermissionDeniedException` not thrown (viewer) — 1 failed, 5 passed | 6 passed |
| NC3 | never cached | `src/Http/Controllers/Documents/DocumentNodeController.php`: `private, no-store` → `private` | `tests/Export/DocumentExportHttpTest.php` | attachment/headers test red — 1 failed, 2 passed | 3 passed |
| NC4 | source follows the chosen revision | `src/Actions/Export/ExportDocumentAction.php`: `$revision ?? $document` → `$document` | `tests/Export/DocumentExportSourceTest.php` | checkpoint export returns working state — 1 failed, 3 passed | 4 passed |
| NC5 | format only for types an exporter lists | `src/Export/DocumentExporterRegistry.php` `for()`: drop the `documentTypes()` condition | `tests/Export/DocumentExportAuthorizationTest.php` | spreadsheet reaches the exporter (`UnsupportedDocumentContentException`) instead of `UnknownExportFormatException` — 1 failed, 5 passed | 6 passed |
| NC6 | exporters never import storage | `src/Export/HtmlExporter.php`: add `use Illuminate\Support\Facades\Storage;` | `tests/Arch/ExportBoundaryTest.php` | `Expecting 'Vaulter\Documents\Export' not to use 'Illuminate\Support\Facades\Storage'` — 1 failed, 2 passed | 3 passed |
