# Contributing to Vaulter

Thanks for your interest in Vaulter! This is a monorepo containing a Laravel package,
Vue components, a Node collaboration server and the documentation site.

## Prerequisites

- PHP `^8.4` with `composer`
- Node `>=20` with `npm`
- Docker (optional — only for the Hocuspocus collaboration server)

## Setup

```bash
git clone https://github.com/axioma-studio/vaulter.git
cd vaulter
composer install     # path-linked packages under packages/*
npm install          # npm workspaces (packages/vue, hocuspocus-server) + docs
```

## Development workflow (PHP)

### Local environment

Use the PHP 8.4 wrapper for Composer and PHP tooling, including subprocesses:

```bash
scripts/php84 --check
scripts/php84 composer install
scripts/db-preflight.sh pgsql
scripts/db-preflight.sh mysql
```

The database preflight defaults to `127.0.0.1`, PostgreSQL port `5432` or MySQL
port `3306`, database `vaulter_test`, and user/password `vaulter`/`secret`.
Override these with `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and
`DB_PASSWORD`. Database names must end with `_test`. Authentication failures
return exit 2 and print the administrator command needed to provision the test
account; other failures return exit 1. Resolve preflight failures before tests.

Run the PostgreSQL base suite with the same coordinates as CI:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret \
scripts/php84 vendor/bin/pest --ci \
packages/core/tests/Access packages/core/tests/Activity \
packages/core/tests/Feature packages/core/tests/Panels \
packages/core/tests/Schema packages/core/tests/Sharing packages/core/tests/Webhooks
```

### Tests

Every suite runs through `scripts/t`; zones, groups, lines and the run policy are in [`tests/README.md`](tests/README.md).

```bash
scripts/t --changed --dry-run        # what your change affects, nothing runs
scripts/t --changed                  # the affected zones and their consumers (SQLite)
scripts/t sqlite -p media            # one package
scripts/t pgsql -p core --group=database   # engine-dependent tests on a server
```

Run the named tests of your change, not the whole suite; full, coverage, mutation and load runs are not part of a
pull request.

### Concurrency tests

SQLite serialises writes, so a race test on it passes even against defective code.
Any test that must prove behaviour under real concurrency (two writers on one row,
a lock held across a read-modify-write, a sweep racing an upload) runs on PostgreSQL
through `Vaulter\Tests\Concurrency\ForkRace`: each task runs in its own forked
process on its own connection, and a file barrier releases them together.

```php
ForkRace::skipUnlessSupported($this); // pgsql + pcntl + posix, otherwise skipped

$outcomes = ForkRace::run([
    fn () => DB::transaction(fn () => bump(lock: true)),
    fn () => DB::transaction(fn () => bump(lock: true)),
]);

expect(array_map(fn (RaceOutcome $o) => $o->ok, $outcomes))->toBe([true, true]);
```

Each `RaceOutcome` carries `ok`, the task's JSON-serialisable `value`, and the thrown
`exception`/`message` (`failedWith(SomeException::class)`). Fixtures the children
read must be committed: bind the test directory with `DatabaseMigrations`, not
`RefreshDatabase`. A task returns or throws, never calls `exit()`. The harness refuses a
database whose name does not end in `_test`.
Packages reach it through the `Vaulter\Tests\` autoload-dev mapping to `../core/tests/`.

Run the harness suite locally exactly as the `test-pgsql` CI job does:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret \
scripts/php84 vendor/bin/pest --ci packages/core/tests/Concurrency
```

All quality tooling is wired through the `Makefile`:

| Command | What it does |
|---|---|
| `make lint` | Fix code style with Pint |
| `make lint-check` | Check code style (no fix) |
| `make stan` | PHPStan (level max) + Larastan |
| `make rector` | Rector dry-run |
| `make rector-fix` | Apply Rector |
| `make test` | Pest with `--coverage --min=80` |
| `make ci` | Everything CI runs (quality + tests) |

Run `make ci` before opening a PR.

### Running the suites quickly

A PostgreSQL or MySQL run is slow because of repeated DDL (every schema build is dozens of `CREATE`/`ALTER`
statements, each one a durable commit), not because of test logic. Use the harness; it changes no test:

