# Review P6.5 — grok/grok-4.7/high, session 01a12ab3-7cfa-71c3-9f27-cc9ecec28852 (1 round)
Finding 1 (bug, reproduced, CONFIRMED): scripts/collab-e2e.sh passed an unversioned VAULTER_LARAVEL_WEBHOOK_URL, so the hub exited at start. Fixed: launcher now uses …/api/vaulter/v1; V1/V2 rerun green.
Plausible, not fixed: test/e2e/restore-epoch.e2e.js still uses nodes/{id}/collab/token (removed route; P6.8 / e2e not run per D66).
