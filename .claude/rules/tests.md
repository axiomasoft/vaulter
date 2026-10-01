---
paths:
  - "**/tests/**"
---
<!-- swissknifeman:generated
Generated-by: harness agent-packs
Pack: laravel-package@0.2.3
Template: rules/tests.md
Edit-policy: generated-core — DO NOT edit; expand through extension-point
Extension-points: .claude/rules/vaulter-reviewer-overrides.md
Upgrade: harness agent-packs upgrade --pack laravel-package --target .
-->

When working with package tests (depth - skill `php-packages:laravel-package-testing`):

- Run only `composer test` (project host specifics), not bare `php`.
- Project test framework - `pest`: read 1-2 existing tests and mirror their style.
- Infra - Orchestra Testbench: tests inherit batch `TestCase` with `getPackageProviders()`;
  DB - in-memory sqlite (`:memory:`), without external environment.
- Batch test is application independent: none `use App\...` — stubs/models for
  tests live in `tests/` (Workbench/fixtures).
- Matrix sensitive areas (`version_compare`-branches, version checks `illuminate/*`) —
  cases for BOTH branches: CI-matrix PHP×Laravel will execute both, the local run will execute one.
- For changed behavior - test; existing checks should not be weakened.
- **Test fairness:** any workaround/force for the sake of green (force `config(...)`, manual
  registering a binding instead of a provider, sibling-class bypass `final`) — mark
  `[POSSIBLE-DEFECT]` in the report: there is almost always a real bug behind the workaround.
