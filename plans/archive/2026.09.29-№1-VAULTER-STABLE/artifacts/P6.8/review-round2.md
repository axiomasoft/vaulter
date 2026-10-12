VERDICT: GREEN

The round-1 leaks are closed. A stale `PUT` can no longer rebuild the current content tag from the `412`, and an archived drive answers `409 drive_archived` before `428` or `412`.

**412.** `problemDetails()` is only `node_id`. The live mismatch body was `meta: {"node_id":"2"}` with no `ETag` header, while the current tag was `"2.0.2"`. The compare-and-swap exception from `DocumentStateWriter` (`expected: 1`, `actual: 9`) renders the same way: `meta.node_id` only, and the text `current 9` is not in the body. Nothing catches that exception and copies `actual` back into the response. `428` is still only `header` and `reason`.

**Archived drives.** A missing `If-Match`, a stale tag, and a restore all return `409 drive_archived` with empty `meta`. `GET` content still returns `200` with the same `ETag`. The hub still authenticates read-only, `load` returns `200`, and `store` returns `403` without a state version. Checkpoints, epoch moves, and in-process stale writes behave as before.

The hub seed `409` still carries `actual_state_version`. That response stays on the webhook-signed hub call, and the Node hub keeps only the status and the code, so a REST caller cannot use it to forge `If-Match`.

`scripts/php84` could not refresh its `php` symlink (`Permission denied`). The same suites ran under `/usr/bin/php8.4` (8.4.26) with `APP_ENV=testing` and `DB_CONNECTION=sqlite`. Content, revision, writer, operations, and hub tests passed (19 + 15 + 9).