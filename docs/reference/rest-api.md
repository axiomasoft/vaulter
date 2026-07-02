---
title: REST API reference
description: Every Vaulter REST route — Drive, sharing, resumable uploads, document nodes, collaboration and the legacy document API — with method, path, params, gates and response.
outline: deep
editLink: false
---

# REST API reference

This is the exhaustive, route-by-route reference for the Vaulter HTTP surface,
generated against `packages/core/routes/api.php`. Every route is mounted under the
configured prefix and middleware:

```php
// config/vaulter.php
'route_prefix' => 'api/vaulter',   // Config::routePrefix()
'middleware'   => ['api'],         // Config::middleware()
```

So a path shown as `storages/{storage}` resolves to
`/api/vaulter/storages/{storage}`.

## Conventions

- **`{storage}` / `{node}`** are **route-model bound** to the swappable
  `config('vaulter.models.storage')` / `config('vaulter.models.node')`. Every Drive
  route also applies `SubstituteBindings` explicitly, so binding works even if the
  host trims its `api` group.
- **`{upload_id}`, `{share}`, `{endpoint}`, `{token}`, `{version}`** are plain
  scalars (not model-bound) — the controller resolves them and asserts they belong
  to the authorized target.
- **Authorization** on the Drive surface is the [`AccessResolver`](/guide/authorization):
  every endpoint guards an [`Ability`](/reference/contracts) on the node (or `canCreateInWorkspace`
  on the destination). A denial — or an unauthenticated request — returns **403**
  (`PermissionDeniedException`). Listings are filtered **per node**, so a nearer
  deny drops a row.
- **Actor** is the authenticated `request->user()` (its auth identifier is the
  attributed `actorId`).
- **Listings** are cursor-paginated; pass `?per_page=` (default 50, clamped 1–200)
  and `?cursor=`.
- Responses are Laravel API resources wrapped in a top-level `data` key.

### Feature gates

Some route groups only mount when a feature flag is enabled in
`config('vaulter.features.*')` (`Config::featureEnabled()`):

| Feature flag | Gates |
|---|---|
| `document_versions` | document-node routes + collaboration handshake + collab webhooks |
| `uploads` | resumable-upload routes (tus + S3-multipart) |
| `reliability` | webhook-endpoint management routes |

---

## Drive

The unified `v_nodes` file/folder tree (`StorageController`, `NodeController`,
`DownloadController`). Storage-collection endpoints carry `{storage}` in the path;
node-scoped endpoints derive the storage from the bound `{node}`.

### Storages

#### `GET storages/{storage}`

Read a storage container's metadata, root node and usage. → `StorageController::show`

**Response** (`StorageResource`):

```json
{
  "data": {
    "id": "01J…",
    "panel": "default",
    "owner": { "type": "App\\Models\\Project", "id": 1 },
    "name": "Project storage",
    "slug": null,
    "root_node_id": "01J…",
    "disk": "local",
    "storage_type": "default",
    "used_bytes": 10485760,
    "quota_bytes": null,
    "status": "active",
    "created_at": "…",
    "updated_at": "…",
    "abilities": { "view": true, "create_folder": true, "upload": true, "manage": true }
  }
}
```

### Listing & navigation

#### `GET storages/{storage}/nodes`

List the direct children of a folder (or the storage root). → `NodeController::index`

| Param | In | Notes |
|---|---|---|
| `parent_id` | query | optional; omit for the root |
| `per_page`, `cursor` | query | pagination |

**Gate:** `Ability::View` on the parent. **Response:** a paginated collection of
`NodeResource`.

#### `GET nodes/{node}`

A single node plus its `breadcrumb`; a folder also embeds its direct `children`.
→ `NodeController::show`

**Gate:** `Ability::View`. **Response** (`NodeResource`):

```json
{
  "data": {
    "id": "01J…",
    "kind": "file",
    "name": "report.pdf",
    "mime": "application/pdf",
    "extension": "pdf",
    "size_bytes": 524288,
    "storage_id": "01J…",
    "parent_id": "01J…",
    "file_id": "01J…",
    "current_version_id": "01J…",
    "is_pinned": false,
    "position": 0,
    "trashed_at": null,
    "created_at": "…",
    "updated_at": "…",
    "children": null,
    "breadcrumb": [ /* root → this node */ ],
    "abilities": { "view": true, "edit": true, "trash": true, "…": true },
    "handler": { "id": "…", "capabilities": { "…": true }, "ui": { "…": "…" } }
  }
}
```

