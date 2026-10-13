# Database test performance

Run commands from the repository root. The database runner starts a private server
and gives each parallel worker its own `_test` database and storage directory.
Start with two workers to limit concurrent database and PHP memory usage:

```bash
DB_PARALLEL=2 XDEBUG_MODE=off \
  timeout --signal=TERM --kill-after=5s 12s \
  scripts/test-db.sh pgsql packages/core/tests --no-coverage
```

Exit code `124` means the time limit stopped this smoke run; it does not establish
that the full suite passes. Use `mysql` for the MySQL lane. `DB_PARALLEL=1` runs
serially. Leave coverage enabled for checks that require coverage.

`RefreshesDatabaseForSchemaVariant` registers migration paths on each application
without starting Testbench's separate migrate/rollback cycle. Server tests sharing
a TestCase, providers, connection and schema configuration migrate once and roll
back test data in transactions. Changing the prefix, ID strategy, tenancy or
features invalidates the schema. SQLite and the committed concurrency tests
keep their Testbench lifecycle.

## Schema snapshots

The installed schema is pinned as raw catalog text (SQLite `sqlite_master`/pragmas, PostgreSQL `pg_catalog`, MySQL
`information_schema` and `SHOW CREATE TABLE`) for 48 configuration presets — id strategy × host-key type × table
prefix × tenancy (`Fixtures/Schema/snapshot-configs.json`) — on each engine.

- `Fixtures/Schema/original/` is the schema of the 0.x chain before P1.10, captured at `c64bba25` and never rewritten
  (`metadata.json` lists the 20 PostgreSQL presets the 0.x chain could not migrate; their original is the builder schema).
- `Fixtures/Schema/expected/` is the current schema. A deliberate schema change updates it together with its decision.
- `SchemaSnapshotTest` compares the running preset with `expected/`; `scripts/schema-snapshot.sh --driver=<engine>
  --all-presets --compare=<dir>` migrates an empty database per preset (one process and schema each, on a private
  disposable server) and prints the table, part and name of every difference. It runs migrations only, no behavioural test.

## Short measurement, 2026-10-11

PostgreSQL 18, one worker, private fast server, Xdebug and PCOV collection disabled
in both measurements. The same `Feature` selection ran with a 12-second limit:

| Metric | Before | After |
| --- | ---: | ---: |
| Elapsed | 12.008 s (stopped) | 2.612 s (selection finished) |
| Passed tests | 53 | 85 |
| Existing failed tests | 2 | 2 |
| Migration table creations / schema rebuilds | 28 | 1 |
| Logged DDL statements | 9,383 | 237 |

Observed passing-test throughput increased from 4.4 to 32.5 tests/s, about **7.4×**.
The after selection includes all tests reached before the time limit; schema
rebuilds fell by **96%**, despite more tests running. DDL totals are from different
completed test counts, not a comparison of identical finished workloads. This is
an estimate for migration-heavy tests, not a measured speedup of the full suite.
Both measurements exposed the same two pre-existing `EventTypeTest` failures.

Validation: 37 focused PostgreSQL tests (including schema reuse, prefix/ID switches,
Spatie, committed fork races and fresh migrations) passed; all three schema reuse
tests passed on MySQL. A two-worker full-core startup showed 112 passing progress
markers before being stopped at 12 seconds. SQLite started; its short variant
selection passed 25 tests and exposed two `HostKeys` constructor errors also
reproduced using the original test harness. Pint passed for the changed PHP files.
