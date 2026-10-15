# Tests

Pest on Orchestra Testbench (PHP), `node --test` (hub) and Vitest (vue). Every suite is run through one entry,
`scripts/t`. Two machine files describe the layout and are the single source for the runner, the selector and CI:

- `tests/zones.json` — **zones**: an index over existing directories (it never moves a test). Used by
  `scripts/t --changed` and checked by `tests/Repo/ZonesMapTest.php`, which fails when a source root or a test file is in no zone.
- `tests/suites.json` — **suites and schema partitions**: for each PHP suite its working directory, vendor and the
  partitions (the files that share one TestCase schema). The runner never guesses a schema from Pest code at run time.

## Suites and zones

| Suite (`-p`) | cwd | Zones |
|---|---|---|
| `core` | repo root | `core-kernel`, `core-access`, `core-sharing`, `core-events-webhooks`, `core-http`, `core-schema`, `core-observability` (aggregator `core`) |
| `media`, `drive`, `documents`, `azgard`, `filament` | `packages/<name>` | one zone each; `uses` follows the Composer graph (drive uses core+media, documents uses core+media+drive, …) |
| `repo` | repo root | `tests/Repo`: the harness itself (runner, selector, zone map, groups) |
| `hub` | `hocuspocus-server` | Node tests of the collaboration hub (not Pest) |
| `vue` | `packages/vue` | the TypeScript client (not Pest) |
| `load` | repo root | `load/tests`; **explicit only** (`-p load` or `stand`), never in `all` |

Paths are repo-relative for `core`, `load` and `repo`, package-relative for the others. Zones of the core suite and
their `src`/`tests`/`uses` are listed in `tests/zones.json`.

## Groups

| Group | What it selects |
|---|---|
| `zone:*` | The tests of a zone. |
| `database` (`database:<zone>`) | Tests whose result depends on the engine (SQL, constraints, transactions, locking, collation). Listed per package in `packages/*/tests/groups.php`; the union equals `database[]` of `zones.json` (`tests/Repo/TestGroupsTest.php`). Only these run on PostgreSQL and MySQL. |
| `serial` | Tests that must not share a process with parallel workers (races, committed transactions). |
| `extended` | Scale: the whole seed range or large sizes; the same budget runs smaller in the fast lane. |
| `stand` | External services and the functional load tests; run by `scripts/t stand`. |
| `nofast` | Umbrella of `serial` + `extended` + `stand`: excluded from the fast lane. |
| `nofull` | `stand`: excluded from `--full`. |

Selecting `--group=extended` or `--group=stand` drops the exclusion the group implies, so a selection is never empty.

## Commands

All commands accept `--dry-run [--json]`: it prints the plan (suites, partitions, passes) and starts nothing.

| Command | Runs |
|---|---|
| `scripts/t --help` | The full usage. |
| `scripts/t` / `scripts/t sqlite` | The fast lane of every default suite on SQLite (`make test`). |
| `scripts/t sqlite -p <pkg>` | One suite (`composer test` = core). |
| `scripts/t sqlite -p <pkg> <path>` | One path (one suite per path list). |
| `scripts/t <pgsql\|mysql> -p <pkg> --group=database` | The engine-dependent tests on a server. Servers need paths, `--group` or `--full`; there is no default server sweep. |
| `scripts/t all`, `--engines=sqlite,pgsql` | Several engines. |
| `scripts/t --changed [--base=<ref>] [--quick] [--db=pgsql\|mysql]` | The zones touched by `base...HEAD` plus staged, unstaged and untracked files, and their consumers; `--quick` keeps only the touched zones. An unknown path or a harness change is refused with exit 2 and the full affected plan is printed; prose-only (`README.md`, `CONTRIBUTING.md`, `docs/`, `plans/`) is a plan of zero runs and is not a pass; `AGENTS.md` and `CLAUDE.md` are not prose, so editing them is refused the same way. |
| `scripts/t <engine> -p <pkg> --full` | Parallel pass without `nofull,serial`, then one `serial` pass. |
| `scripts/t <engine> -p <pkg> --group=serial -j1 --no-skips` | The serial pass only. |
| `scripts/t stand` | Stand-group tests plus the real-process scripts (`scripts/collab-e2e.sh`). Never part of fast/full/all; k6 and benchmarks are not started by any command here. |
| `scripts/t baseline --write\|--compare [-p …]` | Discovery of test names against `tests/baseline/` (nothing is executed). |

