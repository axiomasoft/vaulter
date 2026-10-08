---
title: Vue package (@vaulter/vue)
description: The Vaulter Drive UI for Vue 3 — the typed API client, the Drive composables (useNodes, useUpload, useNodeGrants, useNodeShares, useDocumentNode, useNodeCollaboration) and the components (VaulterDrive, breadcrumb, list, uploader, grants/share panels, document node), with a worked file-manager example.
outline: deep
---

# Vue package (`@vaulter/vue`)

`@vaulter/vue` is a Vue 3 + TypeScript package that ships three layers:

1. a **typed API client** (`createVaulterClient`) that talks to the Vaulter REST API;
2. **composables** that wrap the client in reactive state (`useNodes`, `useUpload`, …);
3. **components** that compose the composables into a headless Drive UI (`VaulterDrive`, …).

Everything is additive: the new **Drive** surface (file/folder/document tree) lives alongside the
legacy document-centric components. This page documents the Drive surface — the one you build new
apps on.

```bash
npm install @vaulter/vue
```

::: tip Peer dependency
`@vaulter/vue` lists `vue@^3.4` as a peer dependency. The collaboration transport additionally pulls
in `@hocuspocus/provider`, `@tiptap/*` and `yjs` (already declared as dependencies).
:::

## Setup

### As a plugin

Install the plugin once; it creates a client from your options and provides it to the whole app, and
registers every component globally:

```ts
import { createApp } from 'vue'
import { VaulterPlugin } from '@vaulter/vue'
import App from './App.vue'

createApp(App)
  .use(VaulterPlugin, {
    baseUrl: '/api/vaulter',                  // default
    csrfToken: document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content,
  })
  .mount('#app')
```

`VaulterPluginOptions` extends `VaulterClientOptions` (below) and adds one field:

| Option | Type | Notes |
|--------|------|-------|
| `client` | `VaulterClient` | provide a pre-built client instead of constructing one from the options (tests / custom wiring) |

### Providing a pre-built client

For tests or per-request SSR wiring, build the client yourself and provide it:

```ts
import { createVaulterClient, provideVaulterClient } from '@vaulter/vue'

const client = createVaulterClient({ baseUrl: '/api/vaulter' })
provideVaulterClient(app, client)
```

Inside any descendant component, resolve it with `useVaulterClient()`. It throws a clear error if no
client was provided — install the plugin or call `provideVaulterClient()` in a parent.

### Client options

`createVaulterClient(options)` and the plugin both accept `VaulterClientOptions`:

| Option | Type | Default | Notes |
|--------|------|---------|-------|
| `baseUrl` | `string` | `/api/vaulter` | matches the backend `routes.prefix` config. Trailing slashes are trimmed |
| `headers` | `Record<string, string>` or `() => Record<string, string>` | — | extra request headers, or a getter (e.g. a rotating bearer token) |
| `csrfToken` | `string` | — | sent as `X-CSRF-TOKEN` |
| `fetch` | `typeof fetch` | global `fetch` | a custom fetch implementation |

The client sends `credentials: 'same-origin'` and `Accept: application/json`; bodies are JSON unless
the call uploads `FormData` or streams a tus/S3 chunk.

::: warning Errors
A non-2xx response throws a `VaulterApiError` with `status`, `message`, an `errors`
(`Record<string, string[]>`, Laravel validation shape) and the raw `Response`. Catch it to render
validation errors or a 403.
:::

## The API client

`createVaulterClient()` returns a `VaulterClient` whose methods are grouped into **namespaces**.
The Drive (M1–M4) namespaces are:

| Namespace | Covers | Backing routes (under `baseUrl`) |
|-----------|--------|----------------------------------|
| `storages` | a single storage container + its root/usage | `GET /storages/{storage}` |
| `nodes` | the folder/file/document tree CRUD + search + trash + download | `/storages/{storage}/nodes`, `/nodes/{node}/*` |
| `uploads` | direct + resumable (tus / S3-multipart) uploads | `/storages/{storage}/uploads`, `/resumable-uploads/*` |
| `grants` | per-node ACL grants | `/nodes/{node}/grants` |
| `nodeShares` | bearer share-links + public resolve/download | `/nodes/{node}/shares`, `/share/{token}*` |
| `activity` | the append-only activity feed | `/storages/{storage}/activity`, `/nodes/{node}/activity` |
| `documentNodes` | document-node content, versions, handler, export | `/storages/{storage}/documents`, `/nodes/{node}/content`, `/nodes/{node}/document-versions/*` |
| `nodeCollaboration` | the realtime collab session handshake | `POST /nodes/{node}/collab/token` |

