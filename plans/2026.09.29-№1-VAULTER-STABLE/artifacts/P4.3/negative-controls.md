# P4.3 — negative controls (V80)

Each control: apply the temporary break → run only the named test → record the red line →
restore the exact bytes (`git diff -- <file>` empty against the pre-control state) → rerun green.
Run from the package's cwd with `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:`.

| # | Guard | Temporary break | Test (cwd) | Red output (fill in) | Restored + green |
|---|---|---|---|---|---|
| NC1 | registry freeze | `packages/documents/src/Types/DocumentTypeRegistry.php`: comment out the `$this->assertOpen();` line in `register()` | `../../scripts/php84 vendor/bin/pest --ci tests/Types/DocumentTypeRegistryTest.php` (packages/documents) | | |
| NC2 | reserved `vaulter/` prefix | same file: in `instance()` replace `!self::firstParty($type::class)` with `false` | `tests/Types/DocumentTypeRegistryTest.php` (dataset "reserved prefix") | | |
| NC3 | degraded content is not saved back (P4-I16) | `packages/documents/src/Operations/DocumentOperations.php`: in `editableType()` comment out the `if (!$this->contentWritable($node)) { … }` block | `tests/Types/DegradedContentTest.php` | | |
| NC4 | core names no editor | `packages/core/src/Handlers/NodePresenter.php`: add a comment line `// TipTap` | `scripts/php84 vendor/bin/pest --ci packages/core/tests/Arch/ArchTest.php --filter="never names a document editor"` (repo root) | | |
| NC5 | documents presented by type, not file handler (V34) | `packages/core/src/Handlers/NodePresenter.php`: comment out the `foreach ($this->container->tagged('vaulter.kind_presenters') …)` block | `tests/Document/NodeHandlerDescriptorTest.php` (packages/documents) | | |
