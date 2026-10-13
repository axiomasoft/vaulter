---
title: Webhook delivery failures and redelivery
---

# Webhook delivery failures and redelivery

First tell **no delivery** from **failed delivery**:

- no `v_webhook_deliveries` row for an event: the relay did not fan it out — check `core.webhooks.outbox_lag` and that `vaulter:webhooks:relay` is scheduled;
- a `dead` row: the endpoint answered with an error or timed out until attempts ran out.

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --check=core.webhooks
```

Send dead deliveries again. The redelivery keeps the **same `webhook-id`**, so a receiver that
deduplicates on it stays correct:

<!-- snippet: shell -->

```bash
php artisan vaulter:webhooks:redeliver --endpoint=12 --since="2026-10-01" --status=dead
```

Receivers must accept a repeated event.
