# Concurrency

Vaulter protects a node and the content of a document with **separate validators**. A writer
reads a validator together with the data, sends it back as an expectation and is refused if
something else committed in between. Nothing is retried for you: a refusal means "reload and
decide again".

## Validators

| Validator | Where | Moves | Expectation | Refusal |
|---|---|---|---|---|
| Node sequence | `v_nodes.sequence` | every change of the node itself | `expectedSequence` | `412 node_sequence_mismatch` (`StaleNodeException`) |
| State version | `v_documents.state_version` | every effective write of a document's working state | `expectedStateVersion` | `412 document_state_mismatch` (`StaleDocumentStateException`) |
| Collaboration epoch | `v_documents.collab_epoch` | a write that makes open rooms stale | `expectedEpoch` (the hub, from its token) | `409 collaboration_epoch_mismatch` |

A node edit and a content edit never move each other's validator: renaming a document does not
fail a save, and saving does not fail a rename.

## Writer matrix

`Δ` is the change one successful call makes. "—" means the call does not write.

| Operation | `sequence` | `state_version` | `collab_epoch` | Precondition |
|---|---|---|---|---|
| rename, move, trash, restore from trash, `setProperties` | +1 | — | — | `expectedSequence` |
| upload of a file revision, restore of a file revision | +1 | — | — | `expectedSequence` |
| delete or pin a file revision | 0 | — | — | — (history, not the head) |
| star, unstar, `restrictInheritance` | 0 | 0 | 0 | — (per-user or access state, not the node) |
| create a document | 0 | 1 | 0 | — |
| copy a document | source 0; copy 0 | source 0; copy 1 | source 0; copy 0 | — |
| update the content (JSON) | 0 | +1 | +1 if the document has Yjs state | `expectedStateVersion` |
| update with identical content | 0 | 0 | 0 | checked first, so a stale expectation is still refused |
| collaboration seed (the first log record) | 0 | 0 | 0 | the generation has no record and no checkpoint, and the `state_version` the hub read in `collaboration/hub/load` is still current (`409 collaboration_seed_conflict`) |
| collaboration append | 0 | 0 | 0 | the generation is current (`409 collaboration_epoch_mismatch`); sets the pending marker, no state version moves |
| collaboration checkpoint | 0 | +1 when the state changed | 0 | the generation, `expected_checkpoint_version` (the state version) and the covered range (`409 collaboration_checkpoint_conflict`) |
| restore a document revision | 0 | +1 | +1 | `expectedStateVersion` |
| snapshot (named checkpoint) | 0 | 0 | 0 | — |
| pin a document revision, export | 0 | 0 | 0 | — |
| purge | the node and its aggregate disappear | | | — |

Document events carry `stateVersion` and `epoch` after the write, from the writer's result, so a
consumer can order content events without reading the row again.

A JSON update of a document **without** Yjs state moves `state_version` but not the epoch (there
is no room to make stale). That is why a seed cannot rely on the epoch alone: the hub reads the
JSON in `collaboration/hub/load` and seeds it later, and an update in between would be overwritten by a
seed built from the old text. The seed (appended as the first record of the log) therefore carries the loaded
`state_version` as `seed_state_version` and the write compares it under the document lock; a moved version is `409 collaboration_seed_conflict`
(`context.expected_state_version`, `actual_state_version`), after which the hub loads again and
seeds from the current JSON.

## Representation validators (REST)

