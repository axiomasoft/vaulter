# Error Reference

Every Vaulter exception extends `Vaulter\Exceptions\VaulterException` and carries a
message in the form `[Vaulter] {what}. Why: {…}. Fix: {…}. Docs: {url}` where the URL
points at the matching anchor below.

## domain-http-errors

Domain exceptions implement `Vaulter\Exceptions\Contracts\ProblemException`.
For JSON requests or routes under the configured Vaulter prefix, the response is
`{message, code}` with the following HTTP status. HTML requests to host routes and
configuration/infrastructure exceptions use Laravel's normal exception handler.
Codes are a stable public contract (AD29).

| Code | HTTP | Exception |
|:--|:--|:--|
| `permission_denied` | 403 | `Vaulter\Exceptions\Access\PermissionDeniedException` |
| `node_name_conflict` | 409 | `Vaulter\Exceptions\Nodes\NodeNameConflictException` |
| `node_move_cycle` | 409 | `Vaulter\Drive\Exceptions\Nodes\NodeCycleException` |
| `cross_drive_operation` | 409 | `Vaulter\Drive\Exceptions\Nodes\CrossDriveOperationException` |
| `invalid_destination` | 409 | `Vaulter\Exceptions\Nodes\InvalidDestinationException` |
| `node_not_trashed` | 409 | `Vaulter\Exceptions\Nodes\NodeNotTrashedException` |
| `node_trashed_by_ancestor` | 409 | `Vaulter\Exceptions\Nodes\NodeTrashedByAncestorException` |
| `restore_destination_required` | 409 | `Vaulter\Exceptions\Nodes\RestoreDestinationRequiredException` |
| `tree_traversal_incomplete` | 409 | `Vaulter\Exceptions\Nodes\TreeTraversalIncompleteException` |
| `drive_archived` | 409 | `Vaulter\Exceptions\Drive\DriveArchivedException` |
| `invalid_drive_state` | 409 | `Vaulter\Exceptions\Drive\InvalidDriveStateException` |
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
| `invalid_document_content` | 422 | `Vaulter\Documents\Exceptions\InvalidDocumentContentException` |
| `document_revision_not_found` | 404 | `Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException` |
| `comment_not_found` | 404 | `Vaulter\Documents\Exceptions\CommentNotFoundException` |
| `document_state_mismatch` | 412 | `Vaulter\Documents\Exceptions\StaleDocumentStateException` |
| `collaboration_epoch_mismatch` | 409 | `Vaulter\Documents\Exceptions\CollaborationEpochMismatchException` |
| `collaboration_seed_conflict` | 409 | `Vaulter\Documents\Exceptions\CollaborationSeedConflictException` |
| `collaboration_unavailable` | 409 | `Vaulter\Documents\Exceptions\CollaborationUnavailableException` |
| `document_state_too_large` | 413 | `Vaulter\Documents\Exceptions\DocumentStateTooLargeException` |
| `unknown_export_format` | 422 | `Vaulter\Documents\Exceptions\UnknownExportFormatException` |
| `invalid_document_content` | 422 | `Vaulter\Documents\Exceptions\UnsupportedDocumentContentException` |
| `invalid_node_name` | 422 | `Vaulter\Exceptions\Nodes\InvalidNodeNameException` |
| `invalid_properties` | 422 | `Vaulter\Exceptions\Nodes\InvalidPropertiesException` |
| `folder_children_limit_exceeded` | 409 | `Vaulter\Exceptions\Policy\FolderChildrenLimitExceededException` |
| `tree_depth_limit_exceeded` | 409 | `Vaulter\Exceptions\Policy\TreeDepthLimitExceededException` |
| `file_size_limit_exceeded` | 413 | `Vaulter\Exceptions\Policy\FileSizeLimitExceededException` |
| `cannot_delete_head_revision` | 409 | `Vaulter\Exceptions\Drive\CannotDeleteHeadRevisionException` |
| `revision_pinned` | 409 | `Vaulter\Exceptions\Drive\RevisionPinnedException` |
| `validation_failed` | 422 | `Vaulter\Exceptions\Webhooks\BlockedOutboundUrlException` |
| `webhook_delivery_not_redeliverable` | 409 | `Vaulter\Exceptions\Webhooks\WebhookDeliveryNotRedeliverableException` |
| `invalid_webhook_signature` | 400 | `Vaulter\Exceptions\Webhooks\InvalidWebhookSignatureException` |
| `validation_failed` | 422 | `Vaulter\Exceptions\Access\UnenumerableGrantSubjectException` |
| `unknown_profile` | 422 | `Vaulter\Exceptions\Profiles\UnknownProfileException` |
| `concurrent_update` | 409 | `Vaulter\Exceptions\Database\ConcurrentUpdateException` |
| `write_outside_operation` | 500 | `Vaulter\Exceptions\Database\WriteOutsideOperationException` |
| `node_not_found` | 404 | `Vaulter\Exceptions\Nodes\NodeNotFoundException` |
| `drive_not_found` | 404 | `Vaulter\Exceptions\Nodes\StorageNotFoundException` |

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

## share-link-ttl-exceeded

