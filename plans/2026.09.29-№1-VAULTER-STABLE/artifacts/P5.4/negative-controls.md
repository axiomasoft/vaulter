# P5.4 negative controls (2026-10-10, SQLite, targeted test files only)

| # | Temporary change | Expected | Result | Restored |
|---|---|---|---|---|
| a | `WebhookSigner::sign()` signs `"{timestamp}.{body}"` (no webhook-id) | `StandardWebhooksSigningTest` red | red: 3 of 10 failed (official vector, id-binding, header test) | exact restore (`data: "{$webhookId}.{$timestamp}.{$body}"`) |
| b | `DeliverWebhook::extendFailingRun()` kills deliveries with `whereIn('status', ['nothing'])` | `WebhookAutoDisableTest` red | red: 1 of 10 failed (disables after five days … kills its open deliveries) | exact restore (`whereIn('status', ['pending', 'failed'])`) |

Pint, PHPStan and the PG/MySQL barrier (V2–V4, V90, V80) were not executed in this run by the
owner's instruction (no heavy runs in plan-run); they stay for the phase-wide barrier.
