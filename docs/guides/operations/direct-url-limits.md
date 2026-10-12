---
title: Direct URL limits
---

# Direct URL limits

With `vaulter.downloads.delivery_mode = direct_signed`, a download is a signed storage URL that
Vaulter does not proxy:

- the URL works until `downloads.direct_ttl_seconds` (default 60) passes;
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
