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
| Parameters | `{node}`, `{drive}`, `{revision}`, `{upload}`, `{grant}`, `{share}`, `{endpoint}`, `{delivery}`, `{token}` are plain ids (`[A-Za-z0-9_-]+`); there is no route-model binding |

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
- Drive-wide routes (search, starred, trash, emptying the trash, the change journal) are
  filtered down to what you can see rather than refused: a caller granted only a folder deep
  inside finds what is in it. A drive where you can see no node at all answers
  `404 drive_not_found`, like an unknown drive.

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

## Unversioned paths

Nothing is served under `/{routes.prefix}` without `/v1`. The old paths
(`storages/*`, `nodes/{node}` without `/v1`, `resumable-uploads/*`, `nodes/{node}/content`,
`nodes/{node}/document-versions`, `collab/*`, …) are gone: under the v1 prefix an unknown path
answers `404 route_not_found` as problem+json, outside it the host's plain 404. See the
[upgrade table](https://github.com/axiomasoft/vaulter/blob/main/UPGRADE.md) for every route
that moved.

## Drive

Drives, the node tree, file content and the trash (package `vaulter-drive`). The routes
are always registered while the package is installed; a feature switched off globally or
by the drive's profile is refused by the operation — `403 profile_restriction` on
something you can see — so `route:cache` never freezes a flag.

Operations that apply to every node kind live under `nodes/{node}`; operations on file
content under `files/{node}` (and on document content under `documents/{node}`). A node of
the wrong kind on a content route answers `422 not_a_file`.

### Drive resource

```json
{
  "data": {
    "id": "01J…",
    "owner": { "type": "App\\Models\\Project", "id": "42" },
    "profile": "default",
    "name": "Project files",
    "root_node_id": "01J…",
    "status": "active",
    "quota_bytes": null,
    "used_bytes": 10485760,
    "created_at": "2026-10-10T12:00:00.000000Z"
  }
}
```

`status` is `active`, `archived` or `deleting`. What you may do in the drive is the
`abilities` of its root node (`GET v1/nodes/{root_node_id}`).

#### `GET v1/drives/{drive}` — `drives.show`

Needs `view` on the root. A drive you cannot see, of another tenant, being deleted or
unknown → `404 drive_not_found`.

#### `POST v1/drives/{drive}/archive` — `drives.archive`

#### `POST v1/drives/{drive}/unarchive` — `drives.unarchive`

#### `DELETE v1/drives/{drive}` — `drives.destroy`

Lifecycle, `manage` on the root. An archived drive is read-only: every mutation answers
`409 drive_archived` until it is unarchived. Delete starts the asynchronous purge and
answers the drive with `status: "deleting"`; from then on it is `404` for everyone.
**Response:** `200` Drive.

### Node resource

```json
{
  "data": {
    "id": "01J…",
    "drive_id": "01J…",
    "parent_id": "01J…",
    "kind": "file",
    "name": "contract.pdf",
    "mime": "application/pdf",
    "extension": "pdf",
    "size_bytes": 1024,
    "sequence": 7,
    "etag": "\"01J….7.3f2a9c1d0b4e5a67\"",
    "inherits_access": true,
    "properties": {},
    "created_at": "…", "created_by": "1",
    "updated_at": "…", "updated_by": "1",
    "content_modified_at": "…", "content_modified_by": "1",
    "trashed_at": null, "trashed_by": null, "trash_root": false,
    "presentation": {
      "handler": "vaulter/preview",
      "document_type": null,
      "type_available": true,
      "viewer": { "key": "vaulter/pdf-preview", "props": {} },
      "capabilities": { "editable": false, "previewable": true, "downloadable": true, "thumbnailable": false, "collaborative": false }
    },
    "abilities": { "view": true, "download": true, "comment": true, "edit": true, "create": false, "move": true, "copy": true, "trash": true, "restore": true, "share": false, "manage": false, "delete": false },
    "viewer": { "starred": false },
    "scan_status": "clean",
    "file": { "revision_id": "01J…", "revision_number": 3, "size_bytes": 1024, "sha256": "…", "mime": "application/pdf", "scan_status": "clean" },
    "document": null
  }
}
```

- `presentation` is `null` for a folder; a document carries `presentation.document_type`
  and `handler: null`, and its head in `document`.
- `abilities` are yours, evaluated for the whole page at once; `etag` is the tag of
  exactly this representation (see [Validators](#validators-and-preconditions)).
- Gone compared with 0.x: `storage_id`, `file_id`, `current_version_id`, `handler`
  (→ `presentation`), `is_pinned`/`pinned`/`position` (→ `viewer.starred`), `owned_by`.

### Listing and reading

#### `GET v1/drives/{drive}/nodes?parent=` — `nodes.index`

The visible children of `parent`, or of the drive root without it. A cursor page of Node.
On this and the other drive-scoped routes that name a node inside the drive (`folders.store`,
`files.store`, `uploads.store`), a miss in a drive whose root you cannot see answers
`404 drive_not_found` — like an unknown drive — and only a miss in a drive you can see answers
`404 node_not_found`.

#### `GET v1/drives/{drive}/search?q=` — `nodes.search`

Live nodes whose name matches `q` (required), limited to what you can see. A cursor page.

#### `GET v1/drives/{drive}/starred` — `nodes.starred`

Your starred nodes of the drive that you can still see, newest star first. A cursor page.

#### `GET v1/shared` — `shared.index`

Nodes other people granted you, across drives — a cursor page like any other list (the
0.x offset pagination with `total` is gone). Needs the `grants` feature, otherwise
`403 profile_restriction`.

#### `GET v1/nodes/{node}?include=breadcrumb,children` — `nodes.show`

One node with its `ETag`; `If-None-Match` answers `304` after authorization. `include`
adds `breadcrumb` — `[{ "id", "name" }]` of the visible ancestors, root first, the node
last — and `children`, the **first page** of a folder's children
(`{ "data": [Node…], "meta": { "next_cursor", "per_page" } }`, `null` for a file); it is
not a full walk. A response with `include` is another variant: it carries no `ETag` header
and is never `304`.

#### `GET v1/nodes/{node}/abilities?explain=` — `nodes.abilities`

`{ "data": { "abilities": { …12… }, "explain": null } }`. `explain=<ability>` adds
`{ "ability", "allowed", "reasons": [ … ] }`; explaining reveals the grants behind the
answer, so it needs `manage` (`403 permission_denied` otherwise).

### Changing nodes

Routes marked **If-Match** require the node's current tag (`428` without it, `412` when
it is stale — see [Validators](#validators-and-preconditions)). The ability a route needs is
checked first: a caller who sees the node but lacks it gets `403 permission_denied`, never a
`428` or `412`. Each answers the changed Node with its new `ETag`.

#### `POST v1/drives/{drive}/folders` — `folders.store`

| Field | Rule |
|---|---|
| `name` | required, a valid node name |
| `parent_id` | optional string; the drive root when absent |

Needs `create` in the parent. **Response:** `201` Node.

#### `PATCH v1/nodes/{node}` — `nodes.update` · **If-Match**

`name` (required). Needs `edit`. Only the name changes here; properties have their own route.

#### `PATCH v1/nodes/{node}/properties` — `nodes.properties.update` · **If-Match**

| Field | Rule |
|---|---|
| `namespace` | required, e.g. `acme`; `vaulter` and `vaulter.*` are reserved (`422 invalid_properties`) |
| `set` | object of string values to write |
| `unset` | list of keys to remove |

One namespace changes atomically. Needs `edit`.

#### `PUT v1/nodes/{node}/star` — `nodes.star` · `DELETE v1/nodes/{node}/star` — `nodes.unstar`

Your personal star (needs `view`). Idempotent, no If-Match: it changes only your
`viewer.starred` — and so your tag — never the node's `sequence`.

#### `POST v1/nodes/{node}/move` — `nodes.move` · **If-Match**

| Field | Rule |
|---|---|
| `destination_id` | string or `null` (the drive root); must be a live folder of the same drive |
| `on_conflict` | `fail` (default, `409 node_name_conflict`) or `rename` |

Needs `move` on the node and `create` in the destination.

#### `POST v1/nodes/{node}/copy` — `nodes.copy`

`destination_id` (the drive root when absent), `name` (optional). The source does not
change, so there is no If-Match; a taken name gets a free one (`name (2)`). Needs `copy`
on the source and `create` in the destination. **Response:** `201` Node.

### Trash

#### `POST v1/nodes/{node}/trash` — `nodes.trash` · **If-Match**

Moves the node and its subtree to the trash; the node becomes a trash root. Needs `trash`.

#### `GET v1/drives/{drive}/trash` — `trash.index`

The trash roots you can see, a cursor page. Each item's `etag` is what restore and purge
expect — a trashed node cannot be read through `nodes.show`.

#### `POST v1/nodes/{node}/restore` — `nodes.restore` · **If-Match**

| Field | Rule |
|---|---|
| `destination_id` | optional folder; without it the folder the node was trashed from |
| `on_conflict` | `rename` (default) or `fail` |

Needs `restore` (and `create` when it lands elsewhere). A member trashed with a folder →
`409 node_trashed_by_ancestor` (`meta.context.trash_root_id`); a gone original folder
without `destination_id` → `409 restore_destination_required`.

#### `DELETE v1/nodes/{node}` — `nodes.purge` · **If-Match**

Deletes a trashed node and its subtree for good. Only from the trash (`409
node_not_trashed` otherwise); needs `delete`. The tag is checked before anything is
removed, so a stale one leaves rows, quota and stored bytes untouched. **Response:** `204`.

#### `DELETE v1/drives/{drive}/trash` — `trash.empty`

Purges every trash root you may delete; the others stay.
**Response:** `200 { "data": { "purged_count": 2 } }`.

### Files and revisions

#### `POST v1/drives/{drive}/files` — `files.store`

Multipart, one request: `file` (size cap `vaulter-drive.uploads.max_file_size`, optional
mime allow-list), `parent_id`, `name`. Needs `create` in the parent. **Response:** `201`
Node. For large files use the [resumable uploads](#resumable-uploads).

#### `GET v1/files/{node}/revisions` — `files.revisions.index`

The retained revisions, newest first, a cursor page of FileRevision:

```json
{
  "id": "01J…", "number": 3, "size_bytes": 1024, "mime": "application/pdf",
  "declared_mime": "application/pdf", "sha256": "…", "scan_status": "clean",
  "keep_forever": false, "is_head": true, "created_by": "1", "created_at": "…"
}
```

#### `POST v1/files/{node}/revisions` — `files.revisions.store` · **If-Match**

Multipart `file`: the next revision. Needs `edit`. **Response:** `201` FileRevision.

#### `POST v1/files/{node}/revisions/{revision}/restore` — `files.revisions.restore` · **If-Match**

Copies an older revision forward as the new head (its bytes count against the quota
again). Needs `edit`. **Response:** `200` FileRevision.

#### `PATCH v1/files/{node}/revisions/{revision}` — `files.revisions.update`

`keep_forever` (required boolean): pin a revision or hand it back to the retention policy.
Needs `edit`.

#### `DELETE v1/files/{node}/revisions/{revision}` — `files.revisions.destroy`

Deletes a revision and releases its bytes; needs `manage`. The head →
`409 cannot_delete_head_revision`; a pinned one → `409 revision_pinned`. **Response:** `204`.

#### `GET v1/files/{node}/download` — `files.download`

#### `GET v1/files/{node}/revisions/{revision}/download` — `files.revisions.download`

The bytes of the head or of one exact revision, through the
[file delivery](#file-delivery) rules: a stream in `strict` mode, a `302` to a signed URL
in `direct_signed` mode; `403 permission_denied` for a caller who may only view;
`423 content_unavailable` for a revision that is not clean — both before any byte.

---

## Resumable uploads

Chunked uploads (`tus` 1.0.0 core or S3-style multipart) that end in the same race-free
commit as a single-shot upload; the assembled bytes are hashed again, a client checksum or
part ETag is never the content address. Throttled by `throttle:vaulter-uploads`. See
[Uploads](/guide/uploads).

`vaulter-drive.uploads.resumable.enabled = false` keeps the routes registered and refuses
creating, appending, completing, aborting, `HEAD` and `OPTIONS` with
`403 profile_restriction` (a target you cannot see is still `404`); the status poll keeps
working.

#### `POST v1/drives/{drive}/uploads` — `uploads.store`

| Field | Rule |
|---|---|
| `protocol` | `tus` (default) or `s3-multipart` |
| `parent_id` | optional string — the folder of a new file (the drive root when absent) |
| `node_id` | optional string — an existing file to upload a new revision of; **needs If-Match** with that file's tag |
| `name` | optional, max 255, no path separators |
| `total_size_bytes` | optional integer ≥ 0, checked against the drive policy up front |
| `expected_sha256` | optional, 64 hex chars (declared, never trusted) |
| `mime_type` | optional, max 191 |
| `metadata` | optional object; keys outside `vaulter-drive.uploads.resumable.metadata.allowed_keys` are dropped |

Needs `create` in the folder, or `edit` on the file. The If-Match of `node_id` is checked
when the session is created; the commit at the end does not compare it again (a persisted
expectation is planned after 1.0). **Response:** `201` UploadSession; for tus also
`Tus-Resumable`, `Location: …/v1/uploads/{upload}` and `Upload-Offset: 0`.

```json
{
  "data": {
    "upload_id": "…", "drive_id": "01J…", "protocol": "tus", "status": "uploading",
    "received_bytes": 8388608, "total_size_bytes": 52428800, "chunk_size": 8388608,
    "mime_type": "application/pdf", "node_id": null, "target_parent_id": "01J…",
    "target_name": "report.pdf", "expires_at": "…"
  }
}
```

Only the session's creator may act on it afterwards (`403 permission_denied` for another
user); an unknown or foreign-tenant `{upload}` is `404`.

#### `GET v1/uploads/{upload}` — `uploads.show`

The session status (not part of tus core). **Response:** `200` UploadSession.

#### `HEAD v1/uploads/{upload}` — `uploads.tus.head`

`204` with `Tus-Resumable`, `Upload-Offset` (the server's resume point — resume from this,
never from your own guess) and `Upload-Length` when known.

#### `PATCH v1/uploads/{upload}` — `uploads.tus.patch`

An `application/offset+octet-stream` chunk at `Upload-Offset`. While incomplete `204` with
the advanced `Upload-Offset`; the last chunk commits the file and answers `201` Node (with
its `ETag`). Protocol refusals keep their tus headers inside `application/problem+json`:
a missing or other `Tus-Resumable` → `412` with `Tus-Version`; a wrong content type →
`415`; a chunk over `max_chunk_size` → `413`; an offset that does not match →
`409 upload_offset_mismatch` with `Upload-Offset`.

#### `DELETE v1/uploads/{upload}` — `uploads.tus.destroy`

Abort; staged bytes are reclaimed. `204` with `Tus-Resumable`.

#### `OPTIONS v1/uploads/{upload}` — `uploads.tus.options`

Discovery, without resolving a session: `Tus-Version`, `Tus-Extension: creation,termination`
and `Tus-Max-Size` when there is a cap.

#### `POST v1/uploads/{upload}/parts` — `uploads.parts.store`

The body is the part's bytes; `part_no` (query/body or `X-Part-Number`, default 1) and
`byte_offset`. A re-upload of a part replaces it. **Response:** `201
{ "part_no", "size", "etag" }`.

#### `POST v1/uploads/{upload}/complete` — `uploads.complete`

Stitches the parts and commits. **Response:** `201` Node.

---

## Comments

Comments on any node (`vaulter-documents`). The routes are always registered; the
operations check the switch, so with comments off — globally (`vaulter-documents.comments.enabled`)
or in the drive's profile — a node you can see answers `403 profile_restriction` and a node you
cannot see the usual `404 node_not_found`. A `{comment}` that belongs to another node is
`404 comment_not_found`. Read needs `view`, create `comment`, resolve `comment` and either
authorship or `edit`, delete the comment's author or `manage`. See [Comments](/guide/comments).

#### `GET v1/nodes/{node}/comments` — `comments.index`

**Response:** `200` page of Comment, oldest first.

#### `POST v1/nodes/{node}/comments` — `comments.store`

| Field | Rule |
|---|---|
| `body` | required, string, max 5000 |
| `parent_id` | optional, the id of a comment of the same node (a reply) |
| `anchor` | optional, object |

**Response:** `201` Comment.

#### `POST v1/nodes/{node}/comments/{comment}/resolve` — `comments.resolve`

**Response:** `200` Comment.

#### `DELETE v1/nodes/{node}/comments/{comment}` — `comments.destroy`

**Response:** `204`.

**Comment:** `id`, `node_id`, `parent_id`, `author` (`{ type, id }`), `body`, `anchor`,
`resolved_at`, `resolved_by`, `created_at`.

---

## Documents

Documents live in a drive next to files and folders (`vaulter-documents`). The routes act on
the content of a document under `documents/{node}`; a node of another kind answers
`409 not_a_document`. See [Documents](/guide/documents).

A document's **content** has its own validator, independent of the node's `sequence`:
`ETag: "{node}.{collab_epoch}.{state_version}"`. It does not depend on the caller, so a reader
and an editor receive the same body and the same tag, and a rename or move leaves it unchanged.
Routes marked **If-Match** need that tag; a stale or weak one is
`412 document_state_mismatch` — also when the collaboration hub stored a newer state in
between.

#### `POST v1/drives/{drive}/documents` — `documents.store`

| Field | Rule |
|---|---|
| `name` | required, string, no path separators |
| `parent_id` | optional (omit = the drive root) |
| `type` | optional, `vendor/name` (the configured default when absent); an unregistered type is `422 unknown_document_type` |
| `content` | optional, object — validated by the type's codec (`422 invalid_document_content`) |

**Gate:** `Create` on the parent and the profile's document types. **Response:** `201` Node
with its `ETag`.

#### `GET v1/documents/{node}/content` — `documents.content.show`

**Gate:** `view`. **Response:** `200 { "data": { "type", "schema_version", "state_version",
"collab_epoch", "revision_id", "content" } }` with the content `ETag`; `304` for a matching
`If-None-Match`, only after authorization.

#### `PUT v1/documents/{node}/content` — `documents.content.update` · **If-Match**

| Field | Rule |
|---|---|
| `content` | required, object — the whole new content |

**Gate:** `edit`. **Response:** `200` as above with the new `ETag`. Content that cannot be saved
back is `409 document_content_degraded`; a type that is not installed
`409 document_type_unavailable`.

#### `GET v1/documents/{node}/revisions` — `documents.revisions.index`

**Gate:** `view`. **Response:** `200` page of DocumentRevision, newest first.

#### `POST v1/documents/{node}/revisions` — `documents.revisions.store`

Checkpoint the working state as the new head.

| Field | Rule |
|---|---|
| `label` | optional, string, max 255 |
| `keep_forever` | optional, boolean |

**Gate:** `edit`. **Response:** `201` DocumentRevision.

#### `GET v1/documents/{node}/revisions/{revision}` — `documents.revisions.show`

**Gate:** `view`. **Response:** `200` DocumentRevision with its `content`. An unknown id, or one of
another document, is `404 document_revision_not_found`.

#### `POST v1/documents/{node}/revisions/{revision}/restore` — `documents.revisions.restore` · **If-Match**

Copies the revision forward as the working state and moves the collaboration epoch.
**Gate:** `edit`. **Response:** `200` content with the new `ETag`.

#### `PATCH v1/documents/{node}/revisions/{revision}` — `documents.revisions.update`

| Field | Rule |
|---|---|
| `keep_forever` | required, boolean — pin the revision out of pruning, or release it |

The label of a revision is set when it is created and does not change. **Gate:** `edit`.
**Response:** `200` DocumentRevision.

#### `GET v1/documents/{node}/export/{format}` — `documents.export`

`{format}` is `html`, `markdown` or another registered exporter format; `?revision={id}`
renders one checkpoint instead of the working state.

**Gate:** `download` and content egress. **Response:** the exporter's bytes as an attachment
(`Content-Disposition`, `X-Content-Type-Options: nosniff`,
`Content-Security-Policy: sandbox; default-src 'none'`, `Cache-Control: private, no-store`,
`Content-Length`); `X-Vaulter-Export-Lossy: 1` marks content its type could only read in part.
A format the type has no exporter for is `422 unknown_export_format` with `meta.allowed`. See
[Export](/guide/export).

**DocumentRevision:** `id`, `number`, `schema_version`, `label`, `is_autosave`, `keep_forever`,
`content_hash`, `is_head`, `created_by`, `created_at` (and `content` on a single read).

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

TipTap/Yjs collaboration over documents (`vaulter-documents.collaboration.enabled`). See
[Collaboration](/guide/collaboration).

#### `POST v1/documents/{node}/collaboration/session` — `documents.collaboration.session`

Joins the document's room: a session for its current generation, read-write for an editor,
read-only for everyone else who may view it.

| Field | Rule |
|---|---|
| `schema_version` | optional, integer — the schema the client's editor speaks; another version than the type's gets a read-only session |

**Gate:** `view`. **Response:** `201 { "data": { "driver", "room", "node_id", "drive_id", "epoch",
"schema_version", "mode", "token", "url" } }`, never stored. Switched-off collaboration, or a
type that cannot collaborate, is `409 collaboration_unavailable`.

### Hub endpoints (server to server)

The hub's calls use the `hub` stack: `throttle:vaulter-public` and `ResolveCollaborationCall`,
which requires the room JWT **and** the HMAC signature of the body. They are registered only
while collaboration is on, never take a user session, and answer refusals as problem+json
(`401 actor_required` for a call that is not signed). The protocol — room, epoch fence, seed,
`409 collaboration_epoch_mismatch` as a terminal answer — is unchanged; see
[Collaboration](/guide/collaboration#the-hub-protocol-server-to-server).

| Route | Name | Answers |
|---|---|---|
| `POST v1/collaboration/hub/authenticate` | `collaboration.hub.authenticate` | `{ allowed, mode, userId, epoch }` |
| `POST v1/collaboration/hub/load` | `collaboration.hub.load` | `{ state, content, epoch, schemaVersion, stateVersion, type }` |
| `POST v1/collaboration/hub/store` | `collaboration.hub.store` | `{ data: { changed, epoch, state_version, yjs_state_size } }` |

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

## OpenAPI and compatibility

[`openapi/vaulter-v1.yaml`](https://github.com/axiomasoft/vaulter/blob/main/openapi/vaulter-v1.yaml) (OpenAPI 3.1)
is the source of truth of this API: every route named `vaulter.v1.<name>` is an operation with
`operationId: <name>`, and this page explains what the file states. It describes the parameters, the
`If-Match` (`428` / `412`) and `If-None-Match` (`304`) rules, the cache and file-delivery headers, the
`application/problem+json` errors with their `code` values, and a schema for every resource.
`openapi/examples/` holds one real response per resource.

The contract is checked, not just written:

- **Coverage.** A test compares the route table with the document: a route without an operation, or an
  operation without a route, or a different method or path, fails.
- **Responses.** The `core`, `drive` and `documents` feature tests run with `VAULTER_OPENAPI_VALIDATE=1`;
  every `vaulter.v1.*` response is validated for status, headers, media type and body
  (`opis/json-schema`, draft 2020-12). An undocumented status or media type fails the test.
- **Route list.** `packages/core/tests/Fixtures/golden/routes-v1.json` pins names, methods, paths and
  middleware stacks; a changed route shows up in the pull request diff.
- **Breaking changes.** CI compares the document with the contract of the last released tag (the base branch
  before the first release that has the file) using `oasdiff breaking --fail-on ERR`; a breaking change to `v1`
  fails the build.

**Compatibility policy.** Inside `v1` only additive changes are made: a new route, a new optional parameter, a
new response field, a new problem `code`. Clients must ignore unknown fields. Removing or renaming a field,
changing its meaning or type, a new required request field, or a new status for an existing outcome needs `v2`.
When `v2` ships, `v1` answers with `Deprecation` and `Sunset` headers and stays supported for at least 12 months.

**Changing the contract.** Edit the routes and `openapi/vaulter-v1.yaml` in one change, then regenerate the
golden with `UPDATE_GOLDEN=1` and the examples with `VAULTER_OPENAPI_EXAMPLES_WRITE=1` (see
[CONTRIBUTING](https://github.com/axiomasoft/vaulter/blob/main/CONTRIBUTING.md)).

## See also

- [Drive](/guide/drive) — the `DriveHandle` handle the Drive routes wrap
- [Trash & restore](/guide/trash) — the trash routes' lifecycle
- [Uploads](/guide/uploads) · [Sharing](/guide/sharing) · [Collaboration](/guide/collaboration)
- [Events reference](/reference/events) · [Enums reference](/reference/contracts)
