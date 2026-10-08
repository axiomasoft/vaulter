# P4.13 negative controls (all on SQLite, each injected, observed red, then restored)

| # | Injected defect | Check | Result |
|---|---|---|---|
| NC1 | `GuardsStatements::guard()` no longer asks `WriteScope::assertOpen()` | `packages/core/tests/WriteGuard` | 4 of 7 red (mass update, save/delete/increment, error code, scope closes) |
| NC2 | `NodeOperations::find()` returns the `Node` model | `packages/core/tests/Arch/PublicApiReturnTypesTest.php` | red for `Contracts\Drive\NodeOperations` |
| NC3 | `NodeSnapshotMapper` always reports `viewer.starred = false` | `packages/drive/tests/Drive/NodeStarPropertiesTest.php` | 1 red (6 pass) |

Restored afterwards; `git diff` of the three files shows only the P4.13 changes.
