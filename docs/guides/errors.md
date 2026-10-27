# Error Reference

Every Vaulter exception extends `Vaulter\Exceptions\VaulterException` and carries a
message in the form `[Vaulter] {what}. Why: {…}. Fix: {…}. Docs: {url}` where the URL
points at the matching anchor below.

## problem+json

Every failure of a versioned `/v1` route answers `application/problem+json` (RFC 9457) with
`type`, `title`, `status`, `code`, `detail`, `request_id`, `errors` and `meta`. Branch on `code`, a
stable SemVer contract; `title` and `detail` are localized text from `lang/` and may change, and
the exception message of the log never reaches the body. The full list of codes, generated from
the code catalogue, is the [error code reference](/reference/errors). The table below describes
the `{message, code}` body of the unversioned routes, which the versioned routes replace.

## domain-http-errors

Domain exceptions implement `Vaulter\Exceptions\Contracts\ProblemException`.
For JSON requests or routes under the configured Vaulter prefix, the response is
`{message, code}` with the following HTTP status. HTML requests to host routes and
configuration/infrastructure exceptions use Laravel's normal exception handler.
Codes are a stable public contract (AD29).

| Code | HTTP | Exception |
|:--|:--|:--|
| `permission_denied` | 403 | `Vaulter\Drive\Exceptions\Access\PermissionDeniedException` |
| `node_name_conflict` | 409 | `Vaulter\Drive\Exceptions\Nodes\NodeNameConflictException` |
| `node_move_cycle` | 409 | `Vaulter\Drive\Exceptions\Nodes\NodeCycleException` |
| `cross_drive_operation` | 409 | `Vaulter\Drive\Exceptions\Nodes\CrossDriveOperationException` |
| `invalid_destination` | 409 | `Vaulter\Drive\Exceptions\Nodes\InvalidDestinationException` |
| `node_not_trashed` | 409 | `Vaulter\Drive\Exceptions\Nodes\NodeNotTrashedException` |
| `node_trashed_by_ancestor` | 409 | `Vaulter\Drive\Exceptions\Nodes\NodeTrashedByAncestorException` |
| `restore_destination_required` | 409 | `Vaulter\Drive\Exceptions\Nodes\RestoreDestinationRequiredException` |
| `tree_traversal_incomplete` | 409 | `Vaulter\Drive\Exceptions\Nodes\TreeTraversalIncompleteException` |
| `drive_archived` | 409 | `Vaulter\Drive\Exceptions\DriveArchivedException` |
| `invalid_drive_state` | 409 | `Vaulter\Drive\Exceptions\InvalidDriveStateException` |
| `quota_exceeded` | 413 | `Vaulter\Drive\Exceptions\Quota\QuotaExceededException` |
| `upload_conflict` | 409 | `Vaulter\Drive\Exceptions\Uploads\UploadConflictException` |
| `upload_conflict` | 409 | `Vaulter\Drive\Exceptions\Uploads\StagedUploadMissingException` |
| `upload_offset_mismatch` | 409 | `Vaulter\Drive\Exceptions\Uploads\OffsetMismatchException` |
| `upload_rejected` | 422 | `Vaulter\Drive\Exceptions\Uploads\UploadContentMismatchException` |
| `not_a_document` | 409 | `Vaulter\Documents\Exceptions\NotADocumentException` |
| `unknown_document_type` | 422 | `Vaulter\Documents\Exceptions\UnknownDocumentTypeException` |
| `document_type_unavailable` | 409 | `Vaulter\Documents\Exceptions\DocumentTypeUnavailableException` |
| `document_content_degraded` | 409 | `Vaulter\Documents\Exceptions\DocumentContentDegradedException` |
| `duplicate_registration` | 500 (boot) | `Vaulter\Documents\Exceptions\DuplicateDocumentTypeException` |
| `registry_frozen` | 500 (boot) | `Vaulter\Exceptions\Registry\RegistryFrozenException` |
| `module_graph_invalid` | 503 | `Vaulter\Modules\ModuleGraphException` |
| `module_not_ready` | 503 | `Vaulter\Exceptions\Modules\ModuleNotReadyException` |
| `extension_failed` | 500 | `Vaulter\Exceptions\Support\InvalidExtensionResult` |
| `invalid_document_content` | 422 | `Vaulter\Documents\Exceptions\InvalidDocumentContentException` |
| `document_revision_not_found` | 404 | `Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException` |
| `comment_not_found` | 404 | `Vaulter\Documents\Exceptions\CommentNotFoundException` |
| `document_state_mismatch` | 412 | `Vaulter\Documents\Exceptions\StaleDocumentStateException` |
| `document_state_pending` | 409 | `Vaulter\Documents\Exceptions\DocumentStatePendingException` |
| `collaboration_epoch_mismatch` | 409 | `Vaulter\Collaboration\Exceptions\CollaborationEpochMismatchException` |
| `collaboration_seed_conflict` | 409 | `Vaulter\Collaboration\Exceptions\CollaborationSeedConflictException` |
| `collaboration_unavailable` | 409 | `Vaulter\Collaboration\Exceptions\CollaborationUnavailableException` |
| `collaboration_protocol_mismatch` | 409 | `Vaulter\Collaboration\Exceptions\CollaborationProtocolMismatchException` |
| `collaboration_checkpoint_conflict` | 409 | `Vaulter\Collaboration\Exceptions\CollaborationCheckpointConflictException` |
| `collaboration_update_mismatch` | 422 | `Vaulter\Collaboration\Exceptions\CollaborationUpdateMismatchException` |
| `collaboration_update_too_large` | 413 | `Vaulter\Collaboration\Exceptions\CollaborationUpdateTooLargeException` |
| `collaboration_draining` | 503 | `Vaulter\Collaboration\Exceptions\CollaborationDrainingException` |
| `document_state_too_large` | 413 | `Vaulter\Documents\Exceptions\DocumentStateTooLargeException` |
| `unknown_export_format` | 422 | `Vaulter\Documents\Exceptions\UnknownExportFormatException` |
| `invalid_document_content` | 422 | `Vaulter\Documents\Exceptions\UnsupportedDocumentContentException` |
| `invalid_node_name` | 422 | `Vaulter\Drive\Exceptions\Nodes\InvalidNodeNameException` |
| `invalid_properties` | 422 | `Vaulter\Drive\Exceptions\Nodes\InvalidPropertiesException` |
| `folder_children_limit_exceeded` | 409 | `Vaulter\Drive\Exceptions\Policy\FolderChildrenLimitExceededException` |
| `tree_depth_limit_exceeded` | 409 | `Vaulter\Drive\Exceptions\Policy\TreeDepthLimitExceededException` |
| `file_size_limit_exceeded` | 413 | `Vaulter\Drive\Exceptions\Policy\FileSizeLimitExceededException` |
| `cannot_delete_head_revision` | 409 | `Vaulter\Drive\Exceptions\CannotDeleteHeadRevisionException` |
| `revision_pinned` | 409 | `Vaulter\Drive\Exceptions\RevisionPinnedException` |
| `blob_unavailable` | 409 | `Vaulter\Media\Exceptions\Cas\BlobUnavailableException` |
| `validation_failed` | 422 | `Vaulter\Webhooks\Exceptions\BlockedOutboundUrlException` |
| `webhook_delivery_not_redeliverable` | 409 | `Vaulter\Webhooks\Exceptions\WebhookDeliveryNotRedeliverableException` |
| `invalid_webhook_signature` | 400 | `Vaulter\Webhooks\Exceptions\InvalidWebhookSignatureException` |
| `invalid_cursor` | 400 | `Vaulter\Drive\Exceptions\Changes\InvalidCursorException` |
| `cursor_expired` | 410 | `Vaulter\Drive\Exceptions\Changes\CursorExpiredException` |
| `validation_failed` | 422 | `Vaulter\Drive\Exceptions\Access\UnenumerableGrantSubjectException` |
| `unknown_profile` | 422 | `Vaulter\Drive\Exceptions\Profiles\UnknownProfileException` |
| `concurrent_update` | 409 | `Vaulter\Exceptions\Database\ConcurrentUpdateException` |
| `write_outside_operation` | 500 | `Vaulter\Exceptions\Database\WriteOutsideOperationException` |
| `node_not_found` | 404 | `Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException` |
| `drive_not_found` | 404 | `Vaulter\Drive\Exceptions\Nodes\StorageNotFoundException` |