```bash
scripts/test-db.sh pgsql packages/core/tests/Schema            # repo root
scripts/test-db.sh -C packages/drive mysql tests/Drive          # one package
scripts/test-db.sh sqlite packages/core/tests                   # in-memory, parallel
```

- **A private server per run.** `pgsql` and `mysql` start a disposable server as your own user
  (`scripts/db-test-env.sh`: fsync off, no binlog, a temporary directory removed afterwards; no Docker or root) and
  stop it at the end. The shared local server is not touched, so lanes cannot collide on one database.
- **One database per worker.** `DB_PARALLEL` (default: half the CPUs, at most 8; `0` runs serially) sets the
  `pest --parallel` workers; each gets `vaulter_w<N>_test` (`SuiteDatabaseConnection` reads `TEST_TOKEN`).
- **Follow progress** with `tail -f var/quality/test-db/<engine>-output.log`.
- **Run a full suite only on frozen code.** Between full runs use the affected scope (the changed package, the
  touched paths on a real database). When a run fails, rerun only the failing files, not the whole suite.
- **Racing tests need their own database.** Never point two lanes at the same `*_test` database (`DatabaseMigrations`
  and `ForkRace` suites drop and rebuild it); the harness gives each run a server of its own.

#### Private servers never outlive their run

Each private server lives in `${VAULTER_DB_ROOT:-/tmp}/vaulter-dbtest.XXXXXX`, owned by the shell that started it:

- **`db_down`** (the `EXIT` trap of `scripts/test-db.sh` and `scripts/schema-snapshot.sh`) stops every server
  process of the directory — mysqld on `TERM`, postgres on `INT`, `KILL` after 10 s, found by pid file and, if the
  server died before writing one, by its command line — and deletes the directory only once they are gone. A server
  left running without its data directory loops on errors and floods its log; this order prevents it.
- **`DB_LOG_MAX_BYTES`** (default `52428800`, 50 MiB) caps `server.log` of both engines; past it the output is read
  and dropped, so the server never blocks. **`DB_FILE_MAX_MB`** (default `16384`, `0` = off) caps every file the
  server writes with `ulimit -f` — the last guard: a runaway server dies with `SIGXFSZ` instead of filling the disk.
- **A run killed with `kill -9` or a timeout** cannot run its trap. The directory's `owner` file holds the pid and
  start time of the owning shell, and a watchdog (`scripts/db-reap.sh --watch`, detached from the run with `setsid`)
  stops the server and deletes the directory within seconds of the owner's death. A server of another, still running
  shell is never touched. `db_up` also reaps what dead owners left behind before starting a new server.
- **`scripts/pg-local.sh`** starts its server with `DB_PERSISTENT=1`: it outlives the shell on purpose, has no
  watchdog and is never reaped; `scripts/pg-local.sh down` stops it the same way as `db_down`.

Finding and removing orphans by hand:

```bash
pgrep -a mysqld | grep vaulter-dbtest; pgrep -a postgres | grep vaulter-dbtest   # servers of the harness
ls -d /tmp/vaulter-dbtest.* /dev/shm/vaulter-dbtest.* 2>/dev/null                 # their directories
scripts/db-reap.sh --dry-run    # what has a dead owner
scripts/db-reap.sh              # stop those servers and delete their directories (only yours, only dead owners)
scripts/db-reap.sh --legacy     # also directories without an owner file (older harness) — only when no run is going
scripts/db-reap.sh --stop /tmp/vaulter-dbtest.XXXXXX   # one directory of yours, even if its owner is alive
```

`scripts/db-reap.sh` only considers `vaulter-dbtest.*` directories of the current user and only signals mysqld,
mariadbd and postgres processes whose command line names that directory.

### Changing the REST contract

`openapi/vaulter-v1.yaml` is the source of truth of the REST API v1. Change a route, a resource or a header and the
document in the same pull request, then:

```bash
scripts/php84 vendor/bin/pest packages/core/tests/OpenApi                 # coverage, document, validator, examples
UPDATE_GOLDEN=1 scripts/php84 vendor/bin/pest packages/core/tests/OpenApi/RouteListSnapshotTest.php
VAULTER_OPENAPI_EXAMPLES_WRITE=1 scripts/php84 vendor/bin/pest packages/core/tests/OpenApi/OpenApiExamplesTest.php
```

