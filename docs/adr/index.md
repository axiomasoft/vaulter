# Architecture Decision Records

Records of the significant architectural decisions behind Vaulter.

- [ADR-0001 — Collaboration transport](/adr/0001-collaboration-transport) — _accepted; placement and persistence replaced by ADR-0031_
- [ADR-0002 — Authorization delegation](/adr/0002-authorization-delegation) — _refined by ADR-0007_
- [ADR-0003 — Embeddability & data model](/adr/0003-embeddability-data-model) — _superseded by ADR-0009 / ADR-0011_
- [ADR-0004 — Content format & versioning](/adr/0004-content-format)
- [ADR-0005 — Unified node tree](/adr/0005-unified-node-tree)
- [ADR-0006 — Content-addressed blobs & race-free GC](/adr/0006-content-addressed-blobs-gc)
- [ADR-0007 — Pluggable authorization](/adr/0007-pluggable-authorization) — _superseded in part by ADR-0025_
- [ADR-0008 — Media Library: custom CAS, not spatie/laravel-medialibrary](/adr/0008-media-library-decision)
- [ADR-0009 — Storage Panels](/adr/0009-storage-panels)
- [ADR-0010 — PHP + npm monorepo & release](/adr/0010-php-npm-monorepo-release)
- [ADR-0011 — Id strategy](/adr/0011-id-strategy)
- [ADR-0012 — Dedup scope](/adr/0012-dedup-scope)
- [ADR-0013 — Two sharing channels kept separate](/adr/0013-two-sharing-channels)
- [ADR-0014 — Legacy DocVault: build parity, then excise from core](/adr/0014-legacy-docvault-strangler)
- [ADR-0016 — Trash retention & purge erasure](/adr/0016-trash-retention-erasure)
- [ADR-0017 — Internal package structure canon (layer-first + domain sub-namespace)](/adr/0017-package-structure-canon)
- [ADR-0018 — Pluggable editor-engine canon (kind-neutral handler contract)](/adr/0018-editor-engine-canon)
- [ADR-0019 — Kernel ownership](/adr/0019-kernel-ownership) — _superseded by ADR-0027_
- [ADR-0020 — Authorized public API](/adr/0020-authorized-public-api) — _accepted; amended by ADR-0026_
- [ADR-0021 — Profiles](/adr/0021-profiles) — _accepted; amends ADR-0009_
- [ADR-0022 — Revisions & documents aggregate](/adr/0022-revisions-and-documents-aggregate) — _proposed; amends ADR-0018_
- [ADR-0023 — Event pipeline](/adr/0023-event-pipeline) — _proposed_
- [ADR-0024 — REST v1 & errors](/adr/0024-rest-v1-and-errors) — _proposed_
- [ADR-0025 — Access model 1.0](/adr/0025-access-model-v1) — _accepted; supersedes ADR-0007 in part_
- [ADR-0026 — Public API on snapshots; Eloquent is internal](/adr/0026-public-api-snapshots) — _accepted; amends ADR-0020_
- [ADR-0027 — Kernel boundaries and the module system](/adr/0027-kernel-boundaries-and-modules) — _accepted; supersedes ADR-0019_
- [ADR-0028 — Extension slots and the operation pipeline](/adr/0028-extension-slots-and-operation-pipeline) — _accepted; builds on ADR-0027_
- [ADR-0029 — Idempotency](/adr/0029-idempotency) — _accepted; builds on ADR-0028_
- [ADR-0030 — Package ownership of schema, models and configuration](/adr/0030-package-ownership-and-schema) — _accepted; builds on ADR-0027_
- [ADR-0031 — The collaboration package and the durability model](/adr/0031-collaboration-package-and-durability) — _accepted; builds on ADR-0030; replaces ADR-0001 in part_
- [ADR-0032 — The operation catalogue and aggregate concurrency](/adr/0032-operation-catalog-and-concurrency) — _accepted; builds on ADR-0028 and ADR-0029_

- [ADR-0033 — Kernel budget and dependency rules](/adr/0033-kernel-budget-and-dependency-rules) — accepted.

- [ADR-0034 — Document SPI v2 and collaboration protocols](/adr/0034-document-spi-v2-and-collaboration-protocols) — accepted; amends ADR-0031.

- [ADR-0035 — Product boundary and release channels](/adr/0035-product-boundary-and-release-channels) — accepted; amends ADR-0010 and ADR-0031.

- [ADR-0036 — Secure production defaults](/adr/0036-secure-production-defaults) — accepted.