REST v1 exposes these as strong ETags (see the [REST reference](/reference/rest-api#validators-and-preconditions)).

- Node: `"{id}.{sequence}.{digest}"` — a validator of the representation **the caller**
  receives. The digest is the first 16 hex characters of a SHA-256 over that caller's node
  JSON without its `etag` (keys sorted at every depth), so it moves with anything the caller
  sees change: abilities after a grant, `viewer.starred`, the head's scan state, the
  presentation, properties, the content head — not only with `sequence`. Clients treat it as
  opaque.
- Document content: `"{documentId}.{epoch}.{stateVersion}"`; its body depends on no caller,
  so the generation is enough. A mismatch is `412 document_state_mismatch`.
- A CRDT room has no `If-Match`. Merging concurrent edits is the room's job; the hub is fenced by
  the epoch alone, and an append of a past generation is answered with `409`.

A conditional route runs in this order: authenticate → resolve the drive/node (tenant-scoped)
→ find the node as the caller (no `view` → `404`; no ability for the mutation → `403`) → build
the caller's current representation → compare `If-Match` → call the operation with that representation's `sequence` as the
expectation. No `If-Match`, or `If-Match: *`, is `428 precondition_required`; a weak or stale
tag is `412 node_sequence_mismatch` (`meta.node_id`, never the current tag). The comparison is
not the lost-update guard by itself: the operation checks the expected sequence again on the
locked row inside its transaction, so a write that slipped in between still ends in the same
`412`. Purge, restore and restore-revision take the expectation through the PHP API as well
(`TrashOperations::purge($node, $expectedSequence)`, `RestoreOptions::$expectedSequence`,
`FileOperations::restoreRevision($file, $revision, $expectedSequence)`) and check it before
removing rows or bytes, moving a node or reserving quota; the parameter stays optional in PHP.

## Checks on the locked row

Preconditions are compared with the row read **under the document lock**, never with the model the
caller passed in. The same holds for "may this state be overwritten": a write that carries no
expectation is still refused with `409 document_content_degraded` when a restore of a newer-schema
or degraded revision committed between the caller's pre-check and the lock. The refused write
changes no content, Yjs state, schema version or checkpoint.

## Aggregate versions (the registry)

Every aggregate that an `if_match` operation changes has its own version column, so a compare-and-set
never hides behind the sequence of another row. The write is
`UPDATE … SET version = version + 1 WHERE id = ? AND version = ?` on the aggregate locked in the
transaction of the operation; zero affected rows is the refusal. The policy of each operation is in the
[operation catalogue](/reference/operations) ([ADR-0032](/adr/0032-operation-catalog-and-concurrency)).

| Aggregate | Version | Representation tag (`etag`) | Refusal | Operations |
|---|---|---|---|---|
| `drive.node` | `v_nodes.sequence` | node ETag (above) | `412 node_sequence_mismatch` | rename, move, trash, restore, purge, properties, revisions |
| `drive.node_access` | `v_nodes.access_version` (grants and inheritance of the node; `sequence` does not move) | `GET nodes/{node}/grants`: node id + `access_version` | `412 stale_write` | revoke, restrict and restore inheritance |
| `drive.share` | `v_node_shares.version` | share id + `version` | `412 stale_write` | update, rotate, revoke |
| `drive.drive` | `v_drives.version` | `GET drives/{drive}`: id + `version` | `412 stale_write` | archive, unarchive, delete |
| `documents.document` | `v_documents.state_version` | `{documentId}.{epoch}.{stateVersion}` | `412 document_state_mismatch` | content update, restore |
| `documents.comment` | `v_node_comments.version` | comment id + `version` | `412 stale_write` | resolve, delete |
| `webhooks.endpoint` | `v_webhook_endpoints.version` | endpoint id + `version` | `412 stale_write` | update, deactivate, rotate secret, delete |

A resource body carries the same value in `etag`, and the `ETag` header repeats it. Creating a row
(grant, share, comment, endpoint, folder, upload) is `append`: it takes no `If-Match`.
`natural`, `state_machine`, `generation` and `row_identity` operations have no `If-Match` either;
their repeat is made safe by the state itself.

## Codes

| Situation | Answer |
|---|---|
| `If-Match` missing or `*` on an `if_match` route | `428 precondition_required` (not stored for idempotency) |
| the tag is stale or foreign | `412` — `node_sequence_mismatch` and `document_state_mismatch` keep their codes; every other aggregate answers `stale_write` (retryable `after_refetch`, receipt `cacheable`) |
| the caller cannot see the resource | `404` before the precondition is read, so a tag never discloses a hidden resource |
| generation fence (collaboration) | `409 collaboration_*` |

`stale_write` names the `aggregate` and the `id` of the resource in its details and never the current tag; the client reloads
the resource and decides again. Because the refusal is `cacheable`, a retry under the same
`Idempotency-Key` repeats it instead of racing a concurrent change. See the [error codes](/reference/errors).

## Partial failures

Each row of the audit §21.3 names the state after a fault, who recovers it and the test that injects the fault. `fast` rows run on
sqlite in one process and inject the failure once, at one stage, so the next attempt really runs. A single-process fixture does not
prove an engine, a process kill or a Redis race: those rows are `pending` and belong to the owner runbook (`serial` + `database` / `stand`).

| Scenario | State after the fault | Recovery | Test (`file::test`, line) | Status |
|---|---|---|---|---|
| Commit to the database succeeds, the delivery stage (consumer) throws | event and delivery row stay durable, `pending`, `attempts = 1`, `last_error` set | the outbox relay delivers on a later pass | `tests/Integration/Reliability/PartialFailureTest.php::keeps a committed event durable when its delivery stage throws once, and delivers it on the next pass` (51); `tests/Integration/Outbox/OutboxRelayTest.php::dead-letters a dead result at once and retries a consumer that throws` (180) | fast |
| Blob stored, metadata transaction fails | no visible node and no live pin; the staged object stays reusable | orphan reclaim after the grace period | `packages/media/tests/Cas/BlobOrphanSweepTest.php::grace: the same object aged past --orphan-grace lands in the orphans` (169); `packages/media/tests/Cas/BlobCommitAtomicityTest.php::rollback leaves no dangling blob row` (29); `::commitStaged keeps the temp file reusable across an outer SQL rollback` (107); `packages/drive/tests/Concurrency/CompleteUploadRetryTest.php::SEC-R19-retry-exhausted maps the persistent failure to a retryable problem and keeps the staged bytes` (73) | fast |
| Metadata exists, the blob read fails | a failed read is not "missing": the mark and the row are kept, nothing live is removed | scrub / heal rewrites the object when the same bytes come again | `packages/media/tests/Cas/BlobScrubTest.php::reports an object that cannot be read as unverified and does not mark it` (310); `packages/media/tests/Cas/BlobScrubHealTest.php::keeps the mark when the rewrite fails` (169); `::keeps the mark when the driver reports a failed write and the object is not there` (190) | fast |
| Duplicate or reordered job | one consumption per event; one POST for overlapping jobs; a finished delivery stays finished | receipts and the delivery state make the repeat a no-op | `tests/Integration/Reliability/PartialFailureTest.php::consumes an event once when the relay pass is repeated after the delivery completed` (72); `packages/webhooks/tests/Webhooks/DeliverWebhookTest.php::sends one POST when a duplicate job overlaps the HTTP attempt` (243) | fast |
| Webhook accepted by the remote side, local timeout | delivery stays transient with a safe classification, a retry is armed | the retry reuses the same delivery, so the same `webhook-id` | `packages/webhooks/tests/Webhooks/DeliverWebhookTest.php::records a safe transport classification when the POST throws` (129); `::uses the configured retry schedule for next_retry_at` (158); `tests/Integration/Reliability/PartialFailureTest.php::retries an accepted-then-timed-out webhook delivery with the same webhook-id` (97) | fast |
| GC in parallel with a new revision | no fast proof | engine-level locking | none in this checkpoint | pending: `serial` + `database` (pgsql), owner runbook |
