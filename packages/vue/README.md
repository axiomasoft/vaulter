# @vaulter/vue

Vue 3 + TypeScript client for [Vaulter](../../README.md): a typed API client for the REST v1 contract, composables and
headless components for the Drive UI (folders, files, uploads, sharing, trash, activity, comments, document nodes). The
editor engine itself (TipTap, Univer, …) is yours.

```bash
npm install @vaulter/vue
```

Peers: `vue@^3.4` and `@tiptap/pm@^3`. Node `>=22`. See [Frontend overview](../../docs/guides/vue.md) for the plugin and
the `createVaulterClient` setup, the composables and the components.

## Collaboration adapters

The public `VaulterCollaborationKey`, `CollaborationAdapter`, `CollaborationHandle` and
`CollaborationStatus` contracts let a host supply a protocol adapter. Without an adapter,
`VaulterDocumentNode` uses the REST autosave path. Realtime editing is available as a commercial add-on.

## Development in the monorepo

```bash
npm --prefix packages/vue run typecheck     # vue-tsc
npm --prefix packages/vue run api:check     # src/api/schema.d.ts matches openapi/vaulter-v1.yaml
npm --prefix packages/vue test              # vitest
```

`src/api` imports no Vue, editor or realtime packages (arch test `tests/arch/api-no-vue.test.ts`). Regenerate the types with
`npm --prefix packages/vue run api:generate` after the OpenAPI contract changes.
