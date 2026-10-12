VERDICT: FINDINGS

One major issue: a `412 document_state_mismatch` publishes the current `state_version`, and that value rebuilds an `If-Match` the server accepts. A second write then replaces the newer content without reading it.

## Major — `412` reveals the current content tag

`packages/documents/src/Http/Resources/DocumentResponses.php:59` and `packages/documents/src/Exceptions/StaleDocumentStateException.php:47`.

`PUT documents/{node}/content` and `POST …/revisions/{revision}/restore` both go through `expectedStateVersion()`. On a tag mismatch it throws `StaleDocumentStateException` with `actual` set to the document's current `state_version`. `problemDetails()` puts that integer in `meta.context.actual`.

P6-design#Validators says a mismatch is `document_state_mismatch` with `meta.node_id`, and that a `412` does not reveal the current ETag. The P6.8 deviation records parsing `expected` from the caller's tag. It does not record publishing the server's current version.

`DocumentStateWriter` (`packages/documents/src/Content/DocumentStateWriter.php:74-79`) moves `collab_epoch` only for a restore, or for a JSON update of a document that already has Yjs state. A normal REST update and a hub store (`StateWriteCause::Collaboration`) only increment `state_version`. The caller's stale tag still holds the epoch, so `"${nodeId}.${epoch}.${actual}"` is the current tag.

Reproduction (bigint ids in this suite; the tag grammar is the same for a ULID, which cannot contain a dot):

```text
stale="2.0.1"
current="2.0.2"
412 meta.context = {"expected":1,"actual":2}
rebuilt="2.0.2"
retry status=200 text="Clobber" etag="2.0.3"
stored="Clobber"
```

The first PUT correctly returned `412` and left "Second" in place. The retry used only the `412` body plus the caller's own stale tag, and replaced that content with "Clobber". The same rebuild works after a hub store: that write does not move the epoch either.

`meta.context.actual` has to stay out of the problem body. `StaleNodeException` already publishes only `node_id`.

## Minor — archived drive answers `428` before `409`

`packages/documents/src/Http/Resources/DocumentResponses.php:48-60`, then `packages/documents/src/Http/Controllers/Documents/UpdateDocumentContentController.php:31-33`.

`expectedStateVersion()` treats an Edit flag from `abilitiesMany()` as permission to compare `If-Match`. Those flags do not know that an archived drive refuses mutations. `DriveArchivedException` is thrown later, inside `update()` / `restore()`, and only if `If-Match` already matched.

An editor on an archived drive, PUT with no `If-Match`:

```text
archived missing=428 precondition_required
archived fresh=409 drive_archived
stored="Draft"
```

The write does not happen. The missing-header response is `428` rather than `409 drive_archived`. A stale tag takes the same path and becomes `412` (with `actual`) before the lifecycle check.

## What held

Documents acceptance suite: 565 passed, 1 skipped, exit 0. The drive file-download failure and three core download `404`s came from pointing those suites at the documents Testbench skeleton; each failed test passed on that package's own skeleton. The two `EventTypeTest` failures are the ones already recorded for P6.8.

Checked and sound: previewer `403` before `428`; invisible and unknown PUT bodies match apart from `request_id`; `304` carries ETag, `private, no-cache`, `X-Vaulter-Api-Version`, `X-Request-Id`, and an empty body; hub routes stay off the authenticated stack, an unsigned call is `401 actor_required`, a stale epoch is `409`, a read-only token cannot store, and with collaboration off the v1 hub routes are absent (`404 route_not_found`). Export uses the exporter's fixed mime (`text/html`, `text/markdown`) with attachment, `nosniff`, CSP sandbox, and `no-store`; HTML still goes through the sanitizer. `VaulterHttpOperationMap::resolve()` still labels `v1/documents/...` for the load collectors and returns null for the old `nodes/.../content` paths; `VaulterThrottledRouteMap::resolve(Request)` is name-only and still labels `vaulter.v1.collaboration.hub.store`.