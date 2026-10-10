---
title: REST API reference
description: The Vaulter REST API v1 — base rules, middleware stacks, validators and caching, file egress, and every route with method, path, parameters and response.
outline: deep
editLink: false
---

# REST API reference

The versioned REST API lives under `/{routes.prefix}/v1` — by default
`/api/vaulter/v1` — and every route is named `vaulter.v1.<name>`. This page lists the
base rules first, then each route. Errors are described in the
[error reference](/reference/errors).

::: info Migration in progress
The access, sharing, activity, change journal and webhook routes are on v1. The drive,
upload, document, comment and collaboration routes further down are still served
unversioned under `/{routes.prefix}` and move to `/v1` with the next 1.0 development
releases; their sections say so.
:::

## Base rules

| Rule | Value |
|---|---|
| Base path | `/{routes.prefix}/v1` (`/api/vaulter/v1` by default) |
| Versioning | major version in the URL; additive changes keep `v1`; removals or changed meaning only in `v2`, and `v1` stays supported for at least 12 months after `v2` ships |
| Version header | every v1 response, errors and `304` included: `X-Vaulter-Api-Version: 1` |
| Route names | `vaulter.v1.<resource>.<action>` |
| JSON | `snake_case`; every id is a string; times are RFC 3339 in UTC with a `Z`; a missing value is `null`, never an absent key; an empty object (`properties`, `data`) is `{}` |
| Single resource | `{ "data": { … } }` |
| Lists | cursor pages: `?cursor=&per_page=` (`per_page` 1–200, default 50; outside that range `422 validation_failed`) → `{ "data": [ … ], "meta": { "next_cursor": "…" \| null, "per_page": 50 } }`; there is no `total` |
| Correlation | an incoming `X-Request-Id` matching `^[A-Za-z0-9._:-]{1,64}$` is kept, otherwise a new ULID is assigned; it is echoed on every response, passed to the operation and recorded with its activity and events |
| Errors | `application/problem+json` with a stable `code` — see the [error reference](/reference/errors) |
| Parameters | `{node}`, `{drive}`, `{grant}`, `{share}`, `{endpoint}`, `{delivery}`, `{token}` are plain ids (`[A-Za-z0-9_-]+`); there is no route-model binding |

### Middleware stacks

```php
// config/vaulter.php
'routes' => [
    'prefix' => 'api/vaulter',
    'middleware' => [
        'authenticated' => ['api', 'auth:sanctum'],
        'public' => ['api'],
        'hub' => ['api'],
    ],
],
```

The host lists only its own middleware. Vaulter appends its own to each of the three
stacks — `AssignCorrelationId`, `AddApiVersionHeader`, `ApplyResponseCachePolicy` and
`RecordVaulterRequestMetrics` — and ranks the first three ahead of throttling and
authentication, so a `401` or `429` also carries the request id, the version and the
cache policy. On a host route these middleware do nothing.

| Stack | Used by | Added by Vaulter |
|---|---|---|
| `authenticated` | everything except the two below | — |
| `public` | `shares/{token}*` | `throttle:vaulter-public`, `ResolveShareToken` |
| `hub` | `collaboration/hub/*` | `throttle:vaulter-public`, `ResolveCollaborationCall` |

### Resolution and authorization

A controller resolves the drive behind the ids through the tenant-scoped
`HttpResourceResolver`, then calls the operations as the request's user
(`Vaulter::drive($drive)->actingAs($user)`): HTTP authorizes exactly like a PHP call of
the same operation.

- No authenticated user → `401 actor_required`.
- A node you cannot view, of another tenant or that does not exist → the same
  `404 node_not_found`, with the same body except `request_id`. A drive you cannot see
  (no `view` on its root), of another tenant, being deleted or unknown → the same
  `404 drive_not_found`.
- A node you can view but an ability you lack → `403 permission_denied`; a feature the
  drive's profile switches off → `403 profile_restriction`.
- A grant or share id that belongs to another node is unknown (`404`).

### Validators and preconditions

