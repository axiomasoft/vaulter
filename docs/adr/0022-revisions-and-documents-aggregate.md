# ADR-0022 — Revisions & documents aggregate

**Status:** proposed

> Transfers decisions D15–D17 of the 2026-09-29 stable audit
> (`audits/2026-09-29-stable-audit/opus/02-decisions.md#d15` … `#d17`) into the project ADR set.
> **Amends [ADR-0018 — Pluggable editor-engine canon](/adr/0018-editor-engine-canon):** structured
> documents get their type, schema version and viewer from a document-type registry and a `Document`
> aggregate instead of the node row. The `FileHandler` contract, its naming (ADR-0018 D7) and the
> `yjs_state` column (ADR-0018 D11) stay in force.

## Context

- `nodes.current_version_id` is polymorphic without a discriminator: for files it points to file
  versions, for documents to document versions, while `Node::currentVersion()` always builds a
  `BelongsTo(FileVersion)`. With bigint ids a document resolves to a foreign file version with the
  same id; with ULIDs to `null` (audit finding N03).
- `CopyNodeAction` copies the payload only for files and recurses only for folders; a copied
  document is an empty node without revisions or a head pointer. There is no "node content by kind"
  mechanism for copy/purge/size (N06).
- Document types are registered through the manager (`Vaulter::registerDocumentType()`,
  `documentTypes()`, `enabled_document_types`) without a dedicated contract in `vaulter-documents`.

## Decision

**1. Document-type registry (D15).** In `vaulter-documents`:

- contract `Vaulter\Documents\Contracts\DocumentType` (key, label, `schemaVersion()`, `codec()`,
  `defaultContent()`, `viewer()`), optional interfaces `SupportsCollaboration`, `UpgradesSchema`,
  `ProvidesTemplates` (later, additive);
- `DocumentTypeRegistry`: registration of FQCNs from config and from providers, keys
  `^[a-z0-9-]+/[a-z0-9-]+$`, a duplicate → `DuplicateDocumentTypeException` (replacement only via
  `replace()`), frozen after the application is `booted`;
- built-in types: `vaulter/rich-text` (TipTap, Yjs), `vaulter/markdown` (TipTap with a restricted
  schema), `vaulter/spreadsheet` (Univer, behind the `spreadsheets` feature, no collaboration);
  `table` and `template` are removed (their data migrates to `vaulter/rich-text`);
- an unknown type (extension removed): the document stays in the tree, its content is available as
  opaque JSON for reading and download; edit/export/collab → `DocumentTypeUnavailableException`
  (409);
- `Vaulter::registerDocumentType()`, `documentTypes()` and `enabled_document_types` are removed.

**2. The `Document` aggregate mirrors `File` (D16).** A new table `v_documents` (1:1 to the node):
`type`, `schema_version`, `current_revision_id`, `revision_count`, `collab_epoch`. The revision head
is stored in the aggregate (`v_files.current_revision_id`, `v_documents.current_revision_id`);
`current_version_id` and `file_id` are dropped from `v_nodes` (N03). The node→aggregate link is
`node_id UNIQUE` in the aggregate.

```
Node ─┬─ File ── FileRevision ── FileRevisionBlob ── Blob
      └─ Document ── DocumentRevision
```

**3. Node content by kind: `NodeContentLifecycle` (D17).** A contract in core:

```php
interface NodeContentLifecycle
{
    public function kind(): NodeKind;
    public function copy(OperationContext $context, Node $source, Node $copy): CopyResult; // bytes added
    public function purge(OperationContext $context, Node $node): void;                   // before the node is deleted
}
```

Drive registers `FileContentLifecycle`, documents registers `DocumentContentLifecycle`, folders are
handled by recursion in `CopyNodeAction`. `CopyNodeAction`/`PurgeNodeAction` dispatch by kind; a
kind without a registered lifecycle → `UnsupportedNodeKindException` (N06).

## Consequences

- Every revision head pointer is typed by its aggregate; the polymorphic node pointer and the
  wrong-revision resolution it caused are gone.
- Copying or purging a document carries its content and revisions; core stays free of document
  internals and delegates through the lifecycle seam.
- Document types are a module-owned extension point with stable `vendor/name` keys; removing an
  extension degrades documents to read-only opaque content instead of breaking the tree.
- Revision terminology replaces "version" for content snapshots (`current_revision_id`,
  `FileRevision`, `DocumentRevision`).

## Amendment — working state, immutable checkpoints, separate validators (2026-10-07)

Accepted as PLAN2.D34 and PLAN2.D35; implemented in PLAN2.P4.1.

**Working state + immutable checkpoints (PLAN2.D34).** `v_documents` holds the document's
working state (`content`, `content_hash`, `content_size`, `yjs_state`, `yjs_state_size`,
`schema_version`) next to its head pointer. `v_document_revisions` holds immutable checkpoints:
after INSERT only `keep_forever` changes. Autosave checkpoints are taken on an interval
(`vaulter-documents.revisions.checkpoint_interval_seconds`, default 300 s), named ones by
`snapshot()`, and a restore copies a checkpoint forward with `restored_from_revision_id` (also
added to `v_file_revisions`). The head of either aggregate is protected by a composite foreign
key `(current_revision_id, id) → revisions(id, file_id | document_id) ON DELETE RESTRICT` on
PostgreSQL/MySQL; SQLite relies on the Actions. `Vaulter\Documents\Content\DocumentStateWriter`
is the only writer of the working state and its checkpoints.

**Separate validators (PLAN2.D35).** `v_nodes.sequence` validates the node (name, parent, trash
state, file head); the content of a document is validated by `v_documents.state_version` and
`collab_epoch` (signed BIGINT, the `sequence` overflow contract). Every effective write of the
working state moves `state_version` by one and never touches `v_nodes.sequence`; restore and an
external JSON replace of a collaborative document also move `collab_epoch`. A stale content
expectation fails with `412 document_state_mismatch`.

Upgrade: the 0.2 version table becomes the checkpoints with unchanged ids; the head's payload
becomes the working state (`2026_10_01_000005_documents_aggregate`), the node pointers and
tombstone go (`2026_10_07_000001_drop_node_legacy_pointers`), and file heads get provenance and
their foreign key (`2026_10_07_000002_revision_provenance_and_heads`).

## Alternatives rejected

The source decisions D15–D17 record no rejected alternatives.

## References

- `audits/2026-09-29-stable-audit/opus/02-decisions.md#d15`
- `audits/2026-09-29-stable-audit/opus/02-decisions.md#d16`
- `audits/2026-09-29-stable-audit/opus/02-decisions.md#d17`
- `audits/2026-09-29-stable-audit/opus/06-extension-points.md` §3
- `audits/2026-09-29-stable-audit/opus/01-review.md` — N03, N06
- [ADR-0018 — Pluggable editor-engine canon](/adr/0018-editor-engine-canon) (amended)
- `plans/2026.09.29-№1-VAULTER-STABLE/decisions/D34.json`, `D35.json` — the amendment
