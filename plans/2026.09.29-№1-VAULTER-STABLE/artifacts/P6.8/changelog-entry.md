### REST v1: documents routes and the end of the unversioned HTTP surface (PLAN2.P6.8)

- Added: the documents routes on REST v1 under `/{routes.prefix}/v1`, named `vaulter.v1.*`, as single-action
  controllers over `DocumentOperations` and `CommentOperations` — `documents.store`, `documents.content.show/update`,
  `documents.revisions.index/store/show/restore/update`, `documents.export`, `documents.collaboration.session` and
  `comments.index/store/resolve/destroy` (PLAN2.D63, D65). The routes are always registered; a switched-off feature
  is refused by the operations (`403 profile_restriction` for comments, `409 collaboration_unavailable` for a
  session) instead of by route registration, so `route:cache` cannot freeze it.
- Added: document content has its own validator, `ETag: "{node}.{collab_epoch}.{state_version}"`, the same for every
  caller (a reader and an editor get one body and one tag; a rename never changes it). `PUT
  documents/{node}/content` and a revision restore require `If-Match` (`428` without it or with `*`, `412
  document_state_mismatch` for a stale or weak tag — also after a hub store landed in between), after View and Edit
  (`403` first); the matched state version is the operation's compare-and-set expectation. `GET` answers `304` to
  `If-None-Match` only after authorization (PLAN2.D35, D62).
- Added: `GET documents/{node}/revisions/{revision}` (one revision with its content), `PATCH
  documents/{node}/revisions/{revision}` `{keep_forever}` (a revision label is fixed when it is created) and
  `POST documents/{node}/collaboration/session` (`201`, never cached). Lists are cursor pages without totals.
- Added: `ContentResponder::export()` — a document export leaves as an attachment with `nosniff`,
  `Content-Security-Policy: sandbox`, `Cache-Control: private, no-store` and `Content-Length`;
  `GET documents/{node}/export/{format}` takes `?revision=` for one checkpoint (PLAN2.D29, C08).
- Added: `HttpOperationContextFactory::withinDrive()` (the drive-id-plus-child-id call that answers `drive_not_found`
  when the caller cannot see the drive), shared by the drive and documents routes.
- Changed: the hub endpoints are `POST v1/collaboration/hub/{authenticate,load,store}` on the `hub` stack; the
  protocol of D36 is unchanged, refusals are `application/problem+json` (an unsigned call is `401 actor_required`,
  a malformed body `422 validation_failed`).
- Changed: `VaulterHttpOperationMap` and `VaulterThrottledRouteMap` label documents and hub requests by route name;
  the throttled map resolves by name only, and the map's path rules left are URI-only collectors for the v1 routes.
- Removed: every unversioned route — `nodes/{node}/{content,document-versions,handler,export,collab/token,comments}`,
  `storages/{storage}/documents`, `collab/{authenticate,load,store}` — without aliases (`GET nodes/{node}/handler` has
  no replacement: the node's `presentation` carries it). Removed classes: `DocumentNodeController`,
  `NodeCommentController`, `NodeCollaborationController`, `ResolveVaulterRouteParameters`, `LegacyNodeResource`,
  `CreateNamedVersionRequest`; `VaulterException::render()` and the `{message, code}` body;
  `HttpResourceResolver::storage()` and `::node()`. The `format`/`mime` fields of document creation and the
  `yjs_state`/`state_version` fields of a content write are gone (`type`, `If-Match`).
- Renamed: `CreateDocumentNodeRequest` → `CreateDocumentRequest`, `StoreNodeCommentRequest` → `AddCommentRequest`,
  `NodeCommentResource` → `CommentResource`; `DocumentRevisionResource` is the revision shape `{id, number,
  schema_version, label, is_autosave, keep_forever, content_hash, is_head, created_by, created_at}`; the content
  shape is the new `DocumentContentResource`.