A node's `ETag` is a strong validator of **the representation you receive**:
`"{id}.{sequence}.{digest}"`, where the digest covers everything in your copy of the
node — your abilities, your starred flag, its scan state and presentation, its
properties and content head. A grant, a star or a finished scan therefore changes it
even though the node's `sequence` did not move. Treat the tag as opaque. Document
content uses `"{node}.{collab_epoch}.{state_version}"`, which does not depend on the
caller.

- The `ETag` header comes with a single node read without `include`, with document
  content, and with mutations that answer a node or content. Every node in a body also
  carries an `etag` field, list items included.
- `If-None-Match` on a single node or document content answers `304` — with `ETag`,
  `Cache-Control`, `Vary`, `X-Vaulter-Api-Version` and `X-Request-Id`, and no body —
  only after the node has been found and authorized. A reader whose access was revoked
  gets `404`, never `304`.
- Routes marked **If-Match** below refuse to change state blind:
  - no `If-Match` → `428 precondition_required` with `meta.header = "If-Match"`;
  - `If-Match: *` → `428` with `meta.reason = "wildcard_not_allowed"` (it would match
    any version);
  - a weak tag (`W/"…"`) never matches;
  - a tag that is not the current one → `412 node_sequence_mismatch` (or
    `document_state_mismatch`) with `meta.node_id` — without the current tag;
  - a list `"a", "b"` matches if any element matches.

  The check runs after authorization, and the operation then writes only if the node
  is still at the sequence you matched (an atomic compare-and-set), so two clients
  holding the same tag cannot both win: the second gets `412`.

### Caching

| Response | `Cache-Control` |
|---|---|
| Single resource with an `ETag` (and its `304`) | `private, no-cache`, `Vary: Authorization, Cookie` |
| Lists, mutations, responses with a token or secret, errors, `428` | `no-store` (`private, no-store` for `GET`) |
| File bytes and signed-URL redirects | `private, no-store` |
| Public share routes | `private, no-store` and `Referrer-Policy: no-referrer` |

Nothing in v1 is `public` or carries a `max-age`: a shared cache must never serve one
caller's representation to another, nor a revoked caller from cache.

### File delivery

Bytes and signed URLs leave only through one component, which checks access, tenant,
profile and scan state again for every request and pins the exact revision. Its mode is
`vaulter.downloads.delivery_mode`:

- **`strict`** (default) — the application streams the bytes with
  `Content-Disposition: attachment; filename="…"; filename*=UTF-8''…` (RFC 6266; control
  characters and path separators are removed from the name),
  `X-Content-Type-Options: nosniff`, `Content-Security-Policy: sandbox; default-src 'none'`,
  `Cache-Control: private, no-store`, `Accept-Ranges: none` and `Content-Length`. The
  declared type is kept only for the inline-safe allow-list, otherwise
  `application/octet-stream`.
- **`direct_signed`** (opt-in) — after the same checks, a `302` to a URL signed by your
  `DirectContentUrlSigner` for that exact revision, valid for
  `vaulter.downloads.direct_ttl_seconds` (1–300), with `Cache-Control: private, no-store`
  and `Referrer-Policy: no-referrer`.

A revision that is not clean (pending scan where scanning is required, infected) answers
`423 content_unavailable` before any byte or URL. A caller who can view a file but not
download it (a previewer) gets `403 permission_denied`. Byte ranges are not supported in
1.0.

Revocation is bounded and documented, not instant: in `strict` mode every new request
is checked, but a stream that has already started is not cut; in `direct_signed` mode a
URL already issued stays valid until it expires — revoking access does not cancel it.
Public share downloads always stream.

---

## Unversioned routes

