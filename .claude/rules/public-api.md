---
paths:
  - "**/src/Contracts/**"
  - "**/src/**/Facades/**"
---
<!-- swissknifeman:generated
Generated-by: harness agent-packs
Pack: laravel-package@0.2.3
Template: rules/public-api.md
Edit-policy: generated-core — DO NOT edit; expand through extension-point
Extension-points: .claude/rules/vaulter-reviewer-overrides.md
Upgrade: harness agent-packs upgrade --pack laravel-package --target .
-->

Public contract of the package (depth - skill `php-packages:laravel-package-compatibility`).
Risk-layer: **BC/SemVer** — is a promise to other people's consumers, not internal code.

- Contracts are stable: interface in `Contracts/` and facade - public API. Removal/
  method renaming, narrowing parameter types - breaking → **MAJOR** + upgrade-note.
- New `public` — conscious SemVer-obligation: first ask if you can leave
  internal.
- Internal mark `@internal` (+`final` for classes) — this takes it out BC-promises.
- Facade - above the binding container (`getFacadeAccessor` → contract), not over a specific class.
