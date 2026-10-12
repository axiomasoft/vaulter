# P4.1 — CHANGELOG [Unreleased] entry

Applied verbatim to `CHANGELOG.md` in the same commit (owner M3, 2026-10-07: CHANGELOG open to agents).

### Document aggregate with working state and immutable checkpoints (AD16; PLAN2.D34, PLAN2.D35, PLAN2.P4.1)

- Added `v_documents` (one aggregate per document node: working state, head, `state_version`,
  `collab_epoch`) and `v_document_revisions` (immutable checkpoints); the 0.2
  `v_node_document_versions` rows migrate with their ids. Composite head foreign keys protect
  file and document heads on PostgreSQL/MySQL.
- Removed `v_nodes.current_version_id`, `file_id` and `deleted_at`, `Node::currentVersion()` and
  `SoftDeletes` on `Node`; `File::currentVersion()` → `File::currentRevision()`. Was: one
  polymorphic head on the node that a same-id file revision could shadow. Now: heads live on
  their own aggregate.
- Changed document saves: `DocumentStateWriter` writes the working state; autosave checkpoints
  follow `vaulter-documents.revisions.checkpoint_interval_seconds` (300 s). Was: the head
  autosave row was rewritten in place within `autosave_debounce_seconds` (now deprecated and
  ignored). Stored checkpoints only change `keep_forever`.
- Changed validators: document content writes move `state_version` (412
  `document_state_mismatch`, `expectedStateVersion`) and never `v_nodes.sequence`. Was: every
  document save bumped the node sequence.
- Added `restored_from_revision_id` to file and document revisions; added
  `StaleDocumentStateException` and `CollaborationEpochMismatchException` (409
  `collaboration_epoch_mismatch`).