The legacy document-centric namespaces (`documents`, `versions`, `folders`, `permissions`,
`comments`, `shares`, `collaboration`) remain for hosts still on that surface.

```ts
import { useVaulterClient } from '@vaulter/vue'

const client = useVaulterClient()

const storage = await client.storages.get(storageId)
const page    = await client.nodes.list(storageId, { parentId: null }) // CursorPage<Node>
const folder  = await client.nodes.createFolder(storageId, { name: 'Invoices' })
const node    = await client.uploads.upload(storageId, file, { parentId: folder.id })
```

List endpoints are **cursor-paginated**: `client.nodes.list(...)` resolves a `CursorPage<Node>`
(`{ data, meta }`); read `meta.next_cursor` and pass it back as `{ cursor }` for the next page.

## Composables

All Drive composables call `useVaulterClient()` internally, so they must run inside a component that
sits under the provided client. Each exposes reactive refs plus async methods that mutate them
optimistically.

### `useNodes`

Reactive listing of a folder's children with navigation and the node mutations wired to the client.

```ts
import { useNodes } from '@vaulter/vue'

const {
  nodes, parentId, cursor, loading, error,
  refresh, loadMore, navigate,
  createFolder, rename, move, copy, trash, restore, forceDelete,
} = useNodes(storageId, () => currentFolderId.value)
```

| Member | Type | Notes |
|--------|------|-------|
| `nodes` | `ShallowRef<Node[]>` | the current folder's children |
| `parentId` | `Ref<NodeId \| null>` | the listed folder (`null` = the storage root) |
| `cursor` | `Ref<CursorMeta \| null>` | the pagination meta of the last load |
| `loading` / `error` | `Ref<boolean>` / `Ref<unknown>` | request state |
| `refresh()` | `Promise<Node[]>` | (re)load the current folder's first page |
| `loadMore()` | `Promise<Node[]>` | append the next cursor page (no-op when there is none) |
| `navigate(parentId)` | `Promise<Node[]>` | enter a folder (`null` = root) and load it |
| `createFolder(payload)` | `Promise<Node>` | create a folder (reflected in the list when it lands here) |
| `rename` / `move` / `copy` / `trash` / `restore` / `forceDelete` | `Promise<Node \| void>` | the node mutations — the list updates optimistically (rename keeps the row, move/trash drop it, copy/restore append) |

Pass `parentRef` (a ref or getter) to drive navigation externally — when it changes, the list
reloads.

### `useSharedNodes`

Reactive **"shared with me"** listing over the cross-storage `GET /shared` endpoint — the nodes
the actor holds a live allow-grant on (re-resolved exactly server-side). A **flat** list: there is
no `parentId` / `navigate` here, because the set spans storages. The endpoint is **length-aware**
(`page` / `total`), so paging is `page`-based.

```ts
import { useSharedNodes } from '@vaulter/vue'

const { nodes, meta, loading, error, hasMore, refresh, loadMore } = useSharedNodes()
```

| Member | Type | Notes |
|--------|------|-------|
| `nodes` | `ShallowRef<Node[]>` | the shared nodes (flat, cross-storage) |
| `meta` | `ShallowRef<PageMeta \| null>` | page counters + total of the last load |
| `loading` / `error` | `Ref<boolean>` / `Ref<unknown>` | request state |
| `hasMore` | `Ref<boolean>` | whether another page exists |
| `refresh()` | `Promise<Node[]>` | (re)load the first page, replacing the list |
| `loadMore()` | `Promise<Node[]>` | append the next page (no-op when there is none) |

It does **not** auto-load — call `refresh()` on mount (the idiom the Drive composables share).
Pass an optional `SharedNodesQuery` (`{ page, perPage }`) to set the initial page / size.

### `useUpload`

Upload into a storage with progress, exposing both the direct and resumable (tus) paths.

```ts
import { useUpload } from '@vaulter/vue'

const { uploading, error, sent, total, progress, upload, uploadResumable, abort } =
  useUpload(storageId)

await upload(file, { parentId })            // single-request
await uploadResumable(largeFile, {          // chunked, resumable, progress-reporting
  parentId,
  onProgress: (sent, total) => { /* … */ },
})
```