- The `core`, `drive` and `documents` suites set `VAULTER_OPENAPI_VALIDATE=1`: every `vaulter.v1.*` response of a
  feature test is checked against the document, so a field the document does not describe fails the test that
  produced it. Fix the document when the code is right; there are no exemptions.
- `UPDATE_GOLDEN=1` rewrites `packages/core/tests/Fixtures/golden/routes-v1.json`; commit its diff.
- `VAULTER_OPENAPI_EXAMPLES_WRITE=1` rewrites `openapi/examples/*.json` from real responses; commit the diff.
- CI (`.github/workflows/openapi.yml`) runs `oasdiff breaking` against the contract of the last released tag. A
  breaking change to `v1` needs `v2`, not an exception.

## Development workflow (JS)

```bash
npm run build        # build @vaulter/vue (Vite library)
npm test             # Vitest
npm run docs:dev     # VitePress docs dev server
```

## Conventions

- **Commits & PR titles:** Conventional Commits (`feat:`, `fix:`, `docs:`, …) — enforced in CI.
- **Branches:** `feat/**`, `fix/**`, `hotfix/**`.
- **Namespaces:** core is `Vaulter\` (`packages/core/src`); new integration packages live
  under `packages/<name>` with `Vaulter\<Name>\` (AzGuard convention).
- **Self-consistent commits:** one plan item per commit, and every commit builds on its own.
  Before committing run `python3 scripts/check-commit-closure.py --all HEAD` (after the commit) or
  `python3 scripts/check-commit-closure.py <rev>`: it fails when a changed source names a `Vaulter\`
  class that is absent from that revision, which is what breaks `git bisect` and reverts.
- **Docs:** plain Markdown only — no `[[wikilinks]]` (docs are synced to the Brain vault,
  which rejects wikilinks).

## Deprecation policy

- Mark a symbol slated for removal with `@deprecated` and a concrete removal target:
  `@deprecated since X.Y, removed in the next major`.
- A `@deprecated` symbol stays functional for **at least one minor release** after it is
  marked — it is never removed in the same minor it was deprecated in. Removal happens on
  the next **major** release.
- While the package is pre-1.0 (`0.x`, no tagged release yet — see `UPGRADE.md`), breaking
  changes ship directly without a deprecation cycle (no BC obligation exists yet); this
  policy takes effect starting with the first `1.0` release, and any `@deprecated` symbol
  added before then is still expected to carry a removal target for when it does.
- Document every deprecation in `UPGRADE.md` (breaking-change log) with what replaces it and
  why, once it is actually removed.

## Release tooling

The release set lives in `release/packages.json`; `monorepo-split.yml` splits exactly those packages
(`scripts/release/check-versions.php` fails when the matrix and the manifest disagree).

- `scripts/php84 scripts/release/check-versions.php` — siblings are required only as `self.version`, nothing shipped
  requires a `dev-*` constraint (the excluded AzGuard bridge may pin its untagged upstream), and `@vaulter/vue` declares
  the REST API line.
- `scripts/php84 scripts/release/build-artifacts.php --version=X.Y.Z --out=DIR` — one zip per package from `git archive`
  (so `export-ignore` applies), with the version written into `composer.json`. No network. A package whose upstream is a
  dev require (`vaulter-azgard` until AzGuard is tagged) is skipped with the reason.
- `scripts/release/consumer-smoke.sh DIR X.Y.Z` — installs the artifacts into a fresh Laravel app without path
  repositories and runs `vaulter:install`, config/route cache and `vaulter:doctor --json`. Needs network and takes
  minutes; run it before tagging, not in a feature branch.
- `scripts/release/verify-split-tags.sh TAG [expected-splits.json]` — read-only; the `verify-tags` job of
  `monorepo-split.yml` runs it after the split. It exits 1 and lists every split repository that lacks the tag or has it
  on another commit.
- `PYTHONDONTWRITEBYTECODE=1 python3 scripts/test-release-tools.py` — offline tests of the tools above; remotes are
  replaced by a `git` stub on `PATH`.


## Documentation sync

The `docs/` folder is two-way synced with the Brain vault project
`05 - Projects/03 - OpenSource/Vaulter/` via `brain sync docs`. Edit on either side; the
CLI performs a git 3-way merge. Always preview with `brain sync docs --dry-run` first.
