# P5.2 negative controls (V80)

Manual temporary edits, restored byte for byte afterwards (no mutation tools).

| # | Edit | Test | Result | Restored |
|---|---|---|---|---|
| a | `'token_prefix' => $this->share->token_prefix` added to `NodeShareCreated::data()` | `packages/core/tests/Events/CoreEventCatalogConformanceTest.php` | RED: `share.created carries keys outside its spec` (`token_prefix`) | `git diff` of the file empty; Events suite 27 passed |
| b | `EventType::FolderCreated` description changed to "A folder was made." without regenerating | `packages/core/tests/Events/EventsReferenceTest.php`; `vaulter:events:reference --check` | RED: generated block differs; command reports both pages and fails | enum restored from backup; suite green |
