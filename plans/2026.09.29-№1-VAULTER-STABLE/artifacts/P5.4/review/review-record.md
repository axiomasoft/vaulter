# P5.4 review — grok/grok-4.7 (owner request 2026-10-10: no mutators, no long runs)

- Reviewer session: grok `01a123ba-0676-7253-acbe-57699d1ee244`, launched through the orch bridge (`ro-run.py`, write tools disallowed, `--rw` only `vendor/`); model `grok-4.7` confirmed by the session record's `modelId`.
- Effort `high` was passed at launch; the session record carries no effort field, and no `contextWindowTokens` was recorded — no context capacity was requested, none is claimed.
- Reviewed: commit 4fddaf26. Stream: `grok-4.7-stream.jsonl`; verdict text: `grok-4.7-verdict.md`. 71 turns, 32 min, product tree untouched (git status clean outside plans/).
- Verdict: FINDINGS (3 major, each with a reproduction).

| # | Finding | Executor confirmation | Correction |
|---|---|---|---|
| 1 | `StandardWebhookVerifier` kept only the first header value; a stale signed timestamp could ride behind a fresh one in a header bag | confirmed (reproduced as a verifier test) | duplicate `webhook-id`/`webhook-timestamp` with different values are refused (`ambiguousHeaders`); signature lines of several headers are joined |
| 2 | `UpdateWebhookEndpointAction` decided "was active" from a stale in-memory copy, leaving new deliveries pending after a manual switch-off | confirmed (`WebhookAutoDisableTest`) | Update/Deactivate re-read the endpoint under `lockForUpdate` before deciding |
| 3 | Redelivery racing a deactivation could publish `pending`, and `DeliverWebhook` never checked `is_active` | confirmed (`RedeliverWebhookTest`) | Redeliver locks the endpoint before the delivery (same order as settlement); `DeliverWebhook` kills a delivery of an inactive endpoint instead of POSTing |

Not covered by the reviewer (stated by it): PostgreSQL deadlock order and the auto-disable race — barrier PG tests.
Regression tests: `StandardWebhookVerifierTest` (2), `WebhookAutoDisableTest` (2), `RedeliverWebhookTest` (1). Affected sqlite files + Arch green after the correction; Pint clean.
