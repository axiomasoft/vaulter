# P4.9 review brief (reviewer grok/grok-4.7/high, read-only) — PENDING, not launched

Why pending: `plan.py bridge orch launch reviewer grok --model grok-4.7 --effort high` fails with
`BRIDGE_PARTNER_UNAVAILABLE: orch not found (set SKM_ORCH_ROOT)`; `swissknifeman/orch` and
`swissknifeman-next/orch` are not accepted as the root. No other provider/model was substituted.

Scope: working tree vs HEAD 248e16d5 (`git diff HEAD`, plus untracked: migration
`2026_10_07_000005_access_model_v1.php`, `Access/Roles/*`, `AccessSource`, `SetNodeInheritanceAction`,
`InheritanceRestriction`, `UnknownRoleException`, `docs/adr/0025-access-model-v1.md`, new tests).
Decisions not to reopen: D45, D46, D53, D30. No deny grants; union of roles up to the first
`inherit_grants=false` node; host `override()` is the only block.

Risky seams to review (give a failing test or command output for every finding):
1. Migration preflight/backfill/down (`packages/core/database/migrations/2026_10_07_000005_access_model_v1.php`):
   deny / unknown level / extra bits refusal, MySQL re-run after partial DDL, `owner_id` drop, reversible `down()`.
2. `SetNodeInheritanceAction` `copy_current`: never broader or longer-lived than the inherited roles.
3. `AccessResolver`: `effectiveGrants` vs `evaluateMany` vs `explain` equivalence (union, boundary, expiry, drive boundary).
4. `OperationGuard::copyableSubtree` (View+Download+Copy on every copied node), move/restore/purge rules.
5. `ShareContext` boundary + minimal share-link adaptation (full redesign is P4.10).
6. Drivers: gate/spatie/azgard role maps, unknown role key, `AzgardPermissionDriver::resolve` union.
7. P4.11 barrier fix inside this item: `2026_10_07_000004_node_data_contract.php` `liveSiblings()` — roots of different drives are not siblings (regression test in `NodeDataContractUpgradeTest`).
8. `packages/vue`: grants moved to `role` (types, client, `VaulterNodeGrantsPanel`, tests, docs). vitest/vue-tsc could not run here (deps not installed).
9. Known open (not P4.9): drive PHPStan red at HEAD (`RescanFileRevisionsCommand.php:171`).

Evidence: `receipts.json`, `barrier-receipts.json`, `negative-controls.md`, `test-map.md` in this directory.
