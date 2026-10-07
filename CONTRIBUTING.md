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

## Documentation sync

The `docs/` folder is two-way synced with the Brain vault project
`05 - Projects/03 - OpenSource/Vaulter/` via `brain sync docs`. Edit on either side; the
CLI performs a git 3-way merge. Always preview with `brain sync docs --dry-run` first.