The response also preserves `errors.name` for name conflicts and invalid names, `errors.url` for blocked
outbound URLs and `errors.subject_type` for unenumerable grant subjects. Content errors
include `reason`: `mime_mismatch`, `invalid_tiptap`, `invalid_json`, `too_large` or
`unsupported_content` (`missing_projection` for a collaboration store without the JSON its type
needs). `document_state_mismatch` and `collaboration_epoch_mismatch` include
`context.expected` and `context.actual`; `collaboration_unavailable` includes `context.reason`
(`disabled`, `unsupported_type`); `document_state_too_large` includes `context.size_bytes` and
`context.max_bytes`; `document_type_unavailable` and
`document_content_degraded` include `context.type`.
Offset mismatches include `Upload-Offset` and `Tus-Resumable: 1.0.0` headers.

## precondition-required

`PreconditionRequiredException` (428 `precondition_required`, `meta.header`, `meta.reason`) — a
request that changes state against a representation did not say which one it saw: the `If-Match`
header is missing (`reason: missing`) or is `*`, which matches any representation and so cannot
detect a lost update (`reason: wildcard`). Read the resource and send its `ETag` back in `If-Match`.

## node-not-found

`NodeNotFoundException::forId()` — the node does not exist or is not visible to the
current actor in the current drive/tenant. These cases use the same 404 response.

