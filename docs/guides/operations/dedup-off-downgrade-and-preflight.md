---
title: Dedup off, downgrade and preflight
---

# Dedup off, downgrade and preflight

`vaulter-media.dedup.scope` is `tenant` (default), `drive` or `off`. Every blob keeps the `scope_key` it was
committed under, so changing the setting leaves existing blobs valid and affects only new uploads: with `drive` or
`off`, bytes already stored under a wider scope are not shared with new uploads.

Change the setting, then confirm with the doctor:

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --production
```

Check `media.dedup.scope` in the doctor report before lowering the scope in production.
