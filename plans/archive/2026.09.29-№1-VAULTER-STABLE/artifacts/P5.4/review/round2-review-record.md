# P5 review (P5.1–P5.4) — grok/grok-4.7, owner request 2026-10-10: no long runs, fix confirmed findings

- Four read-only sessions through the orch bridge (`ro-run.py`, writes blocked, `--rw` only `vendor/`), model `grok-4.7`, effort `high` passed at launch. Sessions: P5.1 01a123f0-1a6e-7052-bf44-7bb82ff7d174, P5.2 01a123f0-1a5b-76e1-9425-2d14704c01d0, P5.3 01a123f0-1a6e-7d72-8b81-c6a47327e311, P5.4 01a123f0-1a6c-7b80-a68c-4049a7e30afa (round 2: re-review of e21f51bf and its closure).
- Each finding was reproduced by the executor as a new regression test that fails without the fix and passes with it.

| # | Finding | Result | Correction |
|---|---|---|---|
| P5.1-1 | stale after-commit callbacks of an attempt whose commit failed and was retried | confirmed | `VaulterDatabase::transaction` marks abandoned attempts; `afterCommit` skips them |
| P5.1-2 | empty `data` stored as `{}` is delivered as `[]` | confirmed | `DeliverWebhook::post` encodes an empty `data` as an object |
| P5.2-1 | `access.inheritance_changed` published a node sequence the mutation does not advance | confirmed against P5-design-events (sequence `—`) | override removed (sequence null); golden updated |
| P5.2-2 | `share.updated` emitted for unchanged max_uses/expiry/removals | confirmed | attributes only when the locked row differs |
| P5.3-1 | prune delete could remove an outbox row a replay turned pending after the select | confirmed | delete repeats the completed predicate |
| P5.4-R1 | inactive-endpoint kill decided on an unlocked copy | confirmed | endpoint locked first, active-after-all proceeds |
| P5.4-R2 | signing used secrets read before the claim | confirmed | endpoint reloaded at signing time |
| plausible | `v_webhook_deliveries.event_id` 64 vs outbox 255 | no reproduction (SQLite does not enforce length); not changed | — |

Side fixes found by affected runs: PHPStan `argument.type` in `DeliverWebhook`; `EventPipelineArchTest` allowlists the operational `WebhookEndpointDisabled` dispatch (P5.4 left it red; V1 did not cover tests/Events).
Not covered (stated by reviewers): PostgreSQL/MySQL races and upgrade fixtures — phase run.
