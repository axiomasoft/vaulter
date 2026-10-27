---
title: Hardening checklist
---

# Hardening checklist

Run before going live and after every upgrade:

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --production
```

It must end without errors. Then confirm each item:

- [ ] Vaulter routes sit behind your authentication middleware (`core.routes.auth` is green).
- [ ] The permission driver is a real one — not `null` (`core.access.*`).
- [ ] Scanning is **required** for uploads and the policy is current (`drive.scan.policy`).
- [ ] Webhook delivery uses the URL guard; set `vaulter-webhooks.delivery.allowed_ip_ranges` only to what you need.
- [ ] See the add-on’s hardening guide.
- [ ] The cache store is shared and supports atomic locks (`core.cache`).
- [ ] The queue driver is not `sync` (`core.queue`).
- [ ] `vaulter-drive.downloads.direct_ttl_seconds` is as short as clients allow, or `delivery_mode` is `strict`.
- [ ] No deprecated config keys remain (`core.config.deprecated_keys`).
- [ ] Database is PostgreSQL, MySQL or MariaDB — SQLite only for development.
- [ ] `vaulter doctor` reports no unaccepted production invariant (`core.production_invariants`: `drive.permission-driver`, `collaboration.secrets`, `collaboration.hub-url`, `webhooks.schemes`, `webhooks.blocked-ranges`, `core.tenancy`); any `vaulter.security.accepted_risks` entry carries a nonempty reason ([ADR-0036](/adr/0036-secure-production-defaults)).
