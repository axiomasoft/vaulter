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
| Database | `ci.yml` (`db`) | `scripts/t <engine> -p <pkg> --group=database` on PostgreSQL and MySQL services |
| Serial | `ci.yml` (`serial`) | `scripts/t pgsql -p <pkg> --group=serial -j1 --no-skips` |
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

## Run policy

- **While working:** the named tests of the change, or `scripts/t --changed`, plus Pint/PHPStan of the touched package.
  A server engine only when the change touches a schema, a query, a transaction or locking (`--db=pgsql|mysql`).
- **Full** (`--full`, every package, every engine) is a qualification run on frozen code, once per phase, not per change.
  Run it on a full affected plan only when `--changed` refuses with exit 2.
- **Coverage** runs weekly in CI. **Mutation and load/k6/benchmarks** run only when the owner asks for them.
- Report what really ran: the engine, the skips and the exit codes. A skipped or failed check is not a pass; a missing
  engine or an unavailable stand makes a qualification incomplete.