`ShareLinkTtlExceededException` (422 `share_link_ttl_exceeded`, extra `limit_days`) — the link
would stay valid longer than the drive profile's `maxShareLinkTtlDays`. The limit applies when
a link is created or changed and again on every visit, so tightening it also ends older links
until their owner shortens them.

## unknown-document-type

`UnknownDocumentTypeException` — no registered [document type](./document-types.md) has the
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

## registry-frozen

`RegistryFrozenException` (`registry_frozen`) — something registered a document type after the
application booted. Register types from a service provider (config, a container tag, or
`register()` before the application has booted).

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

## unknown-collaboration-driver

`VaulterException` from `CollaborationDriverManager` — `config('vaulter-documents.collaboration.driver')`
is not one of the registered `CollaborationDriverType` cases (`hocuspocus`). Set it
to a valid transport name. The removed `reverb` driver is an `InvalidConfigurationException`
that says so.

## missing-jwt

The Hocuspocus driver needs `firebase/php-jwt` to mint room tokens. Run
`composer require firebase/php-jwt`.

## missing-collab-secret

The Hocuspocus driver needs `VAULTER_COLLAB_SECRET`. Set the shared secret in your
environment.

## collab-webhook-unconfigured

The hub endpoints (`collab/authenticate`, `collab/load`, `collab/store`) need both
`VAULTER_COLLAB_SECRET` (the room JWT key) and `VAULTER_COLLAB_WEBHOOK_SECRET` (the body
signature key); with either missing every hub call is `401`. Set both, different from each
other, on Laravel, and the webhook secret on the hub too.

## cross-storage-move

`CrossDriveOperationException` — a move stays within one storage tree. Copy across storages,
or re-home the node into the destination storage explicitly instead of moving it.

## comment-not-found

`CommentNotFoundException` — the comment does not exist or belongs to a different node (a reply's
`parent_id` too). List the node's comments and use an id from that list.

## document-version-not-found

`DocumentRevisionNotFoundException` — the version does not exist or belongs to a
different node. List the node's history (`GET .../document-versions`) and restore an id
from it.

## missing-host-role-driver

`MissingPermissionDriverException` — either the open `null` host-role driver was selected in
production (forbidden — it grants every actor full access), or `config('vaulter.access.permission_driver')`
names a driver key not in the `access.permission_drivers` map. Set a real driver
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
`v_storages.quota_bytes`, free space, or set `quota_bytes` to `null` for unlimited.

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
`vaulter.webhooks.delivery.max_response_bytes`. Have the receiver return a small (or
empty) body, or raise the limit.

## webhook-ssrf

`BlockedOutboundUrlException` — the outbound webhook URL was refused: a disallowed
scheme, a malformed URL, or a host resolving to a blocked network (SSRF protection).
Register the endpoint with a public http(s) URL.

## webhook-delivery-not-redeliverable

`WebhookDeliveryNotRedeliverableException` — a manual redelivery was asked for a delivery that is
still `pending` or `failed` (its retries are not finished) or whose endpoint is inactive. Wait
for the retries to end or for the delivery to die, and re-enable the endpoint first.

## webhook-signature

`InvalidWebhookSignatureException` — `StandardWebhookVerifier` refused a received webhook: a
header is missing, `webhook-timestamp` is outside the tolerance, or no signature matches the raw
body and the secrets. Verify the unmodified request body with the endpoint's `whsec_` secret (both
secrets during a rotation) and check the receiver's clock. See
[Webhooks → Verifying a delivery](/guide/webhooks#verifying-a-delivery).

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

## collaboration-epoch-mismatch

`collaboration_epoch_mismatch` (409) — a collaboration write came from a room of an older
generation: the document was restored or its JSON replaced since the room opened. The write is
refused so the abandoned history is not merged back; reopen the document in a new room. The hub
answers it by closing the room with close code 4409 `epoch_changed` and never retries the store.

## collaboration-seed-conflict

`collaboration_seed_conflict` (409) — a hub tried to seed the collaboration state of a document
that already has one (another hub instance seeded it first). The second seed is refused: both
would merge into duplicated content. The hub loads the stored state instead.

## collaboration-unavailable

`collaboration_unavailable` (409) — `collaborationSession()` or a hub call for a document that
cannot be edited collaboratively: `vaulter-documents.collaboration.enabled` is off
(`context.reason = disabled`), or the document's type does not implement
`SupportsCollaboration` (`unsupported_type`). Edit it through `DocumentOperations::update()`.

## document-state-too-large

`document_state_too_large` (413) — a hub store carried a binary Yjs state above
`vaulter-documents.collaboration.max_state_bytes` (default 16 MiB). Nothing was written. Raise the
limit or split the document.

## write-outside-operation

`write_outside_operation` (500, an integration error) — code outside the Vaulter operations saved,
updated or deleted a Vaulter model (`Node::query()->update(...)`, `$node->save()`). Rows change only
through the `DriveHandle` operations, which keep the invariants, the authorization and the audit
trail; the model guard (`vaulter.database.write_guard`, default `true`) refuses the write before it
reaches the database. Move the write to an operation. `DB::table()` and raw SQL are not intercepted:
the guard is defence in depth, not a sandbox.
