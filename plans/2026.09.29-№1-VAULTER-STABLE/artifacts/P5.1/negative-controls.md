# P5.1 — negative controls (V80)

Runtime: anthropic/claude-opus-5-5/high, SQLite `:memory:`, `scripts/php84 vendor/bin/pest` (cwd repo root), 2026-10-09.
Each control: copy of the exact file → one injected fault → focused run (red) → copy restored → sha256 compared → same run (green).

## (a) outbox sink wrapped in try/catch → EventRecorderTest fault injection red

- File: `packages/core/src/Events/EventRecorder.php`, sha256 before = after = `afcf15b792b81b9ec7cbfff9081644dd00eb977a9e559d5ad46ee6c91bb284fd`.
- Fault: the outbox sink closure became
  `function () use ($event): bool { try { return …->stage(…); } catch (\Throwable) { return true; } }`.
- Command: `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest packages/core/tests/Events/EventRecorderTest.php --filter="rolls the change back when the outbox write fails"`
- Red (exit 1):
  `{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"failed":1,"failures":[{"test":"…EventRecorderTest::…it_rolls_the_change_back_when_the_outbox_write_fails","line":111,"message":"Exception \"RuntimeException\" not thrown."}]}`
- Restored: identical sha256 (yes). Green (exit 0): `{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":5}`.

## (b) `implements ShouldDispatchAfterCommit` on one event → EventPipelineArchTest red

- File: `packages/drive/src/Events/Nodes/NodeRenamed.php`, sha256 before = after = `31670cd8f8817a13976dd11bf6c20398c18ec3030ee1d6187c40d9436eadcf07`.
- Fault: `final readonly class NodeRenamed extends DomainEvent implements \Illuminate\Contracts\Events\ShouldDispatchAfterCommit`.
- First attempt found a test defect: the arch rule `expect('Vaulter')` only walks the core PSR-4
  root (`vendor/axioma-studio/vaulter-core/src`), so the drive class was not seen and the run stayed
  green. Fixed in the same item: the rule now names `Vaulter`, `Vaulter\Drive`, `Vaulter\Documents`,
  `Vaulter\Media`, and a token scan over every `packages/*/src` (comments excluded) forbids the
  marker in packages that are not autoloaded at the root (azgard, filament).
- Command: `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest packages/core/tests/Events/EventPipelineArchTest.php`
- Red (exit 1), both guards:
  `"failed":2` — `…no_Vaulter_class_defers_its_own_dispatch_past_the_commit` → "Expecting 'packages/drive/src/Events/Nodes/NodeRenamed.php' not to implement 'Illuminate\Contracts\Events\ShouldDispatchAfterCommit'."; `…it_names_ShouldDispatchAfterCommit_nowhere_in_package_code` → `+ 0 => 'drive/src/Events/Nodes/NodeRenamed.php'`.
- Restored: identical sha256 (yes). Green (exit 0): `{"tool":"pest","result":"passed","tests":7,"passed":7,"assertions":7}`.
