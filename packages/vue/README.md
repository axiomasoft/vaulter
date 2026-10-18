# @vaulter/vue

Vue 3 + TypeScript client for [Vaulter](../../README.md): a typed API client for the REST v1 contract, composables and
headless components for the Drive UI (folders, files, uploads, sharing, trash, activity, comments, document nodes). The
editor engine itself (TipTap, Univer, …) is yours.

```bash
npm install @vaulter/vue
```

Peers: `vue@^3.4` and `@tiptap/pm@^3`. Node `>=22`. See [Frontend overview](../../docs/guides/vue.md) for the plugin and
the `createVaulterClient` setup, the composables and the components.

## Realtime collaboration is a separate package

`@vaulter/vue` contains **no** `yjs` and no `@hocuspocus/*`. It defines only the seam — `VaulterCollaborationKey`, the
`CollaborationAdapter`, `CollaborationHandle` and `CollaborationStatus` types — and the REST autosave path used by
`VaulterDocumentNode`. Realtime editing is
[`@vaulter/vue-collaboration`](../vue-collaboration/README.md); pass its adapter to the plugin:

```ts
import { VaulterPlugin } from '@vaulter/vue'
import { createCollaborationAdapter } from '@vaulter/vue-collaboration'

app.use(VaulterPlugin, { baseUrl: '/api/vaulter', collaboration: createCollaborationAdapter() })
```

Without the adapter every document uses the autosave path. The backend module is the opt-in
`axioma-studio/vaulter-collaboration` ([guide](../../docs/guides/collaboration.md),
[ADR-0031](../../docs/adr/0031-collaboration-package-and-durability.md)).

## Development in the monorepo

```bash
npm --prefix packages/vue run typecheck     # vue-tsc
npm --prefix packages/vue run api:check     # src/api/schema.d.ts matches openapi/vaulter-v1.yaml
npm --prefix packages/vue test              # vitest
```

`src/api` imports no Vue, editor or realtime packages (arch test `tests/arch/api-no-vue.test.ts`). Regenerate the types with
`npm --prefix packages/vue run api:generate` after the OpenAPI contract changes.