The [Drive](#drive), [Document nodes](#document-nodes-documents),
[Resumable uploads](#resumable-uploads), [Realtime collaboration](#realtime-collaboration)
and [Legacy document API](#legacy-document-api) routes are still served under
`/{routes.prefix}` without `/v1` and keep their pre-v1 conventions:
`{storage}` and `{node}` are resolved into models before the controller runs, errors
are `{ "message", "code" }`, and node bodies use the pre-v1 shape (`storage_id`,
`handler`, …). They move to `/v1` — with the base rules above — in the next 1.0
development releases.

## Drive

The unified `v_nodes` file/folder tree (`ShowDriveController`, `NodeController`,
`DownloadController`). Storage-collection endpoints carry `{storage}` in the path;
node-scoped endpoints derive the storage from the bound `{node}`.

### Storages

#### `GET storages/{storage}`

Read a storage container's metadata, root node and usage. → `ShowDriveController::__invoke`

**Response** (`DriveResource`):

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
    "trashed_at": null,
    "created_at": "…",
    "updated_at": "…",
    "content_modified_at": "…",
    "viewer": { "starred": false },
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

### Shared with me

#### `GET shared`

The cross-storage "shared with me" feed — nodes the actor holds a live
allow-grant on, re-resolved EXACTLY through the `AccessResolver` (a nearer deny
still excludes a row). → `NodeController::shared`

> **This endpoint is OFFSET-paginated (`page`/`total`), NOT cursor-paginated**
> (P1.3 / F-P4.2-04) — the one exception to the "Listings are cursor-paginated"
> convention above. The feed is cross-storage and its visibility is decided by an
> exact, in-memory re-resolution per candidate (`AccessResolver::filterSharedWith`)
> that cannot honestly back a DB cursor — a fake cursor over that in-memory
> filtering would not carry the stability guarantees a real keyset cursor implies
> (D16 of `plans/archive/VLT-REMED/plan.md`). Concurrent grant inserts/revocations between
> page requests can therefore drift the offset window (skip/duplicate a row) —
> the same trade-off offset pagination always carries. A host that needs
> drift-free enumeration of this feed should not rely on paging past the first
> page under concurrent writes.

| Param | In | Notes |
|---|---|---|
| `page`, `per_page` | query | offset pagination (NOT `cursor`) |

**Gate:** none (feature-gated on `grants`); an unauthenticated request returns an
empty bounded page. **Response:** a `LengthAwarePaginator` envelope
(`meta.current_page`, `meta.last_page`, `meta.total`, …) of `NodeResource`.

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
| `file` | required, file, `max:` `config('vaulter-drive.uploads.max_file_size')`; if `config('vaulter-drive.uploads.allowed_mime_types')` is non-empty, restricted to that `mimetypes` set |
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

Restore a trash root (and the members trashed with it). → `NodeController::restore`

| Name | In | Notes |
|---|---|---|
| `destination_id` | body | Optional. Folder to restore into; needs `Create` on it. Without it the node returns to the folder it was trashed from. |

**Gate:** `Ability::Restore`. **Response:** `NodeResource`. A member trashed with a folder answers
`409 node_trashed_by_ancestor` (`context.trash_root_id`); a missing or trashed original folder
without `destination_id` answers `409 restore_destination_required`; an archived drive answers
`409 drive_archived`.

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

### Comments

Node-attached comments (`NodeCommentController`, vaulter-documents), the additive
Drive-native twin of the [legacy document-path comments](#comments-1). Feature-gated
on `comments`. `{comment}` is a plain scalar (the model is swappable, not
route-model bound) — the controller resolves it and asserts it belongs to the bound
node.

| Route | Action | Notes |
|---|---|---|
| `GET nodes/{node}/comments` | `index` | |
| `POST nodes/{node}/comments` | `store` | `body`, `parent_id?`, `anchor?` |
| `POST nodes/{node}/comments/{comment}/resolve` | `resolve` | |
| `DELETE nodes/{node}/comments/{comment}` | `destroy` | |

**Gate:** read ≥ `Ability::View`, create = `Ability::Comment`, resolve/delete = the
comment's author or `Ability::Manage`.

---

## Document nodes (Documents)

Rich-text/Yjs document nodes (`DocumentNodeController`). **Enabled with the Documents package.** See [Editing](/guide/documents) and
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
`NotADocumentException`). **Response:** `DocumentRevisionResource` with the
content embedded.

#### `PATCH nodes/{node}/content`

Autosave the document content (the debounce path). → `update`

| Param | Rule |
|---|---|
| `content` | required, array (TipTap doc) |
| `yjs_state` | nullable, string (base64 Yjs binary) |
| `state_version` | nullable, integer ≥ 0 — the `state_version` the client last read; a save that lost the race answers `412 document_state_mismatch` |

**Gate:** `Ability::Edit`. **Response:** `200` `DocumentRevisionResource`
(autosave is semantically an update even when a new version row was appended). The resource
carries `state_version`; content that cannot be saved back answers `409 document_content_degraded`.

#### `GET nodes/{node}/document-versions`

The version history of a document node, newest first. → `versions`

**Gate:** `Ability::View`. **Response:** paginated `DocumentRevisionResource`.

#### `POST nodes/{node}/document-versions`

Snapshot the current head as a **named** version. → `storeVersion`

| Param | Rule |
|---|---|
| `label` | required, string, max 255 |
| `keep_forever` | nullable, boolean |

**Gate:** `Ability::Edit`. **Response:** `201` `DocumentRevisionResource`.

#### `POST nodes/{node}/document-versions/{version}/restore`

Restore a prior version (repoints the head to a copy of it). → `restore`

**Gate:** `Ability::Edit`. **Response:** `201` `DocumentRevisionResource`.

#### `GET nodes/{node}/handler`

The resolved handler descriptor (id + capabilities + ui) so the client knows which
editor/preview surface to mount. → `handler`

**Gate:** `Ability::View`. **Response:** `{ "data": { … } }`.

#### `GET nodes/{node}/export`

Export the document node as a file in the requested format. → `export`

| Param | In | Notes |
|---|---|---|
| `format` | query | `html` (default), `markdown`, or any registered exporter format |

**Gate:** `Ability::Download` + content egress. **Response:** the exporter's bytes with `Content-Type`, `Content-Disposition: attachment; filename*`, `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff` (not JSON); `X-Vaulter-Export-Lossy: 1` marks content its type could only read partly. A format the document's type lacks answers `422 unknown_export_format` with `allowed`. See [Export](/guide/export).

---

## Resumable uploads

Chunked, resumable uploads that assemble through the *same* race-free CAS commit
(`TusProtocolController`, `S3MultipartProtocolController`). **Gated on the `uploads`
feature.** See [Uploads](/guide/uploads).

The `{upload_id}` is the client's UNIQUE session handle — a plain scalar, resolved
by the controller (which asserts it belongs to an authorized target).

### tus.io

#### `POST storages/{storage}/resumable-uploads`

Create a resumable session (tus Creation). → `TusProtocolController::create`

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
If `config('vaulter-drive.uploads.resumable.enabled')` is `false`, the tus routes still mount
but every tus action returns **403** — the `uploads` feature flag (the schema) and
the tus toggle are separate.
:::

### S3-style multipart

#### `POST resumable-uploads/{upload_id}/parts`

Record (sign/proxy) a numbered part; the body is the part bytes. A re-upload of the
same `part_no` replaces it. → `S3MultipartProtocolController::parts`

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

## Access: grants and inheritance

Roles granted on a node (`vaulter.features.grants`). Every route needs `manage` on the
node. See [Grants](/guide/grants).

#### `GET v1/nodes/{node}/grants` — `grants.index`

The grants set directly on the node. **Response:** `200 { "data": [Grant, …] }` (the
grants of one node are few, so this list is not paged).

#### `POST v1/nodes/{node}/grants` — `grants.store`

| Field | Rule |
|---|---|
| `subject.type` | required, a subject type the permission driver can enumerate |
| `subject.id` | required |
| `role` | required: `previewer`, `reader`, `commenter`, `writer`, `organizer`, `owner` |
| `scope` | `subtree` (default) or `item` |
| `expires_at` | optional, a future date |

**Response:** `201` Grant:

```json
{
  "data": {
    "id": "01J…",
    "node_id": "01J…",
    "subject": { "type": "user", "id": "5" },
    "role": "writer",
    "role_version": 1,
    "scope": "subtree",
    "expires_at": null,
    "created_by": "1",
    "created_at": "2026-10-10T12:00:00.000000Z"
  }
}
```

#### `DELETE v1/nodes/{node}/grants/{grant}` — `grants.destroy`

Revoke one grant. **Response:** `204`.

#### `POST v1/nodes/{node}/inheritance/restrict` — `access.inheritance.restrict`

Stop grants from above applying to the node and its subtree. `mode`: `empty` (default —
the node keeps only its own grants) or `copy_current` (it keeps copies of the grants it
inherited). **Response:** `200` Node with its new `ETag`. If the change removed your own
access, the node comes back with every ability `false`.

#### `POST v1/nodes/{node}/inheritance/restore` — `access.inheritance.restore`

Let grants from above apply again; the node's own grants stay. **Response:** `200` Node.

---

## Share links

Bearer links to a node (`vaulter.features.share_links`). A link grants exactly its
role's abilities on its node-set; identity grants play no part on the public routes.
See [Sharing](/guide/sharing).

### Owner routes

Every owner route needs `share` on the node.

#### `GET v1/nodes/{node}/shares` — `shares.index`

The node's live links. **Response:** `200 { "data": [Share, …] }`. A token is never
listed.

#### `POST v1/nodes/{node}/shares` — `shares.store`

| Field | Rule |
|---|---|
| `role` | `previewer`, `reader` (default), `commenter`, `writer`; `organizer`/`owner` → `422 share_role_not_allowed` |
| `audience` | `anyone` (default), `authenticated`, `tenant`; `writer` needs `authenticated` or `tenant` |
| `scope` | `item` (default) or `subtree` |
| `password` | optional, 1–255 characters |
| `expires_at` | optional, future, within `vaulter.sharing` and profile limits (`422 share_link_ttl_exceeded`) |
| `max_uses` | optional, ≥ 1 successful visits |

**Response:** `201` — the share plus `token` and `url`, **returned once**:

```json
{
  "data": {
    "id": "01J…",
    "node_id": "01J…",
    "role": "reader",
    "role_version": 1,
    "audience": "anyone",
    "scope": "item",
    "expires_at": "2026-10-13T12:00:00.000000Z",
    "max_uses": null,
    "use_count": 0,
    "has_password": false,
    "token_generation": 1,
    "created_by": "1",
    "created_at": "2026-10-10T12:00:00.000000Z",
    "token": "q3V…",
    "url": "https://app.example/share/q3V…"
  }
}
```

#### `PATCH v1/nodes/{node}/shares/{share}` — `shares.update`

Change expiry, use budget, password or audience; the token and role stay. A field that
is absent stays as it is; `password: null` / `max_uses: null` remove them.
**Response:** `200` Share (no token).

#### `POST v1/nodes/{node}/shares/{share}/rotate` — `shares.rotate`

Replace the token: the old token and its open visits stop working at once and
`token_generation` goes up. **Response:** `200` Share with the new `token` and `url`,
once.

#### `DELETE v1/nodes/{node}/shares/{share}` — `shares.destroy`

Revoke the link. **Response:** `204`.

### Public routes

`public` stack: no authentication of its own, `throttle:vaulter-public`, then
`ResolveShareToken`, which checks the token, the audience and the password and counts
the visit. The first answer of a visit returns an `X-Share-Session` header; sending it
back keeps the rest of the visit from costing more uses. Send a password in
`X-Share-Password`. Every answer is `private, no-store` with `Referrer-Policy: no-referrer`.

Denials: an unknown, revoked, rotated, expired or used-up token, a visitor outside the
link's tenant and a node outside the link all answer `404 share_not_found`; a missing or
wrong password `401 share_password_required`; an `authenticated` link without a signed-in
visitor `401 actor_required`.

Nodes are named by an opaque id (never the real node id); `?node=` accepts only the
shared node or one of the children `nodes` lists.

#### `GET v1/shares/{token}` — `public-shares.show`

The shared node: `id` (opaque), `kind`, `name`, `mime`, `extension`, `size_bytes`,
`parent_id` (opaque), `created_at`, `updated_at`.

#### `GET v1/shares/{token}/nodes` — `public-shares.nodes`

The children of a shared folder, name order, as a page. An `item` link lists nothing
(`404 share_not_found`).

#### `GET v1/shares/{token}/download?node=` — `public-shares.download`

The bytes of the shared file, or of a listed child with `?node=`. Needs a role with
`download` — a previewer link gets `403 permission_denied`. Always streamed, with the
headers of [File delivery](#file-delivery); `423 content_unavailable` before any byte if
the file is not clean.

---

## Activity

What happened on a drive or node, newest first, filtered for the reader
(`vaulter.features.activity`).

#### `GET v1/drives/{drive}/activity` — `activity.drive`

Needs `view` on the drive root. **Response:** a page of Activity.

#### `GET v1/nodes/{node}/activity` — `activity.node`

Needs `view` on the node. **Response:** a page of Activity:

```json
{
  "data": [
    {
      "id": "01J…",
      "type": "node.renamed",
      "node_id": "01J…",
      "actor": { "type": "user", "id": "1" },
      "occurred_at": "2026-10-10T12:00:00.000000Z",
      "data": { "correlation_id": "01J…" }
    }
  ],
  "meta": { "next_cursor": null, "per_page": 50 }
}
```

`node_id` is set when the entry is about a node; `actor` is `null` when the projection
hides it.

---

## Change journal

Follow a drive incrementally instead of re-listing it (`vaulter.features.changes`;
switched off → `403 profile_restriction`). See [Changes](/guide/changes).

#### `GET v1/drives/{drive}/changes/start` — `changes.start`

**Response:** `200 { "data": { "cursor": "…" } }` — take it right after a full listing.

#### `GET v1/drives/{drive}/changes?cursor=&limit=` — `changes.index`

`cursor` is required; `limit` is 1–`vaulter.changes.max_page_size`, default 100.
**Response:**

```json
{
  "data": [
    {
      "node_id": "01J…",
      "operation": "upsert",
      "reason": null,
      "subtree": false,
      "node_sequence": 4,
      "node": { "id": "01J…", "etag": "\"01J….4.9f86d081884c7d65\"", "…": "…" }
    }
  ],
  "meta": { "next_cursor": "…", "has_more": false }
}
```

Apply the changes in order (the last one for a node wins); `remove` (with a `reason`:
`deleted`, `moved_out`, `permission_lost`) has `node: null`, and with `subtree: true`
removes everything below too. Store `next_cursor` — unlike a list, it is always set —
and ask again at once while `has_more` is true. `400 invalid_cursor` for a cursor of
another drive or a damaged one; `410 cursor_expired` when the entries after it were
pruned: list the drive again and start over.

---

## Webhook endpoints

The global admin surface for webhook subscribers, mounted when `vaulter.features.webhooks`
is on. Every route is gated by the `WebhookEndpointPolicy` (`vaulter.webhooks.manage_ability`),
reads included, before the id is looked up. See [Webhooks](/guide/webhooks).

| Method and path | Name | Response |
|---|---|---|
| `GET v1/webhook-endpoints` | `webhook-endpoints.index` | page of Endpoint |
| `POST v1/webhook-endpoints` | `webhook-endpoints.store` | `201` Endpoint + `secret` (once) |
| `GET v1/webhook-endpoints/{endpoint}` | `webhook-endpoints.show` | Endpoint |
| `PATCH v1/webhook-endpoints/{endpoint}` | `webhook-endpoints.update` | Endpoint |
| `POST v1/webhook-endpoints/{endpoint}/deactivate` | `webhook-endpoints.deactivate` | Endpoint |
| `POST v1/webhook-endpoints/{endpoint}/rotate-secret` | `webhook-endpoints.rotate-secret` | Endpoint + new `secret` (once) |
| `DELETE v1/webhook-endpoints/{endpoint}` | `webhook-endpoints.destroy` | `204` |
| `GET v1/webhook-endpoints/{endpoint}/deliveries` | `webhook-deliveries.index` | page of Delivery |
| `POST v1/webhook-endpoints/{endpoint}/deliveries/{delivery}/redeliver` | `webhook-deliveries.redeliver` | `202` Delivery |

- **Register / update** take `url` (http(s), refused if it resolves to a private
  network), `events` (event types), `is_active`, `drive_id` (only that drive's events).
- **Rotate secret** takes `overlap_seconds` (≥ 0, capped): the previous secret keeps
  verifying that long.
- **Endpoint:** `id`, `url`, `drive_id`, `events`, `status` (`active` or `disabled`),
  `disabled_at`, `created_at`.
- **Delivery:** `id`, `event_id`, `event_type`, `status` (`pending`, `failed`,
  `delivered`, `dead`), `attempts`, `next_retry_at`, `last_status_code` (the endpoint's
  last HTTP status, or `null`), `created_at`.
- **Redeliver** a `delivered` or `dead` delivery of an active endpoint; otherwise
  `409 webhook_delivery_not_redeliverable`. Unknown ids answer
  `404 webhook_endpoint_not_found` / `404 webhook_delivery_not_found`.

---

## Realtime collaboration

TipTap/Yjs collaboration over the document nodes (`Drive\NodeCollaborationController`).
**Enabled with `vaulter-documents.collaboration.enabled`.** See [Collaboration](/guide/collaboration).

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
refuse the socket cleanly. `readOnly` is `false` only for an `Edit` ability on content that can be
saved back: an uninstalled type, a newer schema or a degraded read is read-only.

#### `POST collab/store`

The hub's `onStoreDocument` hook — persist the debounced Yjs snapshot. → `store`

| Param | In | Notes |
|---|---|---|
| `node_id` *or* `room` | body | the target node (room normalised back through the channel prefix) |
| `yjs_state` | body | required, base64 Yjs binary (the source of truth) |
| `content` / `tiptap` | body | optional JSON projection, decoded by the document's type (`422 invalid_document_content` when it rejects it) |
| `user_id` | body | attribution (for a shared-secret call; a JWT call uses its `sub`) |

The call must be allowed to store (`mayStore()`), else `403`. On the locked document an uninstalled
type answers `409 document_type_unavailable` and content that cannot be saved back
`409 document_content_degraded`; neither the Yjs state nor the projection changes. Without a
projection the JSON keeps its schema version. **Response:**
`{ "data": { "node_id": …, "version_no": …, "yjs_state_size": … } }`.

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
| `POST documents/{documentId}/permissions` | `store` | `subject_type`, `subject_id`, `permission` (legacy numeric document permission) |
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

---

## CORS

All routes on this page mount under `config('vaulter.routes.prefix')` (default
`api/vaulter`), which falls under Laravel's default `config/cors.php` matcher
`paths => ['api/*']` — even though that file isn't published by default, the
default `allowed_origins => ['*']` still applies to every route documented here,
not just the collaboration hub (which has its own, separate
`VAULTER_COLLAB_ALLOWED_ORIGINS` CSWSH guard — see
[Collaboration](/guide/collaboration)).

With `allowed_origins: ['*']` any origin can read responses from this REST surface
in a browser. The practical exposure depends on how the host authenticates: a
bearer-token client is not directly at risk (a browser will not attach another
site's token), but a client relying on cookies/session credentials should publish
`config/cors.php` and narrow `allowed_origins` to its own known origins (and the
origins `@vaulter/vue` is served from) before shipping to production.

## See also

- [Drive](/guide/drive) — the `DriveHandle` handle the Drive routes wrap
- [Trash & restore](/guide/trash) — the trash routes' lifecycle
- [Uploads](/guide/uploads) · [Sharing](/guide/sharing) · [Collaboration](/guide/collaboration)
- [Events reference](/reference/events) · [Enums reference](/reference/contracts)
