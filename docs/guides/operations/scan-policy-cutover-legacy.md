---
title: Scan policy cut-over
---

# Scan policy cut-over

Content uploaded before scanning was required has no verdict (`new_content_only`). To require a
verdict for all content (`all_content`):

1. Set the scanner identity and policy version in `vaulter-drive.uploads.scan`.
2. Preview the backfill:

<!-- snippet: shell -->

```bash
php artisan vaulter:scan:rescan --dry-run --tenant=acme
```

3. Record the scan requests (bounded; repeat with `--after` until nothing is left):

<!-- snippet: shell -->

```bash
php artisan vaulter:scan:rescan --rescan --tenant=acme --reason="policy cut-over"
```

4. When `vaulter:doctor` shows no backfill left, activate the policy:

<!-- snippet: shell -->

```bash
php artisan vaulter:scan:rescan --activate-policy
```

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --check=drive.scan.policy
```

Activate only once the doctor shows no unscanned legacy content.

## Diagnose and recover

1. Record the target policy version and scanner identity. Verify the bound scanner with the doctor and check available queue and scanner capacity before starting the backfill.
2. Watch overdue obligations and failed scans as each batch completes. If progress stalls, repair the scanner or worker, retry failed scans in the affected scope, then resume from the last recorded cursor.
3. Before activating, confirm that the doctor shows the target policy ready and that legacy files have verdicts. If activation reveals a coverage gap, pause rollout, restore the prior policy through normal deployment, and finish backfill before retrying.
