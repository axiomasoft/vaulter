---
paths:
  - "**/database/migrations/**"
---
<!-- swissknifeman:generated
Generated-by: harness agent-packs
Pack: laravel-package@0.2.3
Template: rules/package-migrations.md
Edit-policy: generated-core — DO NOT edit; expand through extension-point
Extension-points: .claude/rules/vaulter-reviewer-overrides.md
Upgrade: harness agent-packs upgrade --pack laravel-package --target .
-->

Package migrations (depth - skill `php-packages:laravel-packages`). Risk-layer:
**consumer schema** — migration is performed in someone else's database, there is no response.

- Distribution - `loadMigrationsFrom` in `boot()` and/or publishable (`publishes` with tag):
  consumer selects whether to run from vendor or publish to yourself.
- Only anonymous-class migrations (`return new class extends Migration`) — named
  class collides with the consumer migration of the same name.
- `down()` is correct and reversible.
- Already applied (released) do not change the migration - the structure is being edited NEW
  migration: consumers have already completed the old version.