Other flags: `-j N` workers inside a partition (default half the CPUs, at most 8), `--junit=DIR`, `--no-skips`,
`--exclude-group=G`. Words after `--` that start with `-` go to Pest. Exit: `0` ok, `1` a pass failed, `2` usage or
refused plan, `3` zero tests where tests were required.

A partition is one Pest process; partitions run one after another and `-j N` workers run inside a partition. Server
engines are provisioned by `scripts/test-db.sh` (a private disposable server, or `DB_EXTERNAL=1` for a ready CI service
whose database name ends in `_test`). The lower-level `scripts/test-db.sh` stays available for a single raw run.

## Lines and where they run

| Line | Where | What |
|---|---|---|
| Fast | `ci.yml` (`fast`), per package and Laravel version | `scripts/t sqlite -p <pkg>`; which packages run comes from `scripts/t --changed --dry-run --json` |
| Database | `ci.yml` (`db`), after `fast` is green | `scripts/t <engine> -p <pkg> --group=database` on PostgreSQL and MySQL services |
| Serial | `ci.yml` (`serial`), after `fast` is green | `scripts/t pgsql -p <pkg> --group=serial -j1 --no-skips` |
| Repo, hub, vue, openapi | `ci.yml` | harness tests, Node tests, spec validation |
| Gate | `ci.yml` (`ci-gate`) | the single required status |
| Weekly full | `weekly-full.yml` (Monday) | `--full` on every engine, coverage, the stand |
| Mutation, load | `mutation.yml`, `load.yml` | `workflow_dispatch` only, on the owner's request |

## Writing a test

- Put it in the directory of its zone. A new source root or test directory goes into `tests/zones.json` first, and a
  new directory of a PHP suite into `tests/suites.json` (the harness tests fail otherwise).
- Reuse the TestCase of the neighbours. Do not mix `RefreshDatabase` and committed `DatabaseMigrations` in one partition.
- A result that depends on the engine belongs to `database` (`packages/<pkg>/tests/groups.php`); a pure-logic test there
  only costs time on every server.
- A race, or a test that commits, is `serial`; scale or the whole seed range is `extended` with a cheap equivalent in
  the fast lane; external services are `stand`.
- A test that writes into the Testbench skeleton (publishes config, the bootstrap cache) runs `-j1` with its own
  `TESTBENCH_APP_BASE_PATH`; the runner does that for the partitions marked so in `suites.json`.
- A dataset that multiplies a dimension no code reads is a loop in one test, or no dimension at all.
- Removing or merging a living test needs the owner's decision and a replacement: `scripts/t baseline --compare`
  fails on a missing original name that has no entry in the ledger.

## Inventory (PLAN3 P2)

Architecture inventory of the monorepo: one `SourceIndex` reads every `packages/{core,media,drive,documents,azgard,filament}/{src,database,config,routes}` file, generators turn it into committed fixtures, and thin Pest tests of the `repo` suite assert that nothing drifted.

```bash
scripts/php84 php scripts/inventory/inventory.php [<name>…] --check    # recompute in memory, compare byte-for-byte; 0 equal, 1 differs, 2 rule/usage error
scripts/php84 php scripts/inventory/inventory.php [<name>…] --write    # rewrite the generated fixture(s)
scripts/php84 php scripts/inventory/inventory.php [<name>…] --report   # --check plus a summary (found/new/stale for scans)
```

- No names runs every generator (`scripts/inventory/generators/*.php`, one file per generator, `name()` = basename); an unknown name exits 2.
- Fixtures live in `tests/Repo/Arch/Fixtures/`. `packages.json` is hand-written for `dir`, `exists` and `target_require`; `composer`, `psr4` and `current_require` are verified against each `packages/<p>/composer.json` and rewritten by `packages --write`. Never edit generated files by hand: change the rule or the code, then `--write`.
- Library tests: `scripts/t sqlite -p repo tests/Repo/Arch/InventoryHarnessTest.php`. A new `tests/Repo/Arch/*Test.php` goes into `suites.repo.partitions[0].files` of `tests/suites.json` in the same commit.

## Which run when

This table is the single source for humans and agents (`AGENTS.md`, `CLAUDE.md` and `CONTRIBUTING.md` link here).
Pick the **smallest** row that covers the change; a bigger row is never a substitute for reading what failed.

