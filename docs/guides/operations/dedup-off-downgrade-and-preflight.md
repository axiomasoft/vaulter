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

## Diagnose and recover

1. Capture the active dedup scope from the doctor and inspect a recent blob's stored `scope_key`. Compare the deployment configuration on the HTTP and worker processes.
2. Estimate storage headroom using recent upload volume. Roll the setting out to all writers together and restart long-lived workers after rebuilding the configuration cache.
3. Upload the same small payload to two drives in a test tenant. Confirm the resulting addresses match the intended scope, and verify that both existing and fresh files download.
4. If capacity or isolation checks fail, restore the previous scope and restart the workers. Reconcile the resulting inventory and allow normal CAS retention and GC to reclaim unreferenced objects.
