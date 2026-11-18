# ADR-0011 — Id strategy

**Status:** accepted (supersedes the dual key type of ADR-0003)

## Context

ADR-0003 offered a binary `id_type` of `id | ulid`. Vaulter must also align with
the ecosystem's morph keys (AzGuard use ULID), support native Postgres
`uuid`, and keep legacy auto-increment hosts working — three shapes, not two — all
behind one swappable seam.

## Decision

- **ULID is the default PK**, with `bigint | ulid | uuid7` selectable behind an
  `IdStrategy` contract (`key`/`reference`/`actor`/`cast`/`modelConcern`). ULID
  aligns with AzGuard morphs; `uuid7` targets native Postgres `uuid`;
  `bigint` is for legacy hosts. The default is `config('vaulter.ids.default')`
  (env `VAULTER_ID_STRATEGY`, default `ulid`); the strategy classes are ported
  from chatom (`AutoIncrementIds`/`UlidIds`/`Uuid7Ids` + `IdStrategyResolver`).
- **One schema helper drives every key/ref/actor column.** `VaulterSchema`
  (hand-written migrations) and `PanelTablesBuilder` (panel-generated) both route
  column types through the resolved `IdStrategy`, so the three shapes share one
  definition.
- **Per-panel id strategy only in own-tables mode.** On the shared `v_*` schema a
  single `v_nodes` has exactly one PK type, so `PanelRegistry` rejects a per-panel
  `idStrategy` unless the panel declares its own tables (ADR-0009 / §H.1).

## Consequences

- One package serves auto-increment, ULID and uuid7 hosts; golden-migration tests
  run the whole `v_*` set under all three strategies.
- The `HasVaulterKey` concern fills the key on `creating` and sets
  string/non-incrementing for ulid/uuid7 — never `HasUlids` directly.
- ADR-0003's `id_type` toggle is replaced by this strategy seam.
