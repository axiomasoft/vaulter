---
title: Operations
---

# Operations

Runbooks for the situations an operator meets in production. Every command below exists;
each destructive or bulk step has a `--dry-run` (or `--execute` opt-in) form — run it first.
Start any investigation with `vaulter:doctor`.

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --production
```

| Runbook | When |
| --- | --- |
| [Scan backlog and stalled leases](/guides/operations/scan-backlog-and-stalled-leases) | uploads wait for a scan verdict |
| [Failed, unsupported and password-protected files](/guides/operations/scan-failures-unsupported-and-password-files) | scan outcomes other than clean |
| [Scan policy cut-over](/guides/operations/scan-policy-cutover-legacy) | legacy content moves to `all_content` |
| [Webhook delivery failures and redelivery](/guides/operations/webhook-delivery-failures-and-redelivery) | endpoints missed or rejected events |
| [Direct URL limits](/guides/operations/direct-url-limits) | `direct_signed` downloads and revocation |
| [Dedup off, downgrade and preflight](/guides/operations/dedup-off-downgrade-and-preflight) | changing `vaulter-media.dedup.scope` |
| [Drives purge and recount](/guides/operations/drives-purge-and-recount) | deleting drives, usage drift |
| [Scheduler and locks](/guides/operations/scheduler-and-locks) | maintenance tasks do not run or run twice |