## storage-not-found

`StorageNotFoundException::forId()` — the storage does not exist or is not visible
to the current actor. Use an accessible storage; the response is 404 `drive_not_found`.

## permission-denied

`PermissionDeniedException` — either the legacy document path's authorization hook, or
the Drive ACL (`AccessResolver` + `PermissionDriver`), denied the attempted action. Grant
a role that carries the ability on the node or an ancestor above any inheritance boundary.
The API maps this to `403`.

## unknown-role

`UnknownRoleException` (422 `unknown_role`) — a grant, a share link or a permission driver
role map named a role (or role version) that is not registered. The 1.0 roles are
`previewer`, `reader`, `commenter`, `writer`, `organizer` and `owner`, all at version 1.

## share-role-not-allowed

`ShareRoleNotAllowedException` (422 `share_role_not_allowed`) — a share link cannot carry this
role for this audience. Links grant `previewer`, `reader` or `commenter` to any audience,
`writer` only to `authenticated` or `tenant` visitors, and never `organizer` or `owner`.

## share-not-found

`ShareNotFoundException` (`share_not_found`, 404) — a public share request that opens nothing: the
token is unknown, revoked, rotated, expired or used up, the visitor is outside the link's tenant,
or `?node=` names a node outside the link. All of these answer the same so a token cannot be
probed. Ask the owner for a current link.

## share-password-required

`SharePasswordRequiredException` (`share_password_required`, 401) — the link is password
protected and the request carried no password, or a wrong one. Send it in the `X-Share-Password`
header (not the URL, which ends up in logs).

## share-link-ttl-exceeded

`ShareLinkTtlExceededException` (422 `share_link_ttl_exceeded`, extra `limit_days`) — the link
would stay valid longer than the drive profile's `maxShareLinkTtlDays`. The limit applies when
a link is created or changed and again on every visit, so tightening it also ends older links
until their owner shortens them.

## unknown-document-type

`UnknownDocumentTypeException` — no registered [document type](/extending/document-types) has the
supplied key. Add its class to `config('vaulter-documents.types')`, tag it
`vaulter.document_types`, or register it from a service provider with
`Documents::types()->register()`.

## document-type-unavailable

`document_type_unavailable` (409) — the document's type is not installed (its extension was
removed). The document can still be read as JSON, copied, trashed and purged; editing,
checkpointing, restoring, exporting and collaborating need the type registered again.
`context.type` names the missing key.

## document-content-degraded

`document_content_degraded` (409) — the stored content contains parts its type does not accept
any more (the nodes of an editor extension that was switched off), or it was written in a newer
schema version than the installed type. It was read with those parts left out, so saving over it
would delete them; the document is read-only until the extension is enabled again.

