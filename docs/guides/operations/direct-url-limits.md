---
title: Direct URL limits
---

# Direct URL limits

With `vaulter-drive.downloads.delivery_mode = direct_signed`, a download is a signed storage URL that
Vaulter does not proxy:

- the URL works until `vaulter-drive.downloads.direct_ttl_seconds` (default 60) passes;
- **revoking access, trashing or deleting the file does not invalidate an issued URL** — exposure is bounded only by the TTL;
- the storage sees the request, not Vaulter, so Vaulter's audit and rate limits do not apply.

Keep the TTL as short as clients tolerate. When revocation must take effect at once, use the default
`strict` mode, where every download passes the authorization check:

<!-- snippet: config -->

```php
return [
    'downloads' => ['delivery_mode' => 'strict'],
];
```

## Diagnose and recover

1. Use a test account to read a file and capture the HTTP status, storage host, issue time and expiry. Remove the signed query string from incident notes.
2. Revoke that account's access and request a fresh download to confirm the API refuses it. Check the previously issued URL again after its TTL to verify the storage refuses it.
3. For an incident, switch to `strict`, refresh the config cache and restart HTTP workers. Confirm new requests pass the authorization check and that the last issued URL has expired. Use storage access logs to review any requests made before expiry.