The `abilities` map is filled by the `AccessResolver` for the request's actor (an
unauthenticated request gets an all-false read-only shell). `handler` is the
resolved [format handler](/guide/document-handlers) descriptor for a leaf node
(null for a folder).

### Write operations

#### `POST storages/{storage}/folders`

Create a folder. → `NodeController::storeFolder`

| Param | Rule |
|---|---|
| `name` | required, string, max 255, no path separators (`/` `\`) |
| `parent_id` | nullable (omit = root) |

**Gate:** `canCreateInWorkspace` + `Ability::Create` on the destination.
**Response:** `201` `NodeResource`.

#### `POST storages/{storage}/uploads`

Commit a binary upload (multipart). A new-version upload targets an existing file
node via `node_id`; otherwise a brand-new file is created under the parent.
→ `NodeController::upload`

| Param | Rule |
|---|---|
| `file` | required, file, `max:` `config('vaulter.drive.max_upload_kb')`; if `config('vaulter.drive.allowed_mime')` is non-empty, restricted to that `mimetypes` set |
| `parent_id` | nullable (omit = root) |
| `node_id` | nullable — the existing file node to commit a **new version** onto |
| `name` | nullable, max 255, no path separators — overrides the stored name |

**Gate:** new file → `canCreateInWorkspace` + `Create` on the destination;
new version → `Ability::Edit` on the target node. **Response:** `201` `NodeResource`.

::: tip Large files
This endpoint reads the multipart body in one request. For resumable, chunked
uploads use the [resumable-upload routes](#resumable-uploads).
:::

#### `PATCH nodes/{node}`

Rename a node. → `NodeController::rename`

| Param | Rule |
|---|---|
| `name` | required, string, max 255, no path separators |

**Gate:** `Ability::Edit`. **Response:** `NodeResource`.

#### `POST nodes/{node}/move`

Move a node under a new parent. → `NodeController::move`

| Param | Rule |
|---|---|
| `parent_id` | nullable (omit = root) |

**Gate:** `Ability::Move` on the node **and** `Create` on the destination.
**Response:** `NodeResource`.

#### `POST nodes/{node}/copy`

Deep-copy a node (and a folder's subtree). → `NodeController::copy`

| Param | Rule |
|---|---|
| `parent_id` | nullable (omit = root) |
| `name` | nullable, max 255, no path separators — overrides the copy's name |

**Gate:** `Ability::View` on the source **and** `Create` on the destination.
**Response:** `201` `NodeResource`.

#### `DELETE nodes/{node}`

Trash (soft-delete) a node and its subtree. → `NodeController::trash`

**Gate:** `Ability::Trash`. **Response:** `NodeResource` (with `trashed_at` set).

#### `POST nodes/{node}/restore`

Restore a trashed node. → `NodeController::restore`

**Gate:** `Ability::Restore`. **Response:** `NodeResource`.

#### `DELETE nodes/{node}/force`

Permanently delete a node and its subtree (detaches blobs, releases quota).
→ `NodeController::forceDelete`

**Gate:** `Ability::Delete`. **Response:** `204 No Content`.

### Search & trash

#### `GET storages/{storage}/search`

Name search within the storage tree (live nodes only). → `NodeController::search`

| Param | In | Notes |
|---|---|---|
| `q` | query | the search term |
| `per_page`, `cursor` | query | pagination |

**Gate:** per-row `Ability::View` (matches the actor cannot view are dropped from
the page). **Response:** paginated `NodeResource` collection.

#### `GET storages/{storage}/trash`

The trash view: trashed (not yet purged) nodes for the storage.
→ `NodeController::trashed`

**Gate:** per-row `Ability::Restore`. **Response:** paginated `NodeResource`
collection. See [Trash & restore](/guide/trash).

### Download

#### `GET nodes/{node}/download`

Stream the node's current head version. → `DownloadController::current`

#### `GET nodes/{node}/versions/{version}/download`

Stream a specific historical file version. → `DownloadController::version`

**Gate:** `Ability::Download`. **Response:** on a disk that can mint a signed URL
(S3-style) a `302` redirect to a short-lived (`5 min`) temporary URL; otherwise the
bytes are streamed straight off the storage driver (never buffered in memory) with
`Content-Type` and `Content-Length` headers.

---

## Document nodes (Documents)

Rich-text/Yjs document nodes (`DocumentNodeController`). **Gated on the
`document_versions` feature.** See [Editing](/guide/documents) and
[Versions](/guide/documents).

#### `POST storages/{storage}/documents`

Create a `kind=document` node with its v1 version. → `store`

| Param | Rule |
|---|---|
| `name` | required, string, max 255, no path separators |
| `parent_id` | nullable (omit = root) |
| `format` | nullable, one of the registered `DocumentType` values |
| `content` | nullable, array (TipTap doc) |

**Gate:** `canCreateInWorkspace` + `Create` on the destination. **Response:**
`201` `NodeResource`.

#### `GET nodes/{node}/content`

The document node plus its current head content. → `content`

**Gate:** `Ability::View` (and the node must be `kind=document`, else a
`NotADocumentNodeException`). **Response:** `NodeDocumentVersionResource` with the
content embedded.

#### `PATCH nodes/{node}/content`

Autosave the document content (the debounce path). → `update`

| Param | Rule |
|---|---|
| `content` | required, array (TipTap doc) |
| `yjs_state` | nullable, string (base64 Yjs binary) |

**Gate:** `Ability::Edit`. **Response:** `200` `NodeDocumentVersionResource`
(autosave is semantically an update even when a new version row was appended).

#### `GET nodes/{node}/document-versions`

The version history of a document node, newest first. → `versions`

**Gate:** `Ability::View`. **Response:** paginated `NodeDocumentVersionResource`.

#### `POST nodes/{node}/document-versions`

Snapshot the current head as a **named** version. → `storeVersion`

| Param | Rule |
|---|---|
| `label` | required, string, max 255 |
| `keep_forever` | nullable, boolean |

**Gate:** `Ability::Edit`. **Response:** `201` `NodeDocumentVersionResource`.

#### `POST nodes/{node}/document-versions/{version}/restore`

Restore a prior version (repoints the head to a copy of it). → `restore`

**Gate:** `Ability::Edit`. **Response:** `201` `NodeDocumentVersionResource`.

#### `GET nodes/{node}/handler`

The resolved handler descriptor (id + capabilities + ui) so the client knows which
editor/preview surface to mount. → `handler`

**Gate:** `Ability::View`. **Response:** `{ "data": { … } }`.

#### `GET nodes/{node}/export`

Export the document node's head content in the requested format. → `export`

| Param | In | Notes |
|---|---|---|
| `format` | query | `html` (default), `markdown`, or any registered exporter format |

**Gate:** `Ability::View`. **Response:** `{ "data": { … } }`. See [Export](/guide/export).

---

## Resumable uploads

Chunked, resumable uploads that assemble through the *same* race-free CAS commit
(`TusUploadController`, `S3MultipartController`). **Gated on the `uploads`
feature.** See [Uploads](/guide/uploads).

The `{upload_id}` is the client's UNIQUE session handle — a plain scalar, resolved
by the controller (which asserts it belongs to an authorized target).

### tus.io

#### `POST storages/{storage}/resumable-uploads`

Create a resumable session (tus Creation). → `TusUploadController::create`

| Param | Rule |
|---|---|
| `protocol` | nullable, one of the `UploadProtocol` values (default `tus`) |
| `parent_id` | nullable (omit = root) — new-file destination |
| `node_id` | nullable — the existing file node for a **new version** |
| `name` | nullable, max 255, no path separators |
| `total_size_bytes` | nullable, integer ≥ 0 |
| `expected_sha256` | nullable, exactly 64 chars (declared, never trusted as the address) |
| `mime_type` | nullable, max 191 |
| `metadata` | nullable, array (merged over the decoded tus `Upload-Metadata` header) |

**Gate:** new file → `canCreateInWorkspace` + `Create` under the folder; new
version → `Ability::Edit` on the node. **Response:** `201` `UploadSessionResource`
with headers `Tus-Resumable`, `Location` (the `upload_id` URL) and `Upload-Offset: 0`.

#### `HEAD resumable-uploads/{upload_id}`

The authoritative server-side resume point. → `head`

**Response:** `204` with `Tus-Resumable`, `Upload-Offset` (the resume point) and
`Upload-Length` (when the total size is known). The client resumes from **this**
offset, never its own guess.

#### `PATCH resumable-uploads/{upload_id}`

Append an `application/offset+octet-stream` chunk at the declared offset. → `patch`

| Header | Notes |
|---|---|
| `Upload-Offset` | where this chunk starts |

**Response:** while incomplete, `204` with the advanced `Upload-Offset`; on the
final chunk the bytes are assembled and committed and the response is `201`
`NodeResource` (the created/updated node).

#### `DELETE resumable-uploads/{upload_id}`

Abort the session (staged temp + any multipart reclaimed). → `abort`

**Response:** `204` with `Tus-Resumable`.

#### `GET resumable-uploads/{upload_id}`

Poll the session status. → `status`

**Response** (`UploadSessionResource`):

```json
{
  "data": {
    "upload_id": "…",
    "protocol": "tus",
    "status": "uploading",
    "received_bytes": 8388608,
    "total_size_bytes": 52428800,
    "chunk_size": 8388608,
    "mime_type": "application/pdf",
    "node_id": null,
    "target_parent_id": "01J…",
    "target_name": "report.pdf",
    "assembled_blob_id": null,
    "expires_at": "…"
  }
}
```

::: warning tus can be disabled independently
If `config('vaulter.uploads.tus.enabled')` is `false`, the tus routes still mount
but every tus action returns **403** — the `uploads` feature flag (the schema) and
the tus toggle are separate.
:::

### S3-style multipart

#### `POST resumable-uploads/{upload_id}/parts`

Record (sign/proxy) a numbered part; the body is the part bytes. A re-upload of the
same `part_no` replaces it. → `S3MultipartController::parts`

| Param | In | Notes |
|---|---|---|
| `part_no` | body / `X-Part-Number` header | defaults to `1` |
| `byte_offset` | body | where the part starts |

**Gate:** new version → `Ability::Edit`; new file → `canCreateInWorkspace` +
`Create`. **Response:** `201` `{ "part_no": …, "size": …, "etag": "…" }`.

#### `POST resumable-uploads/{upload_id}/complete`

Complete-multipart → assemble → commit (server recomputes the sha256 — the ETag is
never the content address). → `complete`

**Response:** `201` `NodeResource`.

---

## Sharing (bearer node share-links)

The bearer share-link channel (`Sharing\ShareController` for owners,
`Sharing\PublicShareController` for the public). A share grants **exactly its
bitmask** on its node-set — the identity ACL is never consulted on the public path.
See [Sharing](/guide/sharing).

### Owner surface (authenticated)

Mounted in the Drive `SubstituteBindings` group, so `{node}` is route-model bound.
Every action requires `Ability::Share` on the node.

#### `GET nodes/{node}/shares`

List the share-links on a node (never exposes the raw token). → `index`

**Response:** paginated `NodeShareResource`.

#### `POST nodes/{node}/shares`

Mint a share-link. → `store`

| Param | Rule |
|---|---|
| `permissions` | nullable, integer `0..31` (CRUDS bitmask) |
| `level` | nullable, a `GrantLevel` value |
| `scope` | nullable, a `GrantScope` value (`item` default, or `subtree`) |
| `password` | nullable, string 1–255 |
| `require_login` | nullable, boolean |
| `allow_discovery` | nullable, boolean |
| `expires_at` | nullable, date, after now |
| `max_uses` | nullable, integer ≥ 1 |

If `permissions` is omitted it defaults to the chosen `level`'s bits, else plain
`READ`. **Response:** `201` `NodeShareResource` — the **raw `token` and full
`url` are returned exactly once here** (every list/show omits them):

```json
{
  "data": {
    "id": "01J…",
    "node_id": "01J…",
    "token_prefix": "abc12345",
    "permissions": 1,
    "level": "reader",
    "scope": "item",
    "has_password": false,
    "require_login": false,
    "allow_discovery": false,
    "expires_at": null,
    "max_uses": null,
    "use_count": 0,
    "revoked_at": null,
    "created_at": "…",
    "token": "abc12345…rawtokenonce",
    "url": "/share/abc12345…rawtokenonce"
  }
}
```

#### `DELETE nodes/{node}/shares/{share}`

Revoke a share-link. The `{share}` id must belong to the authorized node (a foreign
id `404`s). → `destroy`

**Response:** `204 No Content`.

### Public surface (token-resolved)

These routes carry **no auth middleware** — only `ResolveShareToken`, which hashes
the `{token}`, resolves the live share, runs the timing-safe compare + password /
require-login gates, and stashes a `ShareContext`. Authorization is **only** the
share's own bitmask.

#### `GET share/{token}`

The target node's metadata within scope. A `scope=subtree` folder embeds its
immediate children. → `PublicShareController::show`

**Requires:** the `View` bit. **Response:** `SharedNodeResource`.

#### `GET share/{token}/nodes`

List the in-scope children — **only** for `scope=subtree` (an item-scope share has
no listing → `404`). → `nodes`

**Requires:** the `View` bit. **Response:** `SharedNodeResource` collection.

#### `GET share/{token}/download`

Stream the shared file's current head within the `Download` bit, intersected with
item-safety (the blob must be `Committed`). Consumes one use atomically (bounded by
`max_uses`) before streaming. → `download`

**Requires:** the `Download` bit. **Response:** `302` to a signed URL or a streamed
download (as for the [authenticated download](#download)).

---

## Per-node ACL grants

Manage the per-node grants (`Drive\GrantController`). `{node}` is route-model
bound; every action requires `Ability::Share` on the node. See
[Panel ACL](/guide/grants).

#### `GET nodes/{node}/grants`

List the grants on a node. → `index`

**Response:** paginated `NodeGrantResource`.

#### `POST nodes/{node}/grants`

Grant access to a subject. → `store`

| Param | Rule |
|---|---|
| `subject_type` | required, string, a registered subject type |
| `subject_id` | required |
| `level` | required, a `GrantLevel` value |
| `effect` | nullable, an `Effect` value (`allow` default) |
| `scope` | nullable, a `GrantScope` value (`subtree` default) |
| `permissions` | nullable, integer `0..31` |
| `expires_at` | nullable, date |

**Response:** `201` `NodeGrantResource`:

```json
{
  "data": {
    "id": "01J…",
    "node_id": "01J…",
    "subject_type": "App\\Models\\User",
    "subject_id": 5,
    "level": "writer",
    "permissions": 15,
    "effect": "allow",
    "scope": "subtree",
    "expires_at": null,
    "created_at": "…"
  }
}
```

#### `DELETE nodes/{node}/grants`

Revoke a subject's grant. → `destroy`

| Param | Rule |
|---|---|
| `subject_type` | required |
| `subject_id` | required |

**Response:** `204 No Content`.

---

## Activity feed

The append-only activity feed (`Activity\ActivityController`). Recipients are
resolved at READ by scoping to the storage/node — no notification rows.

#### `GET storages/{storage}/activity`

The storage-wide activity feed. → `forStorage`. **Gate:** `Ability::View` on the
storage root.

#### `GET nodes/{node}/activity`

A single node's activity feed. → `forNode`. **Gate:** `Ability::View` on the node.

Both return a paginated `ActivityResource` collection.

---

## Realtime collaboration

TipTap/Yjs collaboration over the document nodes (`Drive\NodeCollaborationController`).
**Gated on the `document_versions` feature.** See [Collaboration](/guide/collaboration).

### Client handshake

Mounted in the Drive `SubstituteBindings` group (`{node}` route-model bound).

#### `POST nodes/{node}/collab/token`

Issue a transport-agnostic collaboration session for the document node. → `token`

**Gate:** the actor needs at least `View`/`Comment` to connect; the session is
read-only unless they hold `Edit`. **Response:** `{ "data": { /* CollaborationSession */ } }`.

### Server-to-server webhooks

The hub-callback surface — **no auth middleware**, only `ResolveCollaborationCall`,
which authenticates the caller via the shared webhook secret **or** a valid signed
room JWT and binds the call to its node (a JWT for node A may not store to node B).
The node is resolved from the **body** (`room` / `node_id`).

#### `POST collab/authenticate`

The hub's `onAuthenticate` hook. → `authenticate`

**Response:** `{ "allowed": bool, "readOnly": bool, "user": { "id": … } | null }`.
`allowed=false` (not a 403) when the user holds no view access, so the hub can
refuse the socket cleanly. `readOnly` is `false` only for an `Edit` ability.

#### `POST collab/store`

The hub's `onStoreDocument` hook — persist the debounced Yjs snapshot. → `store`

| Param | In | Notes |
|---|---|---|
| `node_id` *or* `room` | body | the target node (room normalised back through the channel prefix) |
| `yjs_state` | body | required, base64 Yjs binary (the source of truth) |
| `content` / `tiptap` | body | optional TipTap projection |
| `user_id` | body | attribution (for a shared-secret call; a JWT call uses its `sub`) |

The call must be allowed to store (`mayStore()`), else `403`. **Response:**
`{ "data": { "node_id": …, "version_no": …, "yjs_state_size": … } }`.

---

## Webhook-endpoint management

The global admin surface for subscriber endpoints
(`Webhooks\WebhookEndpointsController`). **Gated on the `reliability` feature.**
Every action is gated by the `WebhookEndpointPolicy` manage ability. See
[Reliability & webhooks](/guide/recipes).

| Method & path | Action | Notes |
|---|---|---|
| `GET webhook-endpoints` | `index` | list endpoints |
| `POST webhook-endpoints` | `store` | register — returns the `signing_secret` **once** |
| `PATCH webhook-endpoints/{endpoint}` | `update` | update |
| `POST webhook-endpoints/{endpoint}/deactivate` | `deactivate` | turn off |
| `DELETE webhook-endpoints/{endpoint}` | `destroy` | delete |

`{endpoint}` is a plain scalar resolved through the read repository.

---

## Legacy document API

The pre-Drive document surface (`DocumentController` and friends) remains mounted
for backward compatibility. New integrations should prefer the
[Drive](#drive) and [document-node](#document-nodes-documents) routes above.

### Documents

| Method & path | Action | Params |
|---|---|---|
| `GET documents` | `index` | `?q=` search, `workspace_type`, `workspace_id` |
| `GET documents/trash` | `trash` | the trash listing |
| `POST documents/bulk` | (`BulkDocumentController`) | `action` (`delete`/`restore`/`move`/`force-delete`), `ids[]`, `folder_id` |
| `POST documents` | `store` | `workspace_type`, `workspace_id`, `type`, `title`, `content`, `folder_id?`, `meta?` |
| `GET documents/{id}` | `show` | |
| `PATCH documents/{id}` | `update` | |
| `DELETE documents/{id}` | `destroy` | soft-delete |
| `DELETE documents/{id}/force` | `forceDestroy` | permanent |
| `POST documents/{id}/restore` | `restore` | |
| `PATCH documents/{id}/move` | `move` | `folder_id` (null = root) |
| `POST documents/{id}/duplicate` | `duplicate` | |
| `GET documents/{documentId}/export` | (`ExportController::show`) | `?format=` |

### Versions

| Method & path | Action | Params |
|---|---|---|
| `GET documents/{documentId}/versions` | `index` | |
| `POST documents/{documentId}/versions` | `store` | `snapshot_label?` |
| `POST documents/{documentId}/versions/{versionId}/restore` | `restore` | |

### Permissions

| Method & path | Action | Params |
|---|---|---|
| `GET documents/{documentId}/permissions` | `index` | |
| `POST documents/{documentId}/permissions` | `store` | `subject_type`, `subject_id`, `permission` (a `PermissionLevel`) |
| `DELETE documents/{documentId}/permissions/{permissionId}` | `destroy` | |

### Comments

| Method & path | Action | Params |
|---|---|---|
| `GET documents/{documentId}/comments` | `index` | |
| `POST documents/{documentId}/comments` | `store` | `body`, `parent_id?`, `anchor?` |
| `POST documents/{documentId}/comments/{commentId}/resolve` | `resolve` | |
| `DELETE documents/{documentId}/comments/{commentId}` | `destroy` | |

See [Comments](/guide/comments).

### Legacy document share-links

| Method & path | Action | Params |
|---|---|---|
| `GET documents/{documentId}/shares` | `index` | |
| `POST documents/{documentId}/shares` | `store` | `permission` (`view`/`comment`/`edit`), `expires_at?` |
| `DELETE documents/{documentId}/shares/{shareId}` | `destroy` | |
| `GET shares/{token}` | `resolve` | public token resolution |

### Legacy collaboration

| Method & path | Action |
|---|---|
| `POST documents/{documentId}/collab/token` | `CollaborationController::token` |
| `POST documents/{documentId}/collab/sync` | `CollaborationController::sync` |

### Legacy folders

| Method & path | Action | Params |
|---|---|---|
| `GET folders` | `index` | `workspace_type`, `workspace_id` |
| `POST folders` | `store` | `workspace_type`, `workspace_id`, `name`, `parent_id?`, `order?` |
| `PATCH folders/{id}` | `update` | rename / move |
| `DELETE folders/{id}` | `destroy` | |

## See also

- [Drive](/guide/drive) — the `DriveContext` handle the Drive routes wrap
- [Trash & restore](/guide/trash) — the trash routes' lifecycle
- [Uploads](/guide/uploads) · [Sharing](/guide/sharing) · [Collaboration](/guide/collaboration)
- [Events reference](/reference/events) · [Enums reference](/reference/contracts)
