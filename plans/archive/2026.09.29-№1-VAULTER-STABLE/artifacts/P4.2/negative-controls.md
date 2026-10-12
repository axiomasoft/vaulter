# P4.2 — negative controls (D51: bounded, SQLite)

Each control injects one defect, runs the named test file on SQLite (cwd as listed), then restores the exact bytes (sha256 verified after restore). Run 2026-10-08 by anthropic/claude-sonnet-5-5/high.

| # | Guard | Injection | Test file | Result with defect | Restored sha256 (prefix) |
|---|---|---|---|---|---|
| NC1 | assertCopySupported ignores the PurgeOnly marker | `NodeContentLifecycleRegistry.php` edit | core NodeContentLifecycleRegistryTest | exit 1 — 3 failed | 70adaa2466b3 |
| NC2 | fallback wins over the primary registration | `NodeContentLifecycleRegistry.php` edit | core NodeContentLifecycleRegistryTest | exit 2 — 1 failed | 70adaa2466b3 |
| NC3 | copy takes the Restore path and bumps the collaboration epoch (cause Restore instead of Copy) | `DocumentContentLifecycle.php` edit | documents DocumentCopyLifecycleTest | exit 1 — 2 failed | 230a44836856 |
| NC4 | purge deletes the history in batches until empty | `DocumentContentLifecycle.php`: batch loop runs once (`while (false)`) | documents DocumentPurgeLifecycleTest | exit 1 — 1 failed (purges a history longer than one delete batch) | 230a44836856 |
| NC5 | hidden document no longer refused as a whole (authorizedNodeIds check dropped) | `CopyNodeAction.php` edit | drive CopySubtreeAuthorizationTest | exit 1 — 1 failed | b1396b75858e |

All controls were green again after the restore (the same files pass in the focused runs recorded in check-profile.json).
