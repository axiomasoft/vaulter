# Error Reference

Every Vaulter exception extends `Vaulter\Exceptions\VaulterException` and carries a
message in the form `[Vaulter] {what}. Why: {…}. Fix: {…}. Docs: {url}` where the URL
points at the matching anchor below.

## permission-denied

`PermissionDeniedException` — either the legacy document path's authorization hook, or
the Drive ACL (`AccessResolver` + `HostRoleDriver`), denied the attempted action. Grant
the required level/scope on the node or an ancestor. The API maps this to `403`.

## unknown-document-type

`UnknownDocumentTypeException` — the supplied `type` is not in
`config('vaulter.enabled_document_types')`. Enable it, or register a custom type via
`Vaulter::registerDocumentType()`.

## invalid-tiptap-content

`InvalidTipTapContentException::missingDocNode()` — the content root must be
`{"type":"doc", …}`. Pass a valid TipTap JSON document or use `TipTapContent::empty()`.

## content-too-large

`InvalidTipTapContentException::tooLarge()` — the content exceeds
`config('vaulter.max_document_size_kb')`. Split the document or raise the limit.

## unknown-collaboration-driver

`VaulterException` from `NodeCollaborationDriverManager` — `config('vaulter.collaboration.driver')`
is not one of the registered `CollaborationDriverType` cases (`reverb`, `hocuspocus`). Set it
to a valid transport name.

## missing-jwt

The Hocuspocus driver needs `firebase/php-jwt` to mint room tokens. Run
`composer require firebase/php-jwt`.

## missing-collab-secret

The Hocuspocus driver needs `VAULTER_COLLAB_SECRET`. Set the shared secret in your
environment.

## collab-webhook-unconfigured

`CollaborationConfigException` — neither `VAULTER_COLLAB_WEBHOOK_SECRET` (shared secret) nor
`VAULTER_COLLAB_SECRET` (JWT key) is configured, so the `collab/authenticate` +
`collab/store` server-to-server webhooks cannot authenticate the Node hub. Set at least one.

## cross-storage-move

`CrossStorageMoveException` — a move stays within one storage tree. Copy across storages,
or re-home the node into the destination storage explicitly instead of moving it.

## document-version-not-found

`NodeDocumentVersionNotFoundException` — the version does not exist or belongs to a
different node. List the node's history (`GET .../document-versions`) and restore an id
from it.

## missing-host-role-driver

`MissingHostRoleDriverException` — either the open `null` host-role driver was selected in
production (forbidden — it grants every actor full access), or `config('vaulter.drivers.permission')`
names a driver key not in the `permission_drivers` map. Set a real driver
(`database`/`gate`/`spatie`/`azgard`) or bind your own `HostRoleDriver`.

## missing-package

`MissingPackageException` — a contract's concrete implementation ships in a package that
is not installed (e.g. `NodeHandlerResolver`/`HandlerRegistry` need vaulter-documents).
Run the printed `composer require`.

## move-cycle

`CycleException` — the move destination is the node itself or one of its own
descendants, which would create a cycle. Choose a destination outside the moved node's
subtree.

## node-name-conflict

`NodeNameConflictException` — a node with that name already exists under the same parent
(or storage root). Pick a different name, or move/trash the existing sibling first.

## not-a-document-node

`NotADocumentNodeException` — document content/version operations require a
`kind=document` node (its payload lives in `v_node_document_versions`). Create the
document via the document-node create endpoint first, or target a document node.

## quota-exceeded

`QuotaExceededException` — the upload would exceed the storage's `quota_bytes`. Raise
`v_storages.quota_bytes`, free space, or set `quota_bytes` to `null` for unlimited.

## unenumerable-grant-subject

`UnenumerableGrantSubjectException` — the configured `HostRoleDriver` does not enumerate
role/group/team subjects, so a role/team grant would never take effect. Use a driver that
enumerates role subjects (`spatie`), or override `subjectsFor()` on your own driver to
append the role/team `Subject`s.

## unknown-export-format

`UnknownExportFormatException` — no exporter is registered for the requested format.
Register one via `config('vaulter.exporters')` or `Vaulter::registerExporter()`.

## unsupported-document-content

`UnsupportedDocumentContentException` — an exporter received a `DocumentContent` shape it
doesn't understand. `DocumentExporter::export()` takes the opaque `DocumentContent`, so an
exporter that only renders one shape (the built-in HTML/Markdown exporters render
`TipTapContent`) narrows with an `instanceof` and throws this when the narrowing fails.
Throw it from your own exporter's guard; catch it if you call an exporter directly.

Note: the package's own node-export path (`NodeExportService`) does not currently produce
a non-TipTap `DocumentContent` — it resolves every version through `TipTapContent`, so a
non-TipTap snapshot decays to an empty TipTap doc rather than reaching this guard. This
error therefore surfaces on **direct exporter calls and custom exporters** today.

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
