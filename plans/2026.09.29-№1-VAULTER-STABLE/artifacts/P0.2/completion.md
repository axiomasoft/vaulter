# P0.2 — Completion Notes

Run `2fdec7bc33a15282a4fba93bc5e1ac0fe66a4da6c3de50e2898229204b46e49f`, route claude-opus-5-5/high (frontier/high ≥ implementation/medium, `over`).
PG: PostgreSQL 16.15, `127.0.0.1:5432/vaulter_test`; PHP 8.4 via `scripts/php84`.

| Validation | Command | Result | Evidence |
|:--|:--|:--|:--|
| PG harness | `DB_CONNECTION=pgsql … scripts/php84 vendor/bin/pest packages/core/tests/Concurrency` | 4 passed, 0 skipped, exit 0 | `pg-flake-1.log` |
| Flake ×5 | same command, 5 consecutive runs | 5/5 exit 0, 4 passed each (3.0–5.0 s) | `pg-flake-{1..5}.log` |
| SQLite | `scripts/php84 vendor/bin/pest packages/core/tests/Concurrency` | 4 skipped, exit 0 | `sqlite.log` |
| Negative control (harness) | barrier removed + parent `pcntl_waitpid` right after each fork (sequential run) | lost-update test RED: child values `[0, 1]` instead of `[0, 0]` (final would be 2, not 1); exit 1; patch reverted (byte-identical) | `negative-control-sequential.{diff,log}` |
| Pint | `scripts/php84 vendor/bin/pint --test packages/core/tests/Concurrency tests/Pest.php` | exit 0 (after `phpdoc_align` fix) | `pint.log` |
| Media autoload | `scripts/php84 composer --working-dir=packages/media dump-autoload`; `class_exists('Vaulter\Tests\Concurrency\ForkRace')` | exit 0; exit 0 | `media-dump.log` |
| Root PG lane vs baseline | every `tests.yml:test-pgsql` step locally, preflight first | 9/9 steps exit 0; base suite 391/0/4 = baseline 391/0/4; ULID 3, bigint 12, id-ULID 5, UUIDv7 4, prefix 2, activity-gate 1, Spatie 9 — all passed; new harness step 4 passed | `root-pg-preflight.log`, `root-pg-{1..9}.log` |
| New CI step, exact command | `DB_CONNECTION=pgsql … scripts/php84 vendor/bin/pest --ci packages/core/tests/Concurrency` | 4 passed, 0 skipped, exit 0 | `ci-step.cmd`, `ci-step.log` |
| CI step path negative control | temporary `it('CI path negative control', fn () => expect(false)->toBeTrue());` | exit 1 (5 tests, 1 failed); removed (byte-identical), rerun 4 passed exit 0 | `ci-step-negative.log`, `ci-step.log` |
| Workflow YAML | `yaml.safe_load` of tests/drive/media/documents.yml | parses | — |

CI: `pcntl, posix` added to `tests.yml:test-pgsql`, `media.yml:media-pgsql`, `documents.yml:documents-pgsql`
(`drive.yml:drive-pgsql` already had them). New step `Postgres: concurrency harness` (`if: always()`) after the base suite.

Not covered by this item (not claimed GREEN here):
- «Example passes in CI» (OPUS 13 P0.2) — hosted run through O-3-CI before the P0 phase audit (D07); the owner has
  not authorised hosted CI (handoff after P0.1). Phase-level acceptance, pending.
- Independent review `full` (Routing B0-A) — a separate read-only review deliverable per the execution protocol,
  not a step inside this item; pending.
