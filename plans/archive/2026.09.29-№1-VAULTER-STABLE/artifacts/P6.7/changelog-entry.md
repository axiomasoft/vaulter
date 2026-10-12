### REST v1: drive routes (PLAN2.P6.7)

- Added: the drive routes on REST v1 under `/{routes.prefix}/v1`, named `vaulter.v1.*`, as single-action
  controllers over the drive operations — drives (`drives.show/archive/unarchive/destroy`), the tree
  (`nodes.index/search/starred/show/update/properties.update/star/unstar/move/copy/abilities`, `folders.store`,
  `shared.index`), trash (`trash.index/empty`, `nodes.trash/restore/purge`), files and revisions
  (`files.store/download`, `files.revisions.index/store/download/restore/update/destroy`) and resumable uploads
  (`uploads.store/show/tus.head/tus.patch/tus.destroy/tus.options/parts.store/complete`). Every node goes out as
  the caller's representation with batched abilities; lists are cursor pages without totals (PLAN2.D63, D65).
- Added: `If-Match` is required on rename, properties, move, trash, restore, purge, adding and restoring a
  revision, and on creating an upload session for an existing file (`428` without it or with `*`, `412
  node_sequence_mismatch` when stale), after View and the route's ability (`403` first); the matched sequence
  is the operation's compare-and-set expectation. A miss inside a drive whose root the caller cannot see answers
  `drive_not_found`, like an unknown drive.
  `GET v1/nodes/{node}` answers `304` to `If-None-Match` only after authorization; `include=breadcrumb,children`
  returns the visible ancestors and the first page of children, without an `ETag` (PLAN2.D62, C07).
- Added: optional expected sequences in the PHP API — `TrashOperations::purge($node, ?int $expectedSequence)`,
  `RestoreOptions::$expectedSequence` and `FileOperations::restoreRevision($file, $revision, ?int
  $expectedSequence)` — checked on the locked row before rows, bytes, quota or position change (PLAN2.D62).
- Added: downloads of a file or one revision go through `ContentResponder` (strict stream or `direct_signed`
  `302`; `403` for a previewer and `423 content_unavailable` before any byte); `Drive`, `FileRevision` and
  `UploadSession` resources on snapshots with string ids; `drive_id` on upload sessions (C08, D44).
- Changed: resumable uploads and the shared-with-me listing are refused by the operations
  (`403 profile_restriction`) when switched off instead of by route registration, so `route:cache` cannot freeze
  them; tus refusals keep `Tus-Resumable`/`Tus-Version` inside `application/problem+json`.
- Changed: `VaulterHttpOperationMap` and `VaulterThrottledRouteMap` label drive requests by v1 route name;
  URI-only collectors keep path rules for the byte-heavy v1 routes. `HttpOperationContextFactory::onDrive()`
  maps a drive that started being deleted after resolution to `drive_not_found`.
- Removed: the unversioned drive routes (`storages/*`, `nodes/{node}`, `nodes/{node}/{move,copy,restore,force,
  download,versions/…}`, `shared`, `resumable-uploads/*`), `NodeController` and `DownloadController`, the drive
  resource's `panel`, `slug`, `disk`, `storage_type`, `updated_at` and `abilities` and the upload session's
  `assembled_blob_id`. No aliases.
