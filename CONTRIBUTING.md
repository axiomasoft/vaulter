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

All quality tooling is wired through the `Makefile`:

| Command | What it does |
|---|---|
| `make lint` | Fix code style with Pint |
| `make lint-check` | Check code style (no fix) |
| `make stan` | PHPStan (level 8) + Larastan |
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