| Member | Type | Notes |
|--------|------|-------|
| `uploading` / `error` | `Ref<boolean>` / `Ref<unknown>` | request state |
| `sent` / `total` | `Ref<number>` | bytes of the active resumable upload |
| `progress` | `ComputedRef<number>` | `sent / total`, `0` when idle |
| `upload(file, options?)` | `Promise<Node>` | direct single-request upload |
| `uploadResumable(file, options?)` | `Promise<Node>` | drives the tus session, resuming from the **server** offset |
| `abort()` | `Promise<void>` | abort the active resumable upload |

`useUpload` takes an optional second arg — a pre-resolved client — for spinning up a per-file
uploader **outside** the `setup()` injection context (e.g. one instance per dropped file), where
`inject()` is no longer available. `VaulterUploader` uses exactly this pattern.

### `useNodeGrants`

Reactive management of a node's per-node ACL grants.

```ts
import { useNodeGrants } from '@vaulter/vue'

const { grants, loading, error, list, grant, revoke } = useNodeGrants(nodeId)

await list()
await grant({ subjectType: 'user', subjectId: 42, role: 'writer', scope: 'subtree' })
await revoke({ subjectType: 'user', subjectId: 42 })
```

| Member | Type | Notes |
|--------|------|-------|
| `grants` | `ShallowRef<NodeGrant[]>` | the node's grants |
| `list()` | `Promise<NodeGrant[]>` | load the grants |
| `grant(payload)` | `Promise<NodeGrant>` | add a grant (prepended to the list) |
| `revoke(payload)` | `Promise<void>` | drop **every** grant the subject holds |

The grant payload (`GrantNodeAccessPayload`) carries `subjectType`, `subjectId`, `role`
(`previewer` / `reader` / `commenter` / `writer` / `organizer` / `owner`), and optional `scope`
(`item` / `subtree`) and `expiresAt`.

### `useNodeShares`

Reactive management of a node's **bearer share-links**. The raw token surfaces **exactly once** via
`lastCreated`; the listed shares only ever carry a `token_prefix`.

```ts
import { useNodeShares } from '@vaulter/vue'

const { shares, lastCreated, loading, error, list, create, revoke, clearLastCreated } =
  useNodeShares(nodeId)

const created = await create({ level: 'reader', scope: 'item', requireLogin: true })
// created.token + created.url are present HERE — and only here.
clearLastCreated() // forget the secret so it never lingers in memory
```

| Member | Type | Notes |
|--------|------|-------|
| `shares` | `ShallowRef<NodeShare[]>` | the node's links (no raw token) |
| `lastCreated` | `ShallowRef<NodeShareCreated \| null>` | the last-minted raw token + URL, held once |
| `create(payload?)` | `Promise<NodeShareCreated>` | mint a link; the response carries `token` + `url` once |
| `revoke(shareId)` | `Promise<void>` | revoke a link |
| `clearLastCreated()` | `void` | forget the last-minted secret |

### `useDocumentNode`

Reactive editing of a **document node**: load its head content, edit with a debounced autosave,
snapshot/restore named versions, resolve its handler, and export.

```ts
import { useDocumentNode } from '@vaulter/vue'

const {
  content, head, versions, handler, loading, saving, error,
  load, setContent, flush, save,
  loadVersions, createNamedVersion, restoreVersion, loadHandler, exportAs,
} = useDocumentNode(nodeId, { debounceMs: 800 })

await load()                                  // fetch the head content
setContent(newTipTapDoc)                       // schedules a debounced autosave
await flush()                                  // force any pending autosave now
await createNamedVersion('Before edit', true)  // snapshot, keep forever
```

| Member | Type | Notes |
|--------|------|-------|
| `content` | `ShallowRef<TipTapDocument \| null>` | the working content |
| `head` | `ShallowRef<DocumentVersion \| null>` | the current-head version |
| `versions` | `ShallowRef<DocumentVersion[]>` | loaded named/auto versions |
| `handler` | `ShallowRef<NodePresentation \| null>` | the resolved document handler |
| `saving` | `Ref<boolean>` | true while a save is in flight |
| `setContent(content, options?)` | `void` | set working content + schedule a debounced autosave |
| `flush()` | `Promise<DocumentVersion \| null>` | force-flush a pending autosave (null when nothing pending) |
| `save(options?)` | `Promise<DocumentVersion>` | save immediately (no debounce) |
| `createNamedVersion(label, keepForever?)` | `Promise<DocumentVersion>` | snapshot a named version |
| `restoreVersion(versionId)` | `Promise<DocumentVersion>` | restore a version (updates head + content) |
| `exportAs(format?)` | `Promise<NodeDocumentExport>` | export the node's document |

