# Vaulter

> Embeddable document management for Laravel — a file manager, TipTap editor, version history
> and real-time collaboration, wired into your app's own roles through a thin adapter.

Vaulter grew out of the collaborative-documents module in FlexCRM and packages that
infrastructure into a reusable component you can drop into any Laravel project **without adopting
a new auth system** — it delegates every permission check to a `PermissionResolver` you implement.

| Component | Version |
|---|---|
| PHP | `^8.4` |
| Laravel | `^12` \| `^13` |
| Vue | `3.x` |
| Node (collab server / build) | `>=20` |

## Features

- **Documents** — TipTap-JSON content, four built-in types (rich text, table, markdown, template),
  soft-delete + trash, restore, permanent purge, **move**, **duplicate**, **bulk** operations and
  title/content **search**.
- **Versions** — named snapshots, history and one-click restore, with pruning to a configurable cap.
- **Folders** — a hierarchical tree scoped to a workspace.
- **Permissions** — delegated entirely to your app via `PermissionResolver`; computed **abilities**
  (`view/comment/edit/manage/delete`) are shipped to the front-end on every document.
- **Real-time collaboration** — Yjs over a pluggable `CollaborationDriver`: **Laravel Reverb** (no
  extra service) by default, or an external **Hocuspocus** Node hub. Server-side state sync +
  `content.synced` broadcast.
- **Comments** — threaded, optionally anchored, broadcast live (`comment.added`).
- **Share links** — tokenized, level-scoped, optionally expiring public links.
- **Export** — render any document to HTML or Markdown.
- **Embeddable by design** — swappable Eloquent models, dual `id`/`ulid` keys and optional
  `tenant_id` (drops cleanly into both plain Laravel and multi-tenant apps).
- **TypeScript front-end** (`@vaulter/vue`) — a typed API client, composables
  (`useDocuments`, `useVaulterEditor`, `useCollaboration`, `useComments`, `useShares`,
  `usePermissions`, `useDocumentExport`) and slot-driven components, shipped with `.d.ts`.
- **Filament admin** (`@vaulter/filament`, optional) — a drop-in Filament 5 plugin to manage
  documents (incl. trash/restore) from your panel.

## Install

```bash
composer require axioma-studio/vaulter-core
php artisan vaulter:install   # publishes config + migrations, runs them
npm install @vaulter/vue
```

Tell Vaulter how to authorize, by implementing `Vaulter\Contracts\PermissionResolver`:

```php
use Vaulter\Facades\Vaulter;

Vaulter::resolvePermissionsWith(new \App\Vaulter\PermissionResolver());
```

Wire the front-end:

```ts
import { VaulterPlugin } from '@vaulter/vue'

app.use(VaulterPlugin, { baseUrl: '/api/vaulter' })
```

```vue
<VaulterFileManager workspace-type="App\Models\Project" :workspace-id="project.id"
  @open-document="openEditor" />
```

See the [documentation](docs/) for the full API, the permission-resolver contract, the collaboration
setup and embedding into multi-tenant apps.

## Monorepo layout

```
vaulter/
├── packages/
│   ├── core/             # axioma-studio/vaulter-core — the Laravel package (Vaulter\)
│   ├── vue/              # @vaulter/vue — TypeScript Vue 3 client, composables & components
│   └── filament/         # axioma-studio/vaulter-filament — Filament 5 admin (Vaulter\Filament\)
├── hocuspocus-server/    # optional Node Hocuspocus collaboration hub (+ Docker)
├── docs/                 # VitePress developer documentation
└── (pint, phpstan, rector, pest, Makefile, CI…)
```

Integrations follow the AzGuard convention — each lives under `packages/` with its own namespace
(`Vaulter\Filament\`, …) and is tested in isolation so optional dependencies (Filament) never
constrain the core matrix.

## Development

```bash
composer install && make ci      # Pint + PHPStan (level max) + Rector + Pest
npm install && npm test          # vue-tsc + Vitest
npm run docs:dev                 # VitePress
```

Every public class is strictly typed and covered by tests; see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE) © Axioma Studio.
