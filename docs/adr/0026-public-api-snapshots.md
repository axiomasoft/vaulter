# ADR-0026 — Public API on snapshots; Eloquent is internal

**Status:** accepted; amends [ADR-0020](/adr/0020-authorized-public-api)

> Implements PLAN2.D44 (and D38, opaque string ids) in PLAN2.P4.13.

## Context

ADR-0020 kept the public PHP API Laravel-native: operations took and returned Eloquent models. That
makes every column a contract, lets a host that holds a `Node` write it directly and bypass the
invariants, the authorization and the audit trail (`$node->save()`, `Node::query()->update()`), and
makes a mass update or delete skip the model events altogether. The window to change the shape of the
API is now: 0.2.0 was never published and 1.0 freezes it.

## Decision

**1. Operations speak in ids and snapshots.** A node argument is `NodeSnapshot|string|int`; the same
for file revisions, grants and share links. Results are `final readonly` snapshots in
`Vaulter\Data\Snapshots` (`DriveSnapshot`, `QuotaSnapshot`, `NodeSnapshot` with its
`FileHeadSnapshot`, `DocumentHeadSnapshot` and `ViewerStateSnapshot`, `FileRevisionSnapshot`,
`GrantSnapshot`, `ShareLinkSnapshot`, `ActivityEntrySnapshot`, and `MintedShareLink` — the only
place a raw share token appears). Lists return `Vaulter\Data\Page<T>` (`items`, an opaque
`nextCursor`). Identifiers are strings under every id strategy; dates are UTC `DateTimeImmutable`.
Snapshot constructors are `@internal`: a host reads them.

**2. Mapping stays in core.** `@internal` mappers build snapshots from models in batches (one query for
file heads, one for the viewer's stars, one for document heads, whatever the page size). Core does not
know the documents package: `Contracts\Drive\NodeDocumentHeads` is bound by it, and a document node
has no head without it.

**3. Models stay where they are, marked `@internal`.** The namespace `Vaulter\Models\*` does not
change; the compatibility policy ([Compatibility](/guide/compatibility)) excludes models, Actions,
Queries, repositories, tables and columns from the 1.0 contract. Filament tables read models inside
the package; its actions go through the operations.

**4. Writes are guarded (defence in depth).** `WriteScope`, a scoped container service with a depth
counter, is opened by `VaulterDatabase::transaction()` and `VaulterDatabase::write()`. Every Vaulter
model uses `GuardsWrites`, which replaces the model's base query builder (`GuardedBuilder`;
`GuardedCteBuilder` for the node tree, which also needs the recursive-query builder). `insert`,
`update`, `upsert`, `increment`, `decrement`, `delete` and `truncate` outside a scope throw
`WriteOutsideOperationException` (`write_outside_operation`, 500) — and a model `save()`, `delete()` or
`touch()` ends in one of them, so it is refused the same way. No model event is registered: the batched
blob GC treats any `saving` listener as a foreign observer and would fall back to its per-row path.
`vaulter.database.write_guard` (default `true`) switches it off; the package test cases do it unless
`VAULTER_TEST_WRITE_GUARD` is set, and dedicated lanes run with it on. `DB::table()` and raw SQL are not
intercepted: this is not a sandbox.

**5. REST keeps its shape until v1.** Resources are built from snapshots; keys are unchanged, ids are
strings.

## Consequences

- A host cannot reach a row through the public API, and a stray write is refused loudly instead of
  silently skipping an invariant.
- Operations cost one extra mapping step; listings do not add per-row queries.
- Hosts and tests that held `Node`, `NodeGrant` or `NodeShare` results switch to snapshots; code that
  needs a model asks for it internally (`DriveHandle::model()` is `@internal`).
- The guard is a second line: invariants still live in the Actions.

## Alternatives rejected

- **Return models read-only (`Model::preventModification`).** Laravel offers no such switch, and the
  columns would stay a contract.
- **Wrapper objects with magic access to the model.** They leak the model through `__get`.
- **Guard through a database connection listener.** It would catch `DB::table()` of the host too, and
  every Vaulter job and command would need an exception list.