| When | Command | Covers | Does not cover | Time¹ |
|---|---|---|---|---|
| Iterating on a step (after every edit) | `scripts/t sqlite -p <pkg> <path>` — the test files you are changing | those files, SQLite | consumers, servers | seconds |
| Step done, before a commit | `scripts/t --changed` (`make test-changed` adds `--db=pgsql`) | touched zones + their consumers, SQLite fast lane; with `--db=pgsql` their database tests on pgsql | mysql, `serial`/`extended`, untouched zones | 0.5–2 min (drive change: 1 min 52 s; `--quick --db=pgsql`: 21 s) |
| The change touches SQL, a migration, a transaction or locking | `scripts/t pgsql -p <pkg> --group=database` | engine-dependent tests of the package on pgsql | mysql | 20 s – 1 min per package |
| Before a push of a schema/query change | `make test-db` (= `scripts/t pgsql --group=database`) | every engine-dependent test of every suite, pgsql | mysql, `serial`, `extended` | 2 min 43 s |
| MySQL-specific doubt (collation, locking, DDL) | `make test-db-mysql` (= `scripts/t mysql --group=database`, local server in Docker on tmpfs) | every engine-dependent test, mysql | pgsql, `serial`, `extended` | 10 min (was hours with a native mysqld on disk) |
| All default suites on SQLite (harness change, wide refactor) | `make test` (= `scripts/t sqlite`) | every default suite, SQLite fast lane, vue, hub | servers, `serial`, `extended`, `stand` | 3 min 52 s |
| Closing a plan, before a release (frozen code) | `make test-full` (= `scripts/t all --full`) | the whole matrix: sqlite+pgsql+mysql, `serial`, `extended` (full seeds) | `stand`, coverage, mutation, load | see below² |
| Owner's explicit request only | `scripts/t stand`; `make test-coverage`; `make mutate`; `load.yml` | external services, coverage, mutation, k6 | — | long |

¹ Measured on the 8-CPU dev host, 4 workers, `make db-up` / `make db-up-mysql` running, other jobs on the host.
² Recorded in "Timings" below.

Rules:

- Never run `--full`, `all`, coverage or mutation per change; CI and `weekly-full.yml` run them.
- `scripts/t --changed` exits 2 for a harness change (`scripts/t`, `tests/*.json`, `composer.json`, TestCases) or an
  unknown path and prints the affected plan: then run `make test` (SQLite) and `make test-db`, not `--full`.
- Start the local servers once per session: `make db-up` (pgsql in `/dev/shm`, native binaries) and, only when you need
  mysql, `make db-up-mysql` (Docker container `vaulter-mysql-local`). Stop them with `make db-down` / `make db-down-mysql`.
  Two runs on one local server wait for each other (`test-db: another run is using …`); they never share a schema.
- A failure: rerun the failing file (`scripts/t <engine> -p <pkg> <file>`), not the tier.

### Timings

| Run | Before | After |
|---|---|---|
| `scripts/t pgsql --group=database` (every suite) | not runnable: ~25 partitions without a database test failed with "zero tests" | 2 min 43 s, 28 steps |
| core `DatabaseMigrations+TestCase` partition, mysql | 702 s (native mysqld, data on disk) | 158 s (`mysql-local`, tmpfs) |
| every database test on mysql | hours (sum of partitions at the native-disk rate) | 10 min 4 s |
| fast lane, every default suite, SQLite | 3 min 52 s | unchanged (already parallel, no coverage) |
| a drive change, `--changed` (drive + 3 consumers) | — | 1 min 52 s |
| `--full`, SQLite (every suite, `serial` + `extended`) | — | 4 min 28 s |
| `--full`, pgsql | — | 9 min 23 s |
| `--full`, mysql (`mysql-local`) | hours | 33 min 20 s |
| every `DatabaseMigrations` test file on mysql (core `EventRecorderTest`), private `mysqld` | 547 s | 86 s (`eatmydata`, opt out with `DB_FAST=0`) |
| `scripts/t-matrix`: sqlite, pgsql and mysql `--full` of every package, 4 cells at a time, native servers | — | 25 min (mysql core 20 min, mysql documents 23 min are the long cells; sqlite 6 min 31 s) |

## Setting up the database servers

