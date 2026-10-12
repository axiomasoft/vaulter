---
title: Dedup off, downgrade and preflight
---

# Dedup off, downgrade and preflight

`vaulter-media.dedup.scope` is `tenant` (default), `drive` or `off`. Moving to a narrower scope
(`drive`, `off`) while blobs are shared across the wider scope would orphan the sharing, so run the
preflight first. It changes nothing:

<!-- snippet: shell -->

```bash
php artisan vaulter:blobs:repair-scopes --dry-run
```

If it lists legacy mixed-scope blobs, copy them to their own scopes. The command redirects revision
edges atomically:

<!-- snippet: shell -->

```bash
php artisan vaulter:blobs:repair-scopes --execute
```

Only then change the setting, and confirm:

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --production
```

Never lower the scope without a clean preflight.
