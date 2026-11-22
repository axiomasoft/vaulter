---
title: Webhook delivery failures and redelivery
---

# Webhook delivery failures and redelivery

First tell **no delivery** from **failed delivery**:

- no `v_webhook_deliveries` row for an event: the relay did not fan it out — check `core.outbox.lag` and `core.outbox.consumer_gaps` and that `vaulter:relay` is scheduled;
- a `dead` row: the endpoint answered with an error or timed out until attempts ran out.

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --check=core.webhooks.deliveries
```

Send dead deliveries again. The redelivery keeps the **same `webhook-id`**, so a receiver that
deduplicates on it stays correct:

<!-- snippet: shell -->

```bash
php artisan vaulter:webhooks:redeliver --endpoint=12 --since="2026-10-01" --status=dead
```

Receivers must accept a repeated event.

## Diagnose and recover

1. Record the event ID, delivery ID, endpoint ID, response status and last safe error classification. Check endpoint state and receiver logs for the same `webhook-id`. Avoid copying signing secrets or URL credentials into the incident record.
2. Restore the receiver, DNS or network path, and verify that DNS returns only permitted addresses. Use an absolute HTTP(S) URL on its default port and confirm the receiver accepts a signed test delivery without redirecting.
3. Redeliver the smallest failed window for one endpoint. Monitor delivery state and receiver deduplication, and confirm newly queued deliveries also complete.
