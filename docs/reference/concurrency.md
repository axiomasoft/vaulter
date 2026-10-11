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
| collaboration seed | 0 | +1 | 0 | `expectedEpoch`, `yjs_state IS NULL` and the `state_version` the hub read in `collaboration/hub/load` (`409 collaboration_seed_conflict`) |
| collaboration store | 0 | +1 when the state changed | 0 | `expectedEpoch` (`409`) |
| restore a document revision | 0 | +1 | +1 | `expectedStateVersion` |
| snapshot (named checkpoint) | 0 | 0 | 0 | — |
| pin a document revision, export | 0 | 0 | 0 | — |
| purge | the node and its aggregate disappear | | | — |

Document events carry `stateVersion` and `epoch` after the write, from the writer's result, so a
consumer can order content events without reading the row again.

A JSON update of a document **without** Yjs state moves `state_version` but not the epoch (there
is no room to make stale). That is why a seed cannot rely on the epoch alone: the hub reads the
JSON in `collaboration/hub/load` and seeds it later, and an update in between would be overwritten by a
seed built from the old text. The seed therefore carries the loaded `state_version` and the
write compares it under the document lock; a moved version is `409 collaboration_seed_conflict`
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
  the epoch alone, and a store of a past generation is answered with `409`.

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
