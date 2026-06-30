# ADR-0005 — Unified node tree

**Status:** accepted

## Context

The legacy DocVault schema kept two parallel trees — `v_documents` and
`v_document_folders` — each with its own parent column, listing, and move logic.
Re-platforming Vaulter into a Drive-style virtual file system needs folders,
binary files, and documents to coexist in **one** hierarchy a host can mount on
any entity, with cheap move/rename and breadcrumb traversal.

## Decision

- **One adjacency-list tree, `v_nodes`.** Every folder, file, document and
  shortcut is a row with a self-referencing `parent_id` (`null` = storage root).
  A `kind` column discriminates `folder | file | document | shortcut`; identity,
  tree links and lightweight caches live on the node, while bytes/snapshots live
  in side tables (`v_files`/`v_file_versions`, `v_node_document_versions`).
- **Recursive CTE for descent.** Traversal (`descendants()`/`ancestors()`) uses
  `staudenmeir/laravel-adjacency-list`'s recursive relationships scoped by
  `storage_id`, with `withMaxDepth()` for shallow loads. Move/rename is a single
  `UPDATE` of `parent_id`/`name` — no path rewriting across descendants.
- **Sibling uniqueness with soft-trash.** A partial unique index on
  `(parent_id, lower(name)) WHERE deleted_at IS NULL AND trashed_at IS NULL`
  (Postgres) — or a stored generated column on MySQL — forbids two live siblings
  of the same name while allowing trashed duplicates.

## Consequences

- O(1) move/rename, versus Nextcloud's materialized-path rewrite cost; one tree
  to authorize, list and trash instead of two.
- We pay on **reads** (CTE descent). For million-node trees or hot ACL resolution
  an `ltree`/closure-table read-model is an opt-in driver added only when
  profiling demands it — never the default (see ADR-0009 / the master plan §H.4).
- Recursive descendant aggregation (`whereHas`/`withCount('descendants')`) is
  banned from request paths (fatal on MariaDB); an arch test enforces it.