`scripts/t pgsql|mysql` starts a private, throw-away server per run (`scripts/db-test-env.sh`) unless a ready one is given
(`DB_EXTERNAL=1` plus `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`, `DB_DATABASE`, the latter ending in `_test`).
What a machine needs, and why:

| Where | What to install / set | Why |
|---|---|---|
| Any machine | `postgresql` 16 and/or `mysql-server` 8.x binaries on `PATH` (`mysqld`, `mysql`, `mysqladmin`; `initdb`, `pg_ctl`, `psql`) | the private servers are started from them; no root, no Docker |
| MySQL, strongly recommended | `eatmydata` (`apt install eatmydata`) | the server is throw-away, so `fsync` is pointless; the script puts `libeatmydata` in front of `mysqld` when it is installed. DDL-heavy partitions (`migrate:fresh` per test) ran 6x faster: 547 s -> 86 s. `DB_FAST=0` turns it and the other speed settings off |
| MySQL on Ubuntu/Debian | nothing, but know the limit: AppArmor lets `mysqld` use `/tmp` only, not `/dev/shm` or `/run/user`, so the data directory cannot be tmpfs. Set `VAULTER_DB_ROOT` to another disk path only if the profile allows it | the speed gap to Docker-on-tmpfs is what `eatmydata` closes |
| A machine with Docker | `make db-up` / `make db-up-mysql` start `pgsql` / `mysql:8.4` with the data on tmpfs (`scripts/pg-local.sh`, `scripts/mysql-local.sh`); `scripts/t` reuses them (fastest MySQL, one run at a time) | CI uses the same images |
| CI | service containers `postgres:16` / `mysql:8.4` with `DB_EXTERNAL=1` and the `DB_*` variables; start them with `--innodb-flush-log-at-trx-commit=0 --innodb-doublewrite=0 --skip-log-bin --log-bin-trust-function-creators=1` and the data directory on tmpfs | same speed settings as the private server; see the `ci.yml` / `weekly-full.yml` service definitions |
| Several runs at once | `VAULTER_DB_PRIVATE=1` (set by `scripts/t-matrix`) | ignores the shared local Docker servers, which would make the runs wait for each other |

The package suites that need a sibling checkout pin it in `composer.json` (azgard: `axiomasoft/azguard` at a commit): a local
`../azguard` that has moved on makes `RealAzgard` fail with errors such as `PanelBuilder::scopes()`; check out the pinned commit.

`scripts/t-matrix` (= `make test-full`) runs every engine/package pair as its own `scripts/t --full` on its own private
server, a few at a time: the wall time is that of the slowest cell, not the sum.

## Run policy

- **While working (local):** SQLite plus one server engine, **pgsql**. `scripts/t --changed --db=pgsql` (`make test-changed`)
  or the named tests of the change, plus Pint/PHPStan of the touched package. The server engine only when the change
  touches a schema, a query, a transaction or locking. One PHP (8.4), one engine matrix cell; mysql only for a
  mysql-specific doubt. Why pgsql: in `/dev/shm` it is the fastest server (core `DatabaseMigrations` partition: pgsql
  32 s, mysql 158 s in `mysql-local` on tmpfs, 702 s with a native mysqld whose AppArmor profile forbids tmpfs).
- **Local speed-ups:** `make db-up` keeps one pgsql server in `/dev/shm` between runs (a run drops from ~8 s to ~3.5 s of
  overhead; `make db-down` stops it); `make db-up-mysql` does the same for mysql in Docker. Scale tests (`Scale::pick()` in `packages/core/tests/Support/Scale.php`) run a cheap
  equivalent of the same code path unless the run is full (`VAULTER_FULL_SEEDS=1`, set by `--full`): 157 s → 15 s on pgsql.
- **Before closing a plan (and in CI):** the complete matrix once on frozen code — `scripts/t all --full`
  (sqlite+pgsql+mysql), Pint/PHPStan of every suite, Node suites. An engine-specific failure found there is fixed then;
  it is not a reason to run mysql on every change. mysql alone: `scripts/t mysql -p <pkg> --group=database`.
- **Full** is never run per change. When `--changed` refuses with exit 2, run `make test` and `make test-db` instead.
- **Coverage** runs weekly in CI. **Mutation and load/k6/benchmarks** run only when the owner asks for them.
- Report what really ran: the engine, the skips and the exit codes. A skipped or failed check is not a pass; a missing
  engine or an unavailable stand makes a qualification incomplete.
