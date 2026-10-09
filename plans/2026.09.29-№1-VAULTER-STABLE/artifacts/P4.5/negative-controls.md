# P4.5 negative controls (D51: one injected error → red → exact restore → green)

Executed in this session (SQLite, `packages/documents`), raw output in
`negative-controls-output.txt`; script: inject into
`src/Actions/Collaboration/PersistCollaborationStateAction.php`, run one filtered test of
`tests/Collaboration/HubProtocolTest.php`, restore the original bytes (sha256 checked), rerun.

| # | Injected error | Guard test | Red | Green after restore |
|---|---|---|---|---|
| NC1 | storage fence dropped from the locked write (`if (false)` for the epoch comparison, `expectedEpoch: null` to the writer) | `fences a past epoch inside the locked write itself, not only at the endpoint` | exit 1, `CollaborationEpochMismatchException` not thrown | exit 1 → 0, sha256 `26a7bb49…5749` restored |
| NC2 | seed precondition `yjs_state IS NULL` dropped | `accepts exactly one seed per generation` | exit 1, `Expected response status code [409] but received 200` | exit 0 |

## Handed off to the owner run (hub dependencies are not installed in this session)

| # | Injected error | Guard test | Procedure |
|---|---|---|---|
| NC3 | `onStoreDocument` rethrows on a terminal 409 (replace the `TERMINAL_CONFLICTS` branch body with `throw new Error('409')`) | `hocuspocus-server/test/epoch-fence.test.js` — "a 409 … closes the room with 4409 epoch_changed and never throws" | `cd hocuspocus-server && node --test test/epoch-fence.test.js` → red; revert exactly the injected line (compare sha256 before/after); rerun → green |
| NC4 | `onLoadDocument` applies its own seed before the store answers (move `Y.applyUpdate(document, update)` above `webhook.storeDocument`) | `test/load-seed.test.js` — "takes the other instance's seed after a seed conflict" | same procedure |
