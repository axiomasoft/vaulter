---
title: Failed, unsupported and password-protected files
---

# Failed, unsupported and password-protected files

A scan ends in one outcome per file revision. What the operator may do depends on it:

| Outcome | Meaning | Action |
| --- | --- | --- |
| clean | published | none |
| failed | scanner error or timeout | retry |
| unsupported | scanner cannot read the format | decide by policy; retrying gives the same result |
| password protected | content cannot be inspected | ask the owner for an unprotected file |
| infected / quarantined | verdict against the content | **never released in bulk** |

Retry only failed revisions, after previewing:

<!-- snippet: shell -->

```bash
php artisan vaulter:scan:rescan --retry-failed --dry-run --tenant=acme --drive=1
```

<!-- snippet: shell -->

```bash
php artisan vaulter:scan:rescan --retry-failed --tenant=acme --drive=1 --reason="scanner outage"
```

Do not release quarantined revisions in bulk; decide each one individually.
