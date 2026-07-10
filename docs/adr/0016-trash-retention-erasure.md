# ADR-0016 — Trash retention & purge erasure

**Status:** accepted

## Context

The Drive trash is a three-stage lifecycle, but only the FIRST stage was ever
implemented as advertised (VLT-REMED P2.2 / F-P1.12-01/02/03):

1. **Trash** (`trashed_at`, a Drive column distinct from Laravel's SoftDeletes
   `deleted_at`) — a reversible hide: the node vanishes from listings and the
   share channel (`ShareResolver::safeTargetNode`), but the identity
   download/export controllers never consulted `trashed_at`, so a node "in the
   trash" stayed fully downloadable/exportable to any actor with a live grant
   for the whole retention window (F-P1.12-01).
2. **Force-delete** (`ForceDeleteNodeAction` → `deleted_at` tombstone) — detaches
   every version↔blob edge (making the blob bytes GC-eligible) and stamps
   `deleted_at`, but a `SoftDeletes` `->delete()` never physically removes a row,
   so FK `cascadeOnDelete` on dependent tables never fires.
3. **Purge** (`vaulter:purge-trash`, retention-gated) previously called the SAME
   stage-2 action, so "permanently delete" never physically deleted anything:
   inline document content (`v_node_document_versions.content`/`yjs_state`) and
   file-version metadata lived in the database forever, defeating the erasure
   expectation retention policy implies (GDPR-style "right to erasure",
   F-P1.12-02).

Separately, `keep_forever` (a per-version pin excluding it from
`NodeDocumentVersionStoreRepository::prune()`) had a setter (`storeVersion`) but
no way to unset it — a pinned version was excluded from prune FOREVER, not just
until released (F-P1.12-03).

## Decision

- **Item-safety trash-check, orthogonal to the ACL.** `trashed_at !== null` 404s
  on the identity read/download/version-download/export controllers
  (`Vaulter\Http\Support\TrashGuard`, mirroring `ShareResolver::safeTargetNode`)
  BEFORE the `AccessResolver` ability check runs. This stays a controller-level
  item-safety gate, not a new `AccessResolver` input — H.2's ACL algorithm is
  unchanged; trash is a lifecycle concern, not a grant concern. Route-model
  binding still resolves a trashed node (restore/force-delete need it); the
  guard only fronts the read/download/export surfaces.
- **Stage 2 (force-delete) stays a soft-delete tombstone.** `ForceDeleteNodeAction`
  is unchanged: it detaches blob edges and stamps `deleted_at`. This is the
  "gone from every live surface, still forensically recoverable by an operator
  with DB access" stage — the deliberate second chance before irreversible
  purge.
- **Stage 3 (purge) is now a REAL physical delete.** `vaulter:purge-trash`, per
  retention-expired subtree and inside ONE transaction: (a) runs
  `ForceDeleteNodeAction` (stage 2, unchanged), then (b)
  `NodeStoreRepository::purgeSubtree()` — `Node::withTrashed()->forceDelete()`
  bypassing the SoftDeletes scope, issuing a genuine `DELETE` so FK
  `cascadeOnDelete` on every dependent table (`v_files` → `v_file_versions` →
  `v_file_version_blobs`, `v_node_document_versions`, `v_node_grants`,
  `v_node_shares`, …) physically removes their rows too. The operation is
  irreversible by construction (that is the point of "purge") and wrapped in a
  transaction so a mid-purge failure never leaves a subtree half-erased.
- **`keep_forever` is releasable.** `ReleaseKeptVersionAction` (guarded by the
  same `Edit` ability as setting the pin) flips `keep_forever` back to `false`,
  re-entering the version into the node's next prune pass. `PATCH
  nodes/{node}/document-versions/{version}/keep-forever`.

## Consequences

- A trashed node's content is unreachable via EVERY identity surface
  (listing, share, download, export) for the ENTIRE retention window, not just
  the listing/share channels — closes the asymmetry F-P1.12-01 found.
- After the retention window, `vaulter:purge-trash` leaves NO recoverable
  content for the purged subtree anywhere in the primary database — the CAS
  bytes were already GC-eligible from stage 2; the rows referencing them (and
  their inline document content) are now gone too.
- **Breaking change (D3 — no BC obligation, package unreleased):**
  `vaulter:purge-trash` output changes from "another soft-delete" to
  irrecoverable physical deletion. A host relying on `Node::withTrashed()` to
  forensically inspect a purged node after retention will no longer find it.
  Documented in `UPGRADE.md`.
- A version, once pinned `keep_forever`, is no longer permanently unprunable —
  an explicit release is required, but it now EXISTS.
