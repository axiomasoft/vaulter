# Read-only re-review — PLAN2.P6.8, round 2 (local, changed closure only)

You are an independent, read-only reviewer; do not modify files. Round 1 findings (artifacts/P6.8/review-round1.md):
(1) major: a 412 `document_state_mismatch` published `meta.context.actual` (the current state version) so a stale
caller could rebuild the current ETag; (2) minor: an archived drive answered 428/412 before 409 `drive_archived`.

Fix under review (working tree vs HEAD a3febca3), only these files:
- `packages/documents/src/Exceptions/StaleDocumentStateException.php` (problemDetails() now only `node_id`; new optional
  `nodeId` ctor argument), `packages/documents/src/Content/DocumentStateWriter.php` (passes the node id),
- `packages/documents/src/Http/Resources/DocumentResponses.php` (archived-drive check before the precondition; the
  412 built without any caller-derived or server version in its public body),
- tests `packages/documents/tests/Http/DocumentContentV1Test.php` (last two tests), `tests/Document/DocumentNodeApiTest.php`.

Check: (a) no path left where a 412/409/428 body or header reveals the current state_version/ETag to a caller that has
not read the content (also the CAS-race 412 thrown from `DocumentStateWriter`, and `HubStoreController` bodies);
(b) the archived check does not break reads, or the hub's read-only archived behaviour; (c) nothing else regressed
(`DocumentStateWriter` other callers: `CollaborationHub`, actions). Reproduce every finding with a test or command
(`env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci <paths>` from
`packages/documents`). Finish with `VERDICT: GREEN` or `VERDICT: FINDINGS`.
