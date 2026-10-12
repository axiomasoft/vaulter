# P4.3 — negative controls (V80)

Each control: apply the temporary break → run only the named test → record the red line →
restore the exact bytes (`git diff -- <file>` empty against the pre-control state) → rerun green.
Run from the package's cwd with `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:`.

| # | Guard | Temporary break | Test (cwd) | Red output (fill in) | Restored + green |
|---|---|---|---|---|---|
| NC1 | registry freeze | `packages/documents/src/Types/DocumentTypeRegistry.php`: comment out the `$this->assertOpen();` line in `register()` | `../../scripts/php84 vendor/bin/pest --ci tests/Types/DocumentTypeRegistryTest.php` (packages/documents) | `RegistryFrozenException` not thrown (DocumentTypeRegistryTest: boot-freeze test) — 1 failed, 12 passed | bytes restored (md5), 13 passed |
| NC2 | reserved `vaulter/` prefix | same file: in `instance()` replace `!self::firstParty($type::class)` with `false` | `tests/Types/DocumentTypeRegistryTest.php` (dataset "reserved prefix") | `InvalidConfigurationException` not thrown for `vaulter/` key (reserved-prefix dataset) — 1 failed, 12 passed | bytes restored (md5), 13 passed |
| NC3 | degraded content is not saved back (P4-I16) | `packages/documents/src/Operations/DocumentOperations.php`: in `editableType()` comment out the `if (!$this->contentWritable($node)) { … }` block | `tests/Types/DegradedContentTest.php` | `DocumentContentDegradedException` not thrown; HTTP autosave 200 instead of 409 — 3 failed, 1 passed | bytes restored (md5), 4 passed |
| NC4 | core names no editor | `packages/core/src/Handlers/NodePresenter.php`: add a comment line `// TipTap` | `scripts/php84 vendor/bin/pest --ci packages/core/tests/Arch/ArchTest.php --filter="never names a document editor"` (repo root) | ArchTest "never names a document editor": NodePresenter.php listed as offender — 1 failed | bytes restored (md5), 1 passed |
| NC5 | documents presented by type, not file handler (V34) | `packages/core/src/Handlers/NodePresenter.php`: comment out the `foreach ($this->container->tagged('vaulter.kind_presenters') …)` block | `tests/Document/NodeHandlerDescriptorTest.php` (packages/documents) | `'vaulter/download'` is not null: document presented by file handler — 2 failed, 5 passed | bytes restored (md5), 7 passed |

## Round 2 — Grok 4.7 review fixes (2026-10-09)

Applied and restored by a script (anchor replaced once, original bytes written back, md5 checked).
Receipts: `artifacts/P4-midaudit/receipts/NC<n>-{red,green}-r2.txt` (command, break, full output).

| # | Finding | Temporary break | Test (cwd packages/documents) | Red output | Restored + green |
|---|---|---|---|---|---|
| NC6 | 1: collab bypasses the editability guard | `src/Content/ContentDecoder.php` `editable()`: drop the schema and lossless conditions (`return $type !== null;`) | `tests/Collaboration/CollaborationWebhookTest.php` | degraded + newer-schema: authenticate `readOnly` false, store 200 instead of 409 — 4 failed, 26 passed (uninstalled type stays refused by `writable()`) | md5 equal, 30 passed |
| NC7 | 2: collab store resets `schema_version` | `src/Actions/Collaboration/PersistCollaborationStateAction.php`: `schemaVersion: 1` (the old default) | same file `--filter="schema version"` | `Failed asserting that 1 is identical to 2` — 2 failed | md5 equal, 2 passed |
| NC8 | 3: lenient read hides dropped fields | `src/Content/TipTap/TipTapContent.php` `decayNode()`: the unknown-field check → `if (false)` | `tests/Unit/TipTapContentTest.php tests/Types/DegradedContentTest.php` | datasets "unknown root field", "unknown node field", "content on a text node" read lossless — 3 failed, 70 passed | md5 equal, 73 passed |
| NC9 | 6: update/restore race | `src/Actions/Documents/UpdateDocumentContentAction.php`: `editableType($document)` → `writable($document->type)` | `tests/Types/DegradedContentTest.php --filter="racing restore"` | `DocumentContentDegradedException` not thrown — 1 failed | md5 equal, 1 passed |
| NC10 | 8: size limit for every type | `src/Content/ContentDecoder.php` `decode()`: global fallback `?? 0` (no limit) | `tests/Types/ContentSizeLimitTest.php` | spreadsheet over the global limit accepted — 2 failed, 2 passed (codec-limit cases stay green) | md5 equal, 4 passed |
