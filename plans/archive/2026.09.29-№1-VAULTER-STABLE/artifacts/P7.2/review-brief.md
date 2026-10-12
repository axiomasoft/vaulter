# Review brief — item P7.2 (read-only)

Repo: /home/vostrikov/projects/packages/vaulter (uncommitted working tree vs HEAD; `git diff -- . ':!plans'` plus untracked files from `git status --short`).
Do not edit the repository. Run tests/commands freely (sqlite: `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci <path>`).

Item scope: seven maintenance commands renamed to `vaulter:<area>:<verb>` (no aliases, options unchanged); core/drive/media SchedulingRegistrar + `schedule` config keys + ScheduleFrequency/ScheduleConfig and ConfigValidator check; own `vaulter:install` (replaces spatie hasInstallCommand; never edits host config/.env); `vaulter:upgrade:inspect` + `Vaulter\Schema\UpgradePreflight` extracted from migration 2026_10_01_000001 without behaviour change; docs en+ru, UPGRADE.md, CHANGELOG.md.
Normative design: plans/2026.09.29-№1-VAULTER-STABLE/knowledge (P7-design-ops: Commands, Scheduler, Install) — read via `python3 /home/vostrikov/.claude/plugins/cache/swissknifeman/task/0.28.3/scripts/plan.py show "2026.09.29-№1-VAULTER-STABLE" knowledge/P7-design-ops`.

Focus: (1) scheduler: every task withoutOverlapping+onOneServer, expiry values, scan_reconcile independent of features.webhooks and gated by scan mode, enabled=false, null off, bad value fails boot; (2) install idempotence and never writing host config/.env, non-interactive safety; (3) UpgradePreflight behaviour identical to the old migration body; (4) missed references to the old command names; (5) public API / BC.
Rules: report only findings with a reproduction (failing test or command output). A finding without a reproduction is at most "plausible". Return: verdict (GREEN/FINDINGS), numbered findings with file:line, reproduction, severity. Stop after reporting.
