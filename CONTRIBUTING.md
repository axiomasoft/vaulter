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

## Documentation sync

The `docs/` folder is two-way synced with the Brain vault project
`05 - Projects/03 - OpenSource/Vaulter/` via `brain sync docs`. Edit on either side; the
CLI performs a git 3-way merge. Always preview with `brain sync docs --dry-run` first.
