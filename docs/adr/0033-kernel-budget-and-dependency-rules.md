# ADR-0033 — Kernel budget and dependency rules

**Status:** accepted — PLAN4 D6/D7/D19, builds on [ADR-0030](/adr/0030-package-ownership-and-schema).

## Context

Architecture audit B §6.1–6.4 calls for executable kernel boundaries. P2.1 removed sibling dev dependencies
from core and P2.2 moved upper-module tests to integration without changing their intended coverage.
The preserved P1 baseline and post-P2.2 source both contain 262 PHP files and 18,911 physical lines;
the current surface contains 122 unique Api/Spi FQCNs. Snapshot member lines are not type counts.

## Decision

Pin exact core runtime require keys and prohibit sibling dev dependencies. Bound PHP file, physical LOC
and unique Api/Spi type growth individually to 10%, without rounding. Measure regular src PHP files without
following symlinks, including blank lines and docblocks. Append a substantive reason to budget history and
explain budget changes in an ADR; retain the original measurements rather than resetting a failing baseline.

Every package's sibling require-dev must belong to its runtime transitive closure. Exempt only the exact
metapackage name. Detect unknown runtime siblings and cycles. Allow only exact from/to/reason/decision
exceptions and reject stale entries; the initial allowlist is empty. External axioma/azguard is not a sibling.

Keep domain Node/Document/Blob/Share/Revision, tables and HTTP outside core. Existing boundary/surface
checks own those constraints, including hidden activation. Keep OperationCatalog, typed context, receipts
and outbox; use typed slots and sealed registries. A new SPI requires two implementations, a trust boundary,
a deliberate extension seam with a contract kit, or separation of an add-on. P6 owns the BC catalog.

## Consequences

Negative fixture manifests prove independent budget, require, dev-edge, cycle, unknown-sibling and stale
allowlist failures without changing production manifests. Discovery records additions by exact name and
preserves original lists. These source guards are lightweight; standalone installation and server qualification
remain deferred to the P8.2 owner runbook. They are not runtime qualification evidence.

## Alternatives

A new vaulter-contracts package is rejected: the existing kernel and named extension slots already provide
the necessary ownership boundary, and another package would add a second abstraction surface.

The dependency rules are documented in the [architecture](/concepts/architecture#dependency-rules-and-kernel-budget).
