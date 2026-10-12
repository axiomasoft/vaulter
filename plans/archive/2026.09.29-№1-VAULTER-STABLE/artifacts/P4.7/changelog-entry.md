# CHANGELOG [Unreleased] entry (applied to CHANGELOG.md in this item)

### Document validators: writer matrix and event payloads (PLAN2.D35; PLAN2.P4.7)

- Changed `DocumentCreated`, `DocumentContentUpdated` and `DocumentRevisionCreated` to carry
  `int $stateVersion, int $epoch` (the document's content validators after the write, from the
  writer's result); `DocumentRestored` already did.
- Added `docs/reference/concurrency.md` (en, ru): the node and content validators, the writer
  matrix, the representation ETag formats for REST and the 412/409 codes.
- Added the executable writer matrix (`DocumentValidatorMatrixTest`) and PostgreSQL race scenarios:
  two updates on one `expectedStateVersion` (one wins, the other is `412 document_state_mismatch`
  without writes), a rename beside a parallel content write, two renames on one `expectedSequence`
  (`412 node_sequence_mismatch`), and an update whose writability precheck went stale to a restore of a
  newer-schema or lossy revision (`DegradedContentRaceTest`, order forced).
- Fixed: `content_hash` no longer depends on the order of object keys, so a document copy and a
  restore keep the hash of their source on PostgreSQL.
- Added regression coverage for writability on the locked row: a content write without an
  expectation is refused (`409 document_content_degraded`) when a restore of a newer-schema revision
  committed between the pre-check and the lock, and then changes nothing.

