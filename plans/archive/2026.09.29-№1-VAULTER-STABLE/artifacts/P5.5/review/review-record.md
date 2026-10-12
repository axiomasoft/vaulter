# P5.5 review — grok/grok-4.7 (owner request 2026-10-10: no mutators, no long runs)

- Reviewer session: grok `01a12433-6009-72d2-a5b0-83246a1aeb2f`, launched through the orch bridge
  (`plan.py bridge orch launch reviewer grok --model grok-4.7 --effort high` → `ro-run.py`, Landlock: repo read-only,
  `--rw` only `vendor/`, write tools disallowed). Model `grok-4.7` from the session init event (`modelUsage`: grok-4.7-build).
  Effort `high` passed at launch; the session record carries no effort field and no `contextWindowTokens`, so no
  context capacity is claimed.
- Reviewed: uncommitted working tree of P5.5 (brief: `brief.md`). 69 turns, 34 min, exit 0. Product tree unchanged by
  the reviewer (porcelain status sha256 identical before/after: `tree-before.txt`).
- Stream: `grok-4.7-stream.jsonl`; verdict text: `grok-4.7-verdict.md`. Verdict: FINDINGS (1 major, plausible).

| # | Finding | Executor confirmation | Correction |
|---|---|---|---|
| 1 | Inside a host transaction on MySQL REPEATABLE READ the head and entries were locking reads (latest) while nodes/ACL were snapshot reads: an entry committed after the snapshot was projected with older nodes and the cursor passed it (stale upsert instead of `remove(moved_out)`, missed grant) | Not reproducible here: MySQL/PG runs are owner-forbidden in items. Confirmed by analysis against InnoDB consistent-read semantics (locking reads do not refresh the snapshot) and the code path (`ChangeOperations::list` + `NodeLocator`/`OperationGuard` plain selects). Recorded as plausible→accepted. | `list()` in a transaction: head `FOR SHARE` only for the expiry check (blocks prune, sees an earlier prune); page bound and entries are plain reads in the same snapshot as the nodes; entry rows no longer locked. Regression test `ChangeJournalRaceTest` "never skips an entry committed after a transaction snapshot, nor projects it with older nodes" (MySQL/PG two sessions; skipped on SQLite) → phase run. |

Checked by the reviewer, not reported: seq allocation and J-last in every journalled Action, D61 compaction, cursor HMAC
and type checks, prune `pruned_through` monotonic, id strategies and backfill, hub signature vs `hocuspocus-server/src/webhook.js`.
Not covered: PostgreSQL/MySQL races and upgrade fixtures (phase run). No re-review round was run.
