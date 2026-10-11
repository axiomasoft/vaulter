---
title: Vue package (@vaulter/vue)
description: The Vaulter Drive UI for Vue 3 on REST v1 — the generated types and the typed client (If-Match, problem+json, API version check), the per-app viewer registry with its unsupported-item fallback, the composables and the accessible components, with a worked file-manager example.
outline: deep
---

# Vue package (`@vaulter/vue`)

`@vaulter/vue` is a Vue 3 + TypeScript package that talks to **REST API v1 only** and ships four layers:

1. **types generated from the OpenAPI contract** (`openapi/vaulter-v1.yaml`), so the client cannot drift from the server;
2. a **typed API client** (`createVaulterClient`) with one namespace per resource;
3. a **viewer registry** that decides which component shows a node, with a safe fallback;
4. **composables** and **components** that turn the client into a headless Drive UI (`VaulterDrive`, …).

```bash
npm install @vaulter/vue
```

::: tip Peer dependencies
`vue@^3.4`, `yjs@^13.6` and `@tiptap/pm@^3` are peers. The collaboration transport pulls in
`@hocuspocus/provider`; the editor itself (TipTap, Univer, …) is yours — see [viewers](#viewers).
:::

## Setup

### As a plugin

Install the plugin once. It builds a client from your options, creates **this application's** viewer registry,
provides the i18n and registers every component globally:

```ts
import { createApp } from 'vue'
import { VaulterPlugin } from '@vaulter/vue'
import App from './App.vue'

createApp(App)
  .use(VaulterPlugin, {
    baseUrl: '/api/vaulter/v1',               // default; the version segment is part of the base
    csrfToken: document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content,
    viewers: {
      'acme/diagram': () => import('./viewers/DiagramViewer.vue'), // your own viewer
    },
    i18n: { locale: 'ru' },
  })
  .mount('#app')
```

| Option | Type | Notes |
|--------|------|-------|
| `client` | `VaulterClient` | a pre-built client instead of one built from the options (tests / custom wiring) |
| `viewers` | `Record<string, ViewerEntry>` | merged over the built-in viewers; a key you give **replaces** the built-in one |
| `i18n` | `{ locale?, messages? }` | `en` (default) and `ru` ship with the package; `messages` overrides single strings per locale |
| `baseUrl`, `headers`, `csrfToken`, `fetch` | see below | forwarded to `createVaulterClient` |

### Client options

| Option | Type | Default | Notes |
|--------|------|---------|-------|
| `baseUrl` | `string` | `/api/vaulter/v1` | matches `routes.prefix` + `/v1`. Trailing slashes are trimmed |
| `headers` | `Record<string, string>` or `() => Record<string, string>` | — | extra request headers, or a getter (e.g. a rotating bearer token) |
| `csrfToken` | `string` | — | sent as `X-CSRF-TOKEN` |
| `fetch` | `typeof fetch` | global `fetch` | a custom fetch implementation |

Build it yourself for tests or per-request SSR wiring, and provide it:

```ts
import { createVaulterClient, provideVaulterClient } from '@vaulter/vue'

provideVaulterClient(app, createVaulterClient({ baseUrl: '/api/vaulter/v1' }))
```

Inside a component, `useVaulterClient()` resolves it and throws a clear error when none was provided.

## Types

`src/api/schema.d.ts` is **generated** from `openapi/vaulter-v1.yaml` by `openapi-typescript` and committed; never edit
it by hand. The package exports ready aliases — `Node`, `Drive`, `FileRevision`, `DocumentRevision`, `Grant`, `Share`,
`MintedShare`, `Comment`, `Activity`, `Change`, `CollaborationSession`, `Problem`, … — and the raw `components`,
`paths` and `operations` for everything else. Ids are always strings.

```bash
npm run api:generate   # regenerate after the contract changed
npm run api:check      # CI: fails when the committed file is stale or not tracked by git
```

## The API client

`createVaulterClient()` returns a `VaulterClient` with one namespace per resource, mirroring the v1 routes:

| Namespace | Covers |
|-----------|--------|
| `drives` | `get`, `archive`, `unarchive`, `delete` |
| `nodes` | `children`, `search`, `starred`, `shared`, `get`, `getWithIncludes`, `update` (rename), `setProperties`, `move`, `copy`, `trash`, `restore`, `purge`, `star`, `unstar`, `abilities` |
| `trash` | `list`, `empty` |
| `folders` | `create` |
| `files` | `upload`, `download`, `downloadUrl`, `revisions`, `addRevision`, `downloadRevision`, `restoreRevision`, `setRevisionRetention`, `deleteRevision` |
| `uploads` | `start` (resumable tus or S3 multipart), `show` |
| `documents` | `create`, `content`, `update`, `export`, `revisions`, `revision`, `snapshot`, `setRevisionRetention`, `restoreRevision`, `collaborationSession` |
| `grants`, `access` | `list`, `create`, `revoke`; `restrictInheritance`, `restoreInheritance` |
| `shares`, `publicShares` | `list`, `create`, `update`, `rotate`, `revoke`; `get`, `nodes`, `downloadUrl` |
| `comments`, `activity`, `changes` | threads; the activity feeds; `start` and `list` of the change feed |

```ts
const client = useVaulterClient()

const page = await client.nodes.children(driveId, { parent: null, perPage: 50 }) // { data, meta }
const folder = await client.folders.create(driveId, { name: 'Invoices' })
const renamed = await client.nodes.update(folder.id, { name: 'Bills' }, { etag: folder.etag })
```

### Cursor pages

Every list is **cursor-paginated** and returns `{ data, meta: { next_cursor, per_page } }` — never a page number or a
total. Pass `meta.next_cursor` back as `{ cursor }`. The change feed uses `{ cursor, limit }` and answers
`meta.has_more`.

### Preconditions: `If-Match` and ETags

A node's `etag` is an **opaque validator** of the representation you hold. Do not parse it, do not build one. Every
method that changes a node or a document takes it as a **required** `{ etag }` option and sends it as `If-Match`:

```ts
await client.nodes.trash(node.id, { etag: node.etag })
await client.nodes.move(node.id, targetId, { etag: node.etag, onConflict: 'rename' })
await client.documents.update(node.id, content, { etag: loaded.etag! })
```

A method without its `etag` does not type-check. The content of a document carries its validator in the `ETag`
response header, which the client returns as `content.etag` (`null` when the header is not exposed to the browser:
add `ETag` to `Access-Control-Expose-Headers` on a cross-origin API).

| Answer | `error.code` | What it means |
|--------|--------------|---------------|
| `412` | `node_sequence_mismatch`, `document_state_mismatch` | somebody changed the resource since you read it — read it again |
| `428` | `precondition_required` | the call went out without `If-Match`: an integration bug, not a state to recover from |

### Errors

A non-2xx response throws a `VaulterApiError` parsed from `application/problem+json` (RFC 9457):

```ts
try {
  await client.nodes.update(node.id, { name }, { etag: node.etag })
} catch (error) {
  if (error instanceof VaulterApiError) {
    error.status     // 422
    error.code       // 'invalid_node_name'  (a string; new codes are additive in v1)
    error.title      // localized summary
    error.detail     // localized explanation, or null
    error.requestId  // quote it in a bug report
    error.meta       // { reason: 'reserved_name' }
    error.errors     // field errors of a 422 validation_failed, or null
  }
}
```

A response that is not a problem document — an HTML 502 from a proxy, an empty 504 — becomes `code: 'http_<status>'`
instead of a parse error.

### API version

The first response that names `X-Vaulter-Api-Version` decides: a major other than `1` throws a `VaulterApiError` with
`code: 'api_version_mismatch'` for that call **and every later one** — a client of one major never half-works against
another.

### Uploads

`client.files.upload()` is the one-request path. `client.uploads.start()` returns a resumable upload (tus by default,
`protocol: 's3'` for S3 multipart) that always resumes from the server's offset:

```ts
const upload = client.uploads.start(driveId, file, { parentId, onProgress: (sent, total) => {} })
const node = await upload.start()   // later: await upload.abort()
```

Replacing the content of an existing file needs its tag: `{ replace: { nodeId, etag } }`.

## Viewers

The server tells **which viewer** shows a node in `node.presentation.viewer.key` (and its `props`). The registry maps
keys to components. It belongs to **one application** (`provide/inject`): two apps, or two SSR requests, never see each
other's viewers.

```ts
app.use(VaulterPlugin, {
  viewers: {
    'acme/diagram': () => import('./DiagramViewer.vue'),  // a plain function is a lazy loader
    'vaulter/download': MyDownloadViewer,                 // replaces the built-in one
  },
})
```

A viewer component receives `node` and `readonly` (plus the server's `viewer.props`, which can never override those
two). The built-in keys are `vaulter/tiptap`, `vaulter/univer` (the headless editing shells
`VaulterDocumentNode` / `VaulterSpreadsheet` — **you** mount the editor engine in their slot or replace the key),
`vaulter/pdf-preview`, `vaulter/image-preview` (shown through the authorised download, so a *previewer* without the
`download` ability sees no bytes), `vaulter/download` and `vaulter/unsupported`.

`<VaulterViewer :node="node" />` mounts the right one. A node **without** a presentation, with a key this app never
registered, or of a document type this app cannot handle (`presentation.type_available === false`) shows
`VaulterUnsupportedNode`: the name, the document type or mime, why it cannot be opened, and a download button — only
for a **file** and only when `abilities.download` is true. You can replace the fallback itself through
`vaulter/unsupported`.

## Composables

All composables call `useVaulterClient()`, so they run under a provided client. They return reactive refs and async
methods.

| Composable | Purpose |
|------------|---------|
| `useDrive(driveId)` | load one drive |
| `useNodes(driveId, parent?)` | list/navigate a folder; `createFolder`, `rename`, `move`, `copy`, `trash`, `restore`, `purge`, `star`, `unstar`; `conflict` |
| `useSharedNodes(query?)` | the flat "shared with me" listing |
| `useUpload(driveId)` | direct and resumable upload with progress |
| `useGrants(nodeId)`, `useShares(nodeId)` | grants and share links (the raw token is kept once in `lastCreated`) |
| `useActivity({ driveId } \| { nodeId })` | the activity feed |
| `useComments(nodeId)` | a thread |
| `useDocument(nodeId)` | load, debounced autosave, `conflict`, export |
| `useRevisions(nodeId)` | document history: `snapshot`, `setRetention`, `restore` |
| `useCollaboration(nodeId)` | the realtime session (below) |

**Mutations take the node, not its id**, because its `etag` is what the server compares:

```ts
const { nodes, rename, conflict } = useNodes(driveId)
await rename(node, 'new name')       // sends If-Match: node.etag
```

On a `412` the composable **re-reads the node** (the list already shows what won), sets `conflict` to
`{ nodeId, error }` and rethrows. A `428` is rethrown untouched. `useDocument` behaves the same for the document: a
stale save keeps your local `content`, refreshes `head` (server content and tag), and sets `conflict`; call `save()` to
overwrite with your edits or `load()` to take the server's version.

`VaulterDrive` shows New folder and Upload only when the current folder's `abilities.create`
is true. At the drive root it loads the root node's abilities; a failed or pending lookup
offers no creation action. Custom `toolbar` slots receive `canCreate` alongside `newFolder`.
Navigation and `refresh()` reload this permission, including the profile's create gate.

### Collaboration

`useCollaboration(nodeId, { supportedSchemaVersion, onEpochChanged })` asks for a session
(`documents.collaborationSession`) and opens the Hocuspocus provider on a `Y.Doc` you bind with TipTap's Collaboration
extension. The content always comes from the hub — never seed the editor.

- `readOnly` is true for a `ro` session **or** when the server's schema is newer than `supportedSchemaVersion`
  (`schemaOutdated`): mount the editor with `editable: false` and ask the user to update the application.
- When the hub closes the room with `4409 epoch_changed` (the document was restored) the old `Y.Doc` is destroyed, a new
  session and a **new** `Y.Doc` replace it, and `onEpochChanged` runs so you can re-read the content and its ETag.
- `4001` (token expired) and `4403` (lost the right to write) renew the session; `4422` is not retried.

A `connect()` that another `connect()`/`disconnect()` overtook rejects with `CollaborationCancelledError` — not a failed session.

`createCollaborationSession(client, nodeId, options)` does the same once, without the reopen logic.

## Components

All components are headless: class hooks (`vaulter-*`), slots and a few scoped styles. Row actions are gated by the
node's `abilities` — a button or a key is never offered for an ability the server did not grant.

| Component | Notes |
|-----------|-------|
| `VaulterDrive` | the file manager: breadcrumb, toolbar, list, dialogs. Props: `driveId`, `initialParentId`, `viewMode`, `actions`, `confirm`. Emits `open-node`, `node-selected`, `navigate` |
| `VaulterNodeList` | the list/grid; see [accessibility](#accessibility). Actions: `rename` (needs `edit`), `move`, `copy`, `trash`, `restore`, `purge` (needs `delete`), `download`, `share` |
| `VaulterBreadcrumb` | `path` of `{ id, name }`, emits `navigate` |
| `VaulterFolderPicker` | destination picker for a move; hides the moved folder and its subtree |
| `VaulterNameDialog` | name entry with [portable-v1 validation](#names) |
| `VaulterUploader` | drop zone, per-file progress, failures announced in an `aria-live` region |
| `VaulterGrantsPanel`, `VaulterSharePanel` | ACL and share-link management |
| `VaulterCommentsPanel` | a comment thread |
| `VaulterSharedWithMe` | the cross-drive listing |
| `VaulterViewer`, `VaulterUnsupportedNode` | see [viewers](#viewers) |
| `VaulterDocumentNode`, `VaulterSpreadsheet` | editing shells (load, autosave, collaboration, conflict) for **your** editor |

Display helpers: `humanByteSize(bytes)` and `nodeIconKind(node)`.

### Names

`VaulterNameDialog` validates a name with `portableNameReason(name)` — the same rules, in the same order, as the
server's default **`portable-v1`** policy (no `< > : " | ? *`, no trailing dot, no Windows device names or
`desktop.ini`, at most 255 UTF-8 bytes and UTF-16 units, NFC). The reasons are the server's `meta.reason`:
`empty`, `path_alias`, `separator`, `control_char`, `reserved_char`, `trailing_dot_or_space`,
`leading_or_trailing_space`, `reserved_name`, `too_long`. When the server still refuses a name (a drive with a stricter
policy), pass the error as `:server-error` and the dialog shows its reason.

### Accessibility

`VaulterNodeList` is a WAI-ARIA **listbox** of options with one tab stop (roving `tabindex`):

| Key | Does |
|-----|------|
| `↑` `↓`, `Home`, `End` | move the focus |
| `Enter` | open (a folder navigates) |
| `Space` | select |
| `F2` | rename (if the caller may edit) |
| `Delete` | move to the trash with the node's `etag` (if the caller may trash) |

After a node leaves the list (trash, move, purge) the focus moves to its neighbour instead of falling back to the page.
Failures — a rejected mutation (`role="alert"`) and a failed upload (`aria-live="assertive"`) — are announced.

## Worked example: a file manager

```vue
<script setup lang="ts">
import { shallowRef } from 'vue'
import { VaulterDrive, VaulterViewer, VaulterGrantsPanel, type Node } from '@vaulter/vue'

const props = defineProps<{ driveId: string }>()

const openNode = shallowRef<Node | null>(null)    // opened in a viewer
const grantsNode = shallowRef<Node | null>(null)  // managed in the side panel

function onNodeSelected(node: Node) {
  // The server decides who may share; the UI only follows.
  grantsNode.value = node.abilities.share ? node : null
}
</script>

<template>
  <VaulterDrive
    :drive-id="props.driveId"
    :actions="['rename', 'move', 'copy', 'trash', 'download', 'share']"
    @open-node="openNode = $event"
    @node-selected="onNodeSelected"
  />

  <aside v-if="grantsNode">
    <VaulterGrantsPanel :node-id="grantsNode.id">
      <template #subject="{ setSubject }">
        <button type="button" @click="setSubject({ type: 'user', id: '42' })">Grant to Alice</button>
      </template>
    </VaulterGrantsPanel>
  </aside>

  <dialog v-if="openNode" open>
    <button type="button" @click="openNode = null">Close</button>
    <!-- Whatever viewer the server chose; unknown ones fall back to a safe "download" view. -->
    <VaulterViewer :node="openNode" />
  </dialog>
</template>
```

Register the editor for documents once, in the plugin options — the viewer receives the node and mounts your TipTap
inside `VaulterDocumentNode`'s slot:

```ts
app.use(VaulterPlugin, { viewers: { 'vaulter/tiptap': () => import('./AppDocumentViewer.vue') } })
```

## See also

- [REST API reference](/reference/rest-api) — the contract this package is generated from.
- [Files & folders](/guide/drive) — the Drive routes behind these composables.
- [Authorization](/guide/authorization) — the abilities the components gate on.
- [Realtime collaboration](/guide/collaboration) — the Yjs transport `useCollaboration` opens.
- [Upgrade guide](https://github.com/axiomasoft/vaulter/blob/main/UPGRADE.md) — what changed from the pre-v1 package.
