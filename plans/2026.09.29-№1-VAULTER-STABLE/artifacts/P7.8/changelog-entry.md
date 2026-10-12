### README, glossary, runbooks and security docs (PLAN2.P7.8)

- Changed: `README.md` rewritten — requirements, three-command install, a tested quick example (`packages/drive/tests/Recipes/ReadmeRecipeTest.php`), package table and documentation links.
- Added: `concepts/glossary`, `extending/{exporters,upload-inspectors,profiles}`, `guides/operations` with eight runbooks (scan backlog, scan failures, scan policy cut-over, webhook redelivery, direct URL limits, dedup preflight, drives purge/recount, scheduler and locks) and `security/{threat-model,hardening-checklist}`, in English and Russian.
- Changed: `scripts/check-doc-snippets.php` also accepts a single file (`README.md`).
- Not added: a 0.2→0.3 upgrade guide — 0.2.0 was never published, so there is nobody to upgrade.
