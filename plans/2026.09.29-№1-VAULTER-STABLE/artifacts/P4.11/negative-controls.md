# P4.11 — negative controls (D51 L, SQLite)

Each control injects one fault, runs the guarding test (expected red), restores the
exact bytes (sha256 compared before/after) and reruns it green. Runner:
`env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci <test>`.

| # | Fault injected | Guarding test | Red | Green after restore | Bytes restored |
|---|---|---|---|---|---|
| NC1 | SchemaBuilder SQLite sibling index on `lower(name)` only | core/tests/Schema/NameKeyUniquenessTest.php | exit 0 — **stays green**: migration 2026_10_07_000004 replaces any sibling index not built on name_key, so a fresh install still converges (evidence the guard works, not of the test) | exit 0 | yes |
| NC1b | Same index fault in SchemaBuilder **and** in the migration's SQLite branch, with the migration's index-shape guard reduced to "index exists" | core/tests/Schema/NameKeyUniquenessTest.php | exit 1 (4 failed: case, NFC/NFD, full folding, final sigma) | exit 0 | yes |
| NC2 | `NodeName::normalize` trims again (`self::nfc(trim($name))`) | core/tests/Naming + core/tests/Unit/NodeNameTest.php | exit 2 | exit 0 | yes |
| NC3 | `CreateFolderAction` skips `assertChildren` | drive/tests/Drive/DrivePolicyEnforcementTest.php | exit 1 | exit 0 | yes |
| NC4 | Migration skips `refuseUnknownShapes` (partial/foreign shape refusal) | core/tests/Upgrade/NodeDataContractUpgradeTest.php | exit 1 ("refuses a name_key column it did not create") | exit 0 | yes |

Raw results: NC1 red_exit=0 green_exit=0 bytes_restored=yes sha=d622c5396ab57398395ede294db6b9aadcd0ed7ec086615dcaa7ddbbde3b9fdf;NC2 red_exit=2 green_exit=0 bytes_restored=yes sha=751c94d4df9b01807d541b7f579218afe829b904398b7535e2280deaa779c9b8;NC3 red_exit=1 green_exit=0 bytes_restored=yes sha=e88481cf6b91298620bd8212908d2635da3ea52c0d8b23890e194ffb58f921ea;NC4 red_exit=1 green_exit=0 bytes_restored=yes sha=9c2f5e30e8946b4d80ce5051f63e2c7fecff6882d2426b1def66085c0e3bd7ab;NC1b red_exit=1 green_exit=0 bytes_restored=yes;