## document-state-pending

`document_state_pending` (409) — edits are saved but not checkpointed. The registered protocol drainer must publish the authoritative state before read/export/restore/purge can proceed. An unavailable drainer fails closed; retry after recovery.

## registry-frozen

`RegistryFrozenException` (`registry_frozen`) — something registered a document type after the
application booted. Register types from a service provider (config, a container tag, or
`register()` before the application has booted).

## blob-unavailable

`BlobUnavailableException` (409 `blob_unavailable`) — the stored bytes of a file are no longer available, or an
owner tried to pin a blob that is deleting or deleted (`BlobPins::pin()`, [Storage & blobs](/concepts/storage-and-blobs#pinning-and-reading)).
Nothing was written. Upload the content again: committing the bytes resurrects a tombstoned blob while they still
exist, and the returned blob can be pinned.

## module-graph-invalid

`ModuleGraphException` (503 `module_graph_invalid`) — the installed module manifests do not compile:
a duplicate id, an unknown or non-boolean `vaulter.modules` key, an active module whose dependency
is inactive, a missing or doubled capability provider, a contribution to an unknown slot. Nothing is
activated; `vaulter:modules` and `vaulter:doctor` print every reason. Fix the manifests or
`vaulter.modules`, then rebuild the caches and restart the workers
([ADR-0027](/adr/0027-kernel-boundaries-and-modules)).

## module-not-ready

`ModuleNotReadyException` (503 `module_not_ready`) — a write reached a module that is inactive or
whose tables do not exist yet (migrations not run). The operation is refused before any transaction
opens; run `php artisan migrate` (or enable the module and its dependencies in `vaulter.modules`) and
retry. A ready module is remembered for the life of the worker process
([ADR-0028](/adr/0028-extension-slots-and-operation-pipeline)).

## extension-failed

`InvalidExtensionResult` (500 `extension_failed`) — a module's contribution to a slot broke the
slot's contract: an operation middleware that did not call `$next` exactly once, passed another
operation, or returned something other than `$next`'s result; a snapshot contributor with a reserved
or duplicate fragment key. The operation did not run or was rolled back. It is an integration error:
it is never retried and never cached. Fix or deactivate the contributing module
([ADR-0028](/adr/0028-extension-slots-and-operation-pipeline)).

## idempotency-key-reused

`IdempotencyKeyReusedException` (422 `idempotency_key_reused`) — the same actor already used the
`Idempotency-Key` for this operation with a different request (other ids, fields or preconditions).
Nothing ran; the stored outcome belongs to the first request. Send a new key for a new request
([idempotency](/concepts/idempotency), [ADR-0029](/adr/0029-idempotency)).

## operation-in-progress

`OperationInProgressException` (409 `operation_in_progress`, `Retry-After`) — a request with the same
`Idempotency-Key` is still running and did not finish within the bounded wait (2 seconds). Nothing ran;
repeat the request after `Retry-After` to receive the outcome of the first one
([idempotency](/concepts/idempotency)).

## duplicate-registration

`DuplicateDocumentTypeException` (`duplicate_registration`) — one document type key was
registered twice. Register it once, or override it with `Documents::types()->replace()`.

## invalid-destination

`InvalidDestinationException` — the destination of a create, upload, move, copy or
document creation is not a folder, is in the trash, or (for documents) belongs to
another drive; or the operation targets the drive root, which is never renamed, moved,
copied, trashed or deleted; or the node is already in the trash. Pick a live folder of
the same drive (no destination means the root). Drive operations report a folder of
another drive as `cross_drive_operation`.

## invalid-node-name

`InvalidNodeNameException` (422 `invalid_node_name`, with `reason` and `errors.name`) — the
new name broke the base rules or the profile's name policy. Nothing is trimmed. `reason` is one
of `empty`, `path_alias` (`.`/`..`), `separator` (`/`, `\`), `control_char`, `reserved_char`
(`< > : " | ? *` under `portable-v1`, or invalid UTF-8), `trailing_dot_or_space`,
`leading_or_trailing_space`, `reserved_name` (Windows device names, `desktop.ini`, `.DS_Store`)
or `too_long` (over 255 UTF-8 bytes or UTF-16 code units). Names are stored in Unicode NFC
when ext-intl is installed.

## folder-children-limit-exceeded

`FolderChildrenLimitExceededException` (409 `folder_children_limit_exceeded`, `limit`) — the
folder already holds the drive policy's `maxFolderChildren` nodes; trashed children count until
purged. Use another folder or purge its trash.

## tree-depth-limit-exceeded

`TreeDepthLimitExceededException` (409 `tree_depth_limit_exceeded`, `limit`) — the create, move,
copy or restore would place a node deeper than the drive policy's `maxFolderDepth`. Choose a
shallower destination.

## file-size-limit-exceeded

`FileSizeLimitExceededException` (413 `file_size_limit_exceeded`, `limit`) — the declared,
streamed or final size is above the drive policy's `maxFileBytes` or the installation's
`vaulter-drive.uploads.max_file_size`.

## invalid-properties

`InvalidPropertiesException` (422 `invalid_properties`, `reason`) — a properties patch broke the
contract: `namespace` (`^[a-z0-9][a-z0-9.-]{0,127}$`), `key` (`^[A-Za-z0-9_.-]{1,64}$`),
`value_too_long` (values are UTF-8 strings of at most 1024 bytes), `too_many` / `too_large`
(the drive policy's `maxPropertyCount` / `maxPropertiesBytes`) or `reserved_namespace`
(`vaulter` and `vaulter.*` are system-only).

## invalid-tiptap-content

`InvalidDocumentContentException::missingDocNode()` — the content root must be
`{"type":"doc", …}`. Pass a valid TipTap JSON document or use `TipTapContent::empty()`.

## invalid-document-content

`InvalidDocumentContentException::notAnObject()` (422 `invalid_document_content`, reason
`invalid_json`) — the document type stores a JSON object (a spreadsheet, a host's own type) and
received a list or a scalar. Send an object. A content above the type's `maxSizeBytes()` or
`vaulter-documents.content.max_size_kb` answers the same code with reason `too_large`.

## content-too-large

`InvalidDocumentContentException::tooLarge()` — the content exceeds
`config('vaulter-documents.content.max_size_kb')`. Split the document or raise the limit.


## cross-storage-move

`CrossDriveOperationException` — a move stays within one storage tree. Copy across storages,
or re-home the node into the destination storage explicitly instead of moving it.

## comment-not-found

`CommentNotFoundException` — the comment does not exist or belongs to a different node (a reply's
`parent_id` too). List the node's comments and use an id from that list.

## document-version-not-found

`DocumentRevisionNotFoundException` — the version does not exist or belongs to a
different node. List the node's history (`GET .../revisions`) and restore an id
from it.

## missing-host-role-driver

`MissingPermissionDriverException` — either the open `null` host-role driver was selected in
production (forbidden — it grants every actor full access), or `config('vaulter-drive.access.permission_driver')`
names a driver key not in the `vaulter-drive.access.permission_drivers` map. Set a real driver
(`database`/`gate`/`spatie`/`azgard`) or bind your own `PermissionDriver`.

## missing-package

`MissingPackageException` — a contract's concrete implementation ships in a package that
is not installed (e.g. `NodePresenter`/`FileHandlerRegistry` need vaulter-documents).
Run the printed `composer require`.

## move-cycle

`NodeCycleException` — the move destination is the node itself or one of its own
descendants, which would create a cycle. Choose a destination outside the moved node's
subtree.

## node-name-conflict

`NodeNameConflictException` — a node with that name already exists under the same parent
(or storage root). Pick a different name, or move/trash the existing sibling first.

Name occupancy is visible to anyone who may create under the parent: a user with `Create` on
the parent can learn that a name is taken even when the sibling holding it is hidden from them
(no `View`). This is accepted for 1.0 (Drive and Nextcloud behave the same) and is bounded:
under the `Fail` policy the 409 carries only the requested name and the parent, never the
id, kind or stored name of the hidden sibling. With the `AddRevision` policy a hidden sibling
must answer as `node_not_found` (404), exactly like any other node without `View`.

## node-not-trashed

`NodeNotTrashedException` — restore and permanent delete only apply to a node in the
trash. Trash the node first; a live node is never purged directly.

## node-trashed-by-ancestor

`NodeTrashedByAncestorException` — a node that was trashed together with a folder is restored
through that folder, not on its own. The response carries `context.trash_root_id`, the root to
restore (or purge). Only a trash root (`trash_root_id = id`) can be restored.

## restore-destination-required

`RestoreDestinationRequiredException` — the folder the node was trashed from is gone or is in
the trash, and a restore never moves a node silently (it used to fall back to the drive root).
Pass an explicit destination: `RestoreOptions(destination: $folder)` or `destination_id` on
`POST nodes/{node}/restore`. The caller needs `Create` on that folder.

## tree-traversal-incomplete

`TreeTraversalIncompleteException` — the folder tree under the node is deeper than a bounded
traversal can read (the deepest `maxFolderDepth` of any profile, at least 64 levels), so the copy,
trash or purge cannot prove it sees every descendant. It stops before any write instead of acting
on part of the tree. Act on a shallower folder first.

## drive-archived

`DriveArchivedException` — an archived drive is read-only: every mutation, purge and creation by
a non-system actor is refused, reads and downloads still work. Unarchive the drive first
(`$drive->unarchive()`, needs `Manage` on the root).

## invalid-drive-state

`InvalidDriveStateException` — the drive lifecycle only allows `active → archived → active` and
`active|archived → deleting`. `archive()` needs an active drive, `unarchive()` an archived one.

## not-a-document-node

`NotADocumentException` — document content/version operations require a
`kind=document` node (its payload lives in its `v_documents` aggregate). Create the
document via the document-node create endpoint first, or target a document node.

## quota-exceeded

`QuotaExceededException` — the upload would exceed the storage's `quota_bytes`. Raise
`v_drives.quota_bytes`, free space, or set `quota_bytes` to `null` for unlimited.

## unenumerable-grant-subject

`UnenumerableGrantSubjectException` — the configured `PermissionDriver` does not enumerate
role/group/team subjects, so a role/team grant would never take effect. Use a driver that
enumerates role subjects (`spatie`), or override `subjectsFor()` on your own driver to
append the role/team `Subject`s.

## unknown-export-format

`UnknownExportFormatException` — no exporter renders the requested format for the document's
type. The response lists the formats the type does have in `allowed`. Register an exporter via
`config('vaulter-documents.exporters')` or `Documents::exporters()->register()`.

## unsupported-document-content

`UnsupportedDocumentContentException` — an exporter received a `DocumentContent` shape it
doesn't understand. `DocumentExporter::export()` takes the opaque `DocumentContent`, so an
exporter that only renders one shape (the built-in HTML/Markdown exporters render
`TipTapContent`) narrows with an `instanceof` and throws this when the narrowing fails.
Throw it from your own exporter's guard; catch it if you call an exporter directly.

The registry only routes a document to exporters that list its type in `documentTypes()`, so
this error surfaces on **direct exporter calls and exporters that list a type whose content they
cannot render**.

## upload-conflict

`UploadConflictException` / `OffsetMismatchException` — the resumable-upload session is
being modified by a concurrent request, or the `Upload-Offset` header doesn't match the
server's authoritative offset. Re-read the session `HEAD` to learn the current offset and
resume from there.

## staged-upload-missing

`StagedUploadMissingException` — the session claims a non-zero `Upload-Offset`, but the
staged object holding those already-received bytes is gone (a concurrent sweep/abort
reclaimed it, or the bucket was cleaned out of band). The chunk is refused instead of being
written on its own, which would silently truncate the file. Re-read the session `HEAD`; if
the session is no longer open, start a new upload.

## upload-content-mismatch

`UploadContentMismatchException` — the assembled upload's bytes don't match the declared
`Content-Type`. Upload a file whose contents match the declared mime type.

## webhook-response-too-large

`WebhookResponseTooLargeException` — the webhook receiver's response body exceeds
`vaulter-webhooks.delivery.max_response_bytes`. Have the receiver return a small (or
empty) body, or raise the limit.

## webhook-ssrf

`BlockedOutboundUrlException` — the outbound webhook URL was refused: a disallowed
scheme, a malformed URL, or a host resolving to a blocked network (SSRF protection).
Register the endpoint with a public http(s) URL.

## webhook-delivery-not-redeliverable

`WebhookDeliveryNotRedeliverableException` — a manual redelivery was asked for a delivery that is
still `pending` or `failed` (its retries are not finished) or whose endpoint is inactive. Wait
for the retries to end or for the delivery to die, and re-enable the endpoint first.

## webhook-endpoint-not-found

`WebhookEndpointNotFoundException` (`webhook_endpoint_not_found`, 404) — no webhook endpoint has
the id in the URL; it may have been deleted. List the endpoints and use a current id.

## webhook-delivery-not-found

`WebhookDeliveryNotFoundException` (`webhook_delivery_not_found`, 404) — the delivery does not
exist, belongs to another endpoint, or was pruned after its retention period.

## webhook-signature

`InvalidWebhookSignatureException` — `StandardWebhookVerifier` refused a received webhook: a
header is missing, `webhook-timestamp` is outside the tolerance, or no signature matches the raw
body and the secrets. Verify the unmodified request body with the endpoint's `whsec_` secret (both
secrets during a rotation) and check the receiver's clock. See
[Webhooks → Verifying a delivery](/guides/webhooks#verifying-a-delivery).

## invalid-change-cursor

`Vaulter\Drive\Exceptions\Changes\InvalidCursorException` (`invalid_cursor`, 400) — a
[change cursor](/concepts/events) that this application did not sign, that was altered, or that
belongs to another drive or tenant. Cursors are opaque: store them as they are. Start again from
`startCursor()` after a full listing.

## cursor-expired

`Vaulter\Drive\Exceptions\Changes\CursorExpiredException` (`cursor_expired`, 410) — entries after the
cursor were deleted by `vaulter:changes:prune` (older than `vaulter-drive.changes.retention_days`), or the drive's
journal was reset with `vaulter:changes:reset`. Resynchronize: take a new `startCursor()`, list the
drive again and replace the local copy. See [Change journal → Expired cursors](/concepts/events#expired-cursors).

## invalid-cursor

`InvalidCursorException`: 422 `validation_failed`, with `errors.cursor`. Restart the
listing without a cursor; the signature, listing scope or ordering did not match.


## actor-required

`actor_required` (401) means the current operation has neither an authenticated
user nor an explicit trusted system actor. Authenticate, use `actingAs($user)`,
or use `asSystem($reason)` in trusted maintenance or a tenant-validated job.
System actors still enforce tenant scope, profile policy and content availability.

## profile-restriction

`profile_restriction` (403) means the effective drive profile disables the
requested operation. Global and profile policies both apply. An actor without
View receives `node_not_found` (404) before profile restrictions are disclosed.

## concurrent-update

`concurrent_update` (409, with `Retry-After: 1`) means a deadlock or serialization failure
survived the bounded outermost retry (three attempts for upload completion). Nothing was
committed and the staged upload bytes are intact, so repeat the request unchanged.

## document-state-mismatch

`document_state_mismatch` (412) — the save carried `expectedStateVersion`, but another save of
the document's content landed first (`context.actual` is the current `state_version`). Nothing
was written. Reload the document and apply the edit again. Renames and moves never cause this:
the node and its content have separate validators.

## collaboration-unavailable

`collaboration_unavailable` (409) — the stored protocol binding or required adapter is unavailable, or the document type does not support it. Restore the matching adapter or edit through the public content endpoint when the state permits.

## document-state-too-large

`document_state_too_large` (413) — the document state exceeds the caller-supplied limit. Nothing was written. Review the host limit or split the document.

## write-outside-operation

`write_outside_operation` (500, an integration error) — code outside the Vaulter operations saved,
updated or deleted a Vaulter model (`Node::query()->update(...)`, `$node->save()`). Rows change only
through the `DriveHandle` operations, which keep the invariants, the authorization and the audit
trail; the model guard (`vaulter.database.write_guard`, default `true`) refuses the write before it
reaches the database. Move the write to an operation. `DB::table()` and raw SQL are not intercepted:
the guard is defence in depth, not a sandbox.
