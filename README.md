# DocVault

> Embeddable document management for Laravel — file manager, TipTap editor, version
> history and real-time collaboration, wired into your app's own roles via a thin adapter.

DocVault grew out of the collaborative-documents module in FlexCRM. It packages that
infrastructure (TipTap JSON content, version snapshots, folder tree, workspace scoping,
and Yjs-based real-time editing) into a reusable component you can drop into any Laravel
project without adopting a new auth system.

> **Status:** early scaffold (`0.0.x`). The repository structure, tooling and docs are in
> place; feature implementation follows the milestones in [`docvault-spec.md`](docvault-spec.md).

## Requirements

| Component | Version |
|---|---|
| PHP | `^8.4` |
| Laravel | `^12` \| `^13` |
| Vue | `3.x` |
| Node (collab server / build) | `>=20` |

## Monorepo layout

```
doc-vault/
├── packages/
│   ├── core/             # axioma-studio/docvault-core — the Laravel package (DocVault\)
│   │   ├── src/  config/  database/migrations/  routes/  tests/
│   └── vue/              # @docvault/vue — Vue 3 components (Vite library build)
├── hocuspocus-server/    # Node.js real-time collaboration server (+ Docker)
├── docs/                 # VitePress developer documentation (synced with the Brain vault)
└── (pint, phpstan, rector, pest, Makefile, CI…)
```

Further integrations follow the AzGuard convention — each lives under `packages/` with its
own namespace, e.g. a future `packages/filament` → `DocVault\Filament\`.

## Quick start (development)

```bash
# PHP side (monorepo, path-linked packages)
composer install
make ci            # pint --test + phpstan + rector --dry-run + pest --min=80

# JS side (npm workspaces)
npm install
npm run build      # build @docvault/vue
npm test           # Vitest

# Docs
npm run docs:dev   # VitePress dev server
```

## Documentation

Developer docs live in [`docs/`](docs/) and are published with VitePress. See
[CONTRIBUTING.md](CONTRIBUTING.md) for the development workflow.

## License

[MIT](LICENSE) © Axioma Studio.
