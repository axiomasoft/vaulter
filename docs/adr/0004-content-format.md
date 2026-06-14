# ADR-0004 — Content format & versioning

**Status:** accepted

## Context

Documents are edited with TipTap and must support history, restore, and real-time editing.
We need a storage format that round-trips losslessly with the editor and a version model that
does not grow unbounded.

## Decision

- **TipTap JSON** is the canonical content format, wrapped in the `TipTapContent` value object
  which validates the root node (`{"type":"doc", …}`) and enforces a size limit.
- **Versions are append-only snapshots.** `CreateVersionAction` snapshots the document's
  current content; `DocumentVersion` has no `updated_at` (immutable).
- **Bounded history.** `config('docvault.max_versions')` caps retained versions per document;
  `VersionStoreRepository::pruneOldest()` drops the oldest beyond the cap (`0` = unlimited).
- **Restore** copies a version's content back onto the document (a normal update that itself
  can be re-snapshotted), preserving a linear, auditable history.

## Consequences

- Editor state survives round-trips without conversion loss.
- Storage stays bounded by default while remaining configurable.
- Yjs binary state (for live collaboration) is layered on top in the collaboration milestone;
  the persisted snapshot remains TipTap JSON for portability.