`setContent`'s `options.yjsState` carries the optional base64 Yjs CRDT update (the realtime source of
truth) — pass it when bridging from a Yjs editor.

### `useNodeCollaboration`

Open a realtime collaboration session for a document node. It fetches the room token, then builds a
Yjs session over it (Hocuspocus via `@hocuspocus/provider`, Reverb via a host-supplied bridge).

```ts
import { useNodeCollaboration } from '@vaulter/vue'

const {
  doc, session, handle,
  connecting, connected, readOnly, collaborators, error,
  fetchSession, connect, disconnect,
} = useNodeCollaboration(nodeId)

await connect()        // mints the token AND opens the Yjs session
// bind `doc` to TipTap via the Collaboration extension; mount read-only when readOnly is true
onBeforeUnmount(disconnect)
```

| Member | Type | Notes |
|--------|------|-------|
| `doc` | `Y.Doc` | the shared Yjs document to bind to TipTap |
| `session` | `ShallowRef<NodeCollaborationSession \| null>` | the resolved backend descriptor |
| `connected` | `Ref<boolean>` | a live connection flag |
| `readOnly` | `Ref<boolean>` | writes disallowed (from the actor's backend ability) |
| `collaborators` | `Ref<Collaborator[]>` | the remote participants (awareness) |
| `fetchSession()` | `Promise<NodeCollaborationSession>` | fetch the token only (build your own provider) |
| `connect()` | `Promise<NodeCollaborationSessionHandle>` | fetch the token AND open the session |
| `disconnect()` | `void` | tear down the live session (idempotent) |

See [Realtime collaboration](/guide/collaboration) for the transport details.

::: details Other composables
The package also exports `useStorage` (load a single storage), `useActivity` (the activity feed),
`useComments` (a node's comment thread), `useNodeGrants` / `useNodeShares` (per-node ACL and
share-links) and `useSpreadsheet` (the headless workbook shell). Full coverage lives in
[Vue composables](/guide/vue).
:::

## Components

All components are **headless-ish**: minimal scoped styles, class hooks (`vaulter-*`) and slots
throughout, so you can fully restyle them.

### `VaulterDrive`

The headline file-manager: a toolbar (new folder, upload, list/grid toggle, search), a breadcrumb
and the node listing. Folder opens navigate internally; file/document opens emit `open-node` for the
host. Row actions wire to `useNodes`, with a confirm gate on the destructive ones.

**Props**

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `storageId` | `StorageId` | — | required |
| `initialParentId` | `NodeId \| null` | `null` | the folder to open initially (`null` = root) |
| `viewMode` | `'list' \| 'grid'` | `'list'` | initial view |
| `actions` | `NodeAction[]` | `['rename','move','trash','download','share']` | row actions to offer (still filtered per node abilities) |
| `confirm` | `(message) => boolean \| Promise<boolean>` | `window.confirm` | the gate for destructive actions |

**Emits**

| Event | Payload | When |
|-------|---------|------|
| `open-node` | `node: Node` | a non-folder node was opened — the host opens/edits/previews it |
| `node-selected` | `node: Node` | a node was selected (single-click) |
| `navigate` | `parentId: NodeId \| null` | the listed folder changed |

**Slots**: `toolbar` (with `new-folder`, `view`, `set-view`, `search-term`), `error`, `empty`.
**Exposes**: `refresh`, `goTo`, `nodes`, `displayNodes`, `selectedId`, `view`.

### `VaulterDriveBreadcrumb`

The root → current-node ancestry as clickable crumbs. Navigation is **emit-only** — the host decides
what `navigate(null = root)` does.

| Prop | Type | Default |
|------|------|---------|
| `path` | `Node[]` | `[]` (a node's `breadcrumb`) |
| `rootLabel` | `string` | `'Drive'` |

Emits `navigate: NodeId \| null`. Slots: `root`, `crumb` (per ancestor, with `node`, `is-current`).

### `VaulterNodeList`

The node listing — a list or grid of nodes with a kind/mime icon, name, size and modified date.
Per-row actions are gated on `node.abilities` (an action the actor can't do is never offered).

**Props**

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `nodes` | `Node[]` | — | required |
| `viewMode` | `'list' \| 'grid'` | `'list'` | |
| `selectedId` | `NodeId \| null` | `null` | single-select highlight |
| `actions` | `NodeAction[]` | `['rename','move','trash','download','share']` | offered set ∩ each node's abilities |
| `loading` | `boolean` | `false` | renders the `loading` slot |

`NodeAction` is `'rename' \| 'move' \| 'copy' \| 'trash' \| 'restore' \| 'forceDelete' \| 'download' \| 'share'`.

**Emits**: `select(node)`, `open(node)` (double-click / Enter), `action(action, node)`.
**Slots**: `loading`, `empty`, `icon` (with `node`, `kind`), `name`, `actions` (with `node`,
`actions`, `run`).

### `VaulterUploader`

A standalone drag-drop + file-picker upload zone driving `useUpload`. Small files go direct; files
past `resumableThreshold` use the resumable (tus) path with byte progress and cancel. Each file gets
its **own** `useUpload` so progress is tracked independently.

**Props**

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `storageId` | `StorageId` | — | required |
| `parentId` | `NodeId \| null` | `null` | the folder to upload into (`null` = root) |
| `resumableThreshold` | `number` | `5 * 1024 * 1024` | bytes above which the tus path is used |
| `accept` | `string` | — | the file input `accept` attribute |
| `multiple` | `boolean` | `true` | allow selecting more than one file |
| `disabled` | `boolean` | `false` | |

**Emits**: `uploaded(node)`, `error(file, error)`.
**Slots**: `dropzone` (with `open-picker`, `is-dragging`), `item` (with `item`, `progress`, `cancel`).
**Exposes**: `openPicker`, `items`, `clearFinished`.

### `VaulterNodeGrantsPanel`

Per-node ACL management over `useNodeGrants` — list grants, add a grant, revoke one. The host owns
**subject selection** (it can't know your users/roles/teams), delegated to a `subject` slot or a
`resolveSubject` prop.

**Props**

| Prop | Type | Default |
|------|------|---------|
| `nodeId` | `NodeId` | — |
| `autoLoad` | `boolean` | `true` |
| `roles` | `GrantRoleName[]` | `['previewer','reader','commenter','writer','organizer','owner']` |
| `scopes` | `GrantScopeName[]` | `['item','subtree']` |
| `resolveSubject` | `(type, id) => GrantSubject \| Promise<GrantSubject>` | passes raw type/id through |

`GrantSubject` is `{ type: string; id: WorkspaceId; label?: string }`.
**Emits**: `granted(grant)`, `revoked(subject)`.
**Slots**: `loading`, `item` (with `grant`, `revoke`), `subject` (with `set-subject`, `subject-type`,
`subject-id`), `error`.

### `VaulterNodeSharePanel`

Bearer share-link management over `useNodeShares`. On create the raw token + URL are surfaced
**exactly once** (via `lastCreated`) with a copy button; the listed shares only carry a
`token_prefix`.

**Props**

| Prop | Type | Default |
|------|------|---------|
| `nodeId` | `NodeId` | — |
| `autoLoad` | `boolean` | `true` |
| `levels` | `GrantLevelName[]` | `['previewer','reader','writer']` |
| `scopes` | `GrantScopeName[]` | `['item','subtree']` |

**Emits**: `created(share)`, `revoked(shareId)`.
**Slots**: `created` (with `share`, `url`, `copy`, `dismiss`, `copied`), `loading`, `item`, `error`.

### `VaulterDocumentNode`

The bridge from a document node's **handler descriptor** to a concrete editor/preview UI. It loads
the handler (`useDocumentNode.loadHandler`) and head content, then mounts the component the handler's
`ui.component` maps to — the mapping is your `componentMap` prop, so hosts register their own editors
per handler. Unmapped handlers fall back to a preview/download slot.

When the handler is **collaborative** and collaboration is enabled, it opens a Yjs session
(`useNodeCollaboration`) and hands the shared `doc` to the editor (bind it via the TipTap
Collaboration extension); the autosave path is bypassed.

**Props**

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `nodeId` | `NodeId` | — | required |
| `componentMap` | `Record<string, Component>` | `{}` | maps a handler's `ui.component` (or `id`) to an editor component |
| `readonly` | `boolean` | `false` | force read-only |
| `autoLoad` | `boolean` | `true` | load on mount |
| `debounceMs` | `number` | `800` | autosave debounce |
| `collaboration` | `boolean` | `true` | enable realtime collab when the handler declares it |
| `collaborationProviders` | `Partial<…>` | `{}` | provider factories (Hocuspocus override / Reverb bridge) |

The TipTap-editor keys `VaulterEditor`, `tiptap`, `richtext`, `rich-text` resolve to a host-supplied
editor under one of those `componentMap` keys.

**Emits**: `ready(handler)`, `saved(content)`, `collaborating(readonly)`.
**Slots**: a default slot (with `handler`, `content`, `readonly`, `set-content`, `flush`,
`collaborative`, `ydoc`, `connected`, `collaborators`), plus `loading`, `fallback`, `error`.

The `DocumentNodeEditorProps` type describes what a mapped editor component receives — `nodeId`,
`content`, `handler`, `readonly`, `onChange`, and (in collaborative mode) `collaborative`, `ydoc`,
`connected`, `collaborators`.

### `VaulterSharedWithMe`

The cross-storage **"shared with me"** view over [`useSharedNodes`](#usesharednodes): a **flat**
listing of the nodes shared with the actor, with no folder navigation (the nodes span storages, so
every open emits `open-node` for the host to route). It reuses `VaulterNodeList` for the rendering
and owns only the load / refresh / empty / error chrome plus a "Load more" pager.

**Props**

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `viewMode` | `'list' \| 'grid'` | `'list'` | |
| `actions` | `NodeAction[]` | `['download','share']` | row actions to offer (still filtered per node abilities) |
| `query` | `SharedNodesQuery` | `undefined` | initial `{ page, perPage }` |

**Emits**: `open-node(node)` (cross-storage — the host routes it), `node-selected(node)`,
`action(action, node)`.
**Slots**: `toolbar` (with `refresh`, `loading`), `title`, `refresh-label`, `error`, `empty`,
`name` / `context` (per row), `load-more`.
**Exposes**: `refresh`, `loadMore`.

### `VaulterFolderPicker`

A modal destination picker for **move**: it walks the storage's **folder** tree (folders only) with
a breadcrumb and a "Move here" action at the current location. Headless to match the rest of the
Drive — minimal scoped styles, class hooks + slots, navigation is emit-driven. Illegal destinations
are never offered: the node being moved is hidden from the listing (so you can't descend into its
own subtree), and "Move here" is disabled at the node's *current* parent (a no-op move) or the node
itself. The storage root (a `null` parent) is a selectable destination.

**Props**

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `storageId` | `StorageId` | — | required |
| `node` | `Node` | — | required — the node being moved (its self/subtree + current parent are excluded) |
| `open` | `boolean` | `false` | whether the picker is shown (re-opening resets to root) |
| `rootLabel` | `string` | `'Drive'` | label of the root crumb / root destination |

**Emits**: `picked(targetId: NodeId \| null)` (`null` = the storage root), `close()`.
**Slots**: `header` (with `node`), `folder` (per folder), `loading`, `empty`, `error`, `footer`
(with `move-here`, `can-move-here`, `current-parent-id`, `cancel`).
**Exposes**: `goTo`, `moveHere`, `currentParentId`, `folders`, `breadcrumb`, `canMoveHere`,
`loading`.

### `VaulterNameDialog`

A tiny accessible **name-input** dialog — one text field for "new folder", "rename", or any
name-entry flow. It mirrors `VaulterFolderPicker`'s modal shape (backdrop + panel, `role="dialog"`,
Esc / backdrop to close, focus-on-open). On open the field is seeded from `initialValue`, focused
and **fully selected** (so a rename pre-selects the current name for instant overtyping). `submit`
carries the **trimmed** value and only fires when non-empty; the confirm button is disabled while
the trim is empty.

**Props**

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `open` | `boolean` | `false` | whether the dialog is shown |
| `title` | `string` | `'Name'` | heading text (e.g. `'New folder'` / `'Rename'`) |
| `label` | `string` | `'Name'` | the input's accessible label |
| `initialValue` | `string` | `''` | prefill — empty for new-folder, the node name for rename |
| `confirmLabel` | `string` | `'Save'` | the confirm button's text |
| `placeholder` | `string` | `''` | the input placeholder |

**Emits**: `submit(name: string)` (trimmed, non-empty only), `close()`.
**Slots**: `header` (with `title`), `footer` (with `submit`, `cancel`, `value`, `can-submit`).
**Exposes**: `value`, `submit`.

### Display helpers

Two pure helpers back the list rendering and are exported for reuse:

```ts
import { humanByteSize, nodeIconKind } from '@vaulter/vue'

humanByteSize(1_500_000)   // '1.4 MB' — null/undefined → '' (blank cell)
nodeIconKind(node)         // 'folder' | 'document' | 'image' | 'pdf' | 'archive' | 'audio' | 'video' | 'file'
```

`nodeIconKind` collapses several mime families (image/pdf/archive/audio/video) into one visual
bucket; restyle via the `vaulter-node-icon--<bucket>` class or the per-row `icon` slot.

::: details Comments panel
The package also exports `VaulterCommentsPanel` — the slot-driven thread for a document node's
comments (prop `node-id`). See [Comments](/guide/comments) for its API.
:::

## Worked example: a file manager

A complete drive screen — list, navigate, upload, open a document for editing and manage grants —
assembled from `VaulterDrive`, `VaulterDocumentNode` and `VaulterNodeGrantsPanel`:

```vue
<script setup lang="ts">
import { ref, shallowRef } from 'vue'
import {
  VaulterDrive,
  VaulterDocumentNode,
  VaulterNodeGrantsPanel,
  type Node,
} from '@vaulter/vue'
import AppTipTapEditor from './AppTipTapEditor.vue' // the host-supplied TipTap editor

const props = defineProps<{ storageId: string }>()

// The node currently opened for editing (a file/document), null while browsing.
const openNode = shallowRef<Node | null>(null)
// The node whose grants we're managing in a side panel.
const grantsNode = shallowRef<Node | null>(null)

// Map the handler's ui.component to a concrete editor. The built-in TipTap keys
// (VaulterEditor / tiptap / richtext / rich-text) resolve to the host editor below.
const componentMap = { VaulterEditor: AppTipTapEditor }

function onOpenNode(node: Node) {
  if (node.kind === 'document') {
    openNode.value = node
  } else {
    // a plain file — download it
    window.open(`/api/vaulter/nodes/${node.id}/download`, '_blank')
  }
}

function onNodeSelected(node: Node) {
  // surface grants management for the selected node when the actor may share it
  grantsNode.value = node.abilities.share ? node : null
}
</script>

<template>
  <div class="drive-screen">
    <VaulterDrive
      :storage-id="props.storageId"
      :actions="['rename', 'move', 'copy', 'trash', 'download', 'share']"
      @open-node="onOpenNode"
      @node-selected="onNodeSelected"
    />

    <aside v-if="grantsNode" class="drive-screen__grants">
      <h3>Sharing — {{ grantsNode.name }}</h3>
      <VaulterNodeGrantsPanel :node-id="grantsNode.id">
        <!-- The host owns subject selection; wire your own picker here. -->
        <template #subject="{ setSubject }">
          <button type="button" @click="setSubject({ type: 'user', id: 42, label: 'Alice' })">
            Grant to Alice
          </button>
        </template>
      </VaulterNodeGrantsPanel>
    </aside>

    <dialog v-if="openNode" open class="drive-screen__editor">
      <header>
        <strong>{{ openNode.name }}</strong>
        <button type="button" @click="openNode = null">Close</button>
      </header>
      <VaulterDocumentNode
        :node-id="openNode.id"
        :component-map="componentMap"
        @saved="() => { /* toast 'Saved' */ }"
      />
    </dialog>
  </div>
</template>
```

Key points:

- `VaulterDrive` handles navigation, upload and the destructive-action confirms internally; you only
  react to `open-node` and `node-selected`.
- A `document` node opens in `VaulterDocumentNode`, which resolves the handler, mounts your TipTap
  editor (via `componentMap`) and autosaves with debounce — or runs collaboratively if the handler
  declares it.
- `VaulterNodeGrantsPanel` manages ACL grants; you supply only the subject picker.

## See also

- [Filament admin plugin](/guide/filament) — the admin-side counterpart.
- [Files & folders](/guide/drive) — the Drive REST routes behind these composables.
- [Authorization](/guide/authorization) — the abilities the components gate on.
- [Realtime collaboration](/guide/collaboration) — the Yjs transport `useNodeCollaboration` opens.
