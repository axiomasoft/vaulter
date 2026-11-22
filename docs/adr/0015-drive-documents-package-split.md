# ADR-0015 — Drive and documents package boundaries

**Status** — Accepted. This entry records the package split implemented in the monorepo. ADR-0017 gives the internal namespace conventions, and ADR-0030 defines table and model ownership.

## Context

The unified node tree from ADR-0005 serves folders, files and editor documents. Hosts can use file storage without enabling a document editor. Both packages still need the same tenant, access and operation pipeline.

## Decision

`vaulter-drive` owns the node tree, file and upload paths, drive-level authorization, and the HTTP surface for drives. `vaulter-documents` depends on the drive package and owns document content, working state and revisions. Core owns the shared transaction and module contracts. A drive install can run without the documents module. Document capabilities attach through the declared extension surfaces.

The packages own their migrations and test suites. Public contracts and snapshots carry data across the boundary, following ADR-0026 and ADR-0030.
