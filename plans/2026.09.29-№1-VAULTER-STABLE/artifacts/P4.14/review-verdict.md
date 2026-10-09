# P4.14 review — grok/grok-4.7/high (round 1)

- Reviewed: commit `e9a3c54b` (base `c0627911`), read-only launch via `plan.py bridge orch launch reviewer grok --model grok-4.7 --effort high` (`review-launch.json`), brief `review-brief.md`, stream `review-grok-stream.jsonl`, result `review-grok-result.txt`.
- Reviewer session: `01a11f45-4c5d-7252-85f0-08c918e6faa2` (`~/.grok/sessions/<cwd>/…`): `model_id grok-4.7-build` / `current_model_id grok-4.7`, `reasoning_effort high`, `contextWindowTokens 500000`, 45 model calls, 897 s.
- Reviewer runs: azgard Pest 48 passed, PHPStan no errors (via `/usr/bin/php8.4`; the `scripts/php84` shim could not refresh in the read-only sandbox).
- Reviewer verdict: **FINDINGS** (1 high, 3 medium, 2 low).

## Executor triage

| # | Sev | Finding | Executor verdict | Evidence |
|---|---|---|---|---|
| F1 | high | `override()` ignores the workspace: `isSuperAdmin()` asks AzGuard in the global scope, so under `AssignmentScopePolicy::isolated` a tenant-wide super-admin (not a super-admin inside the workspace for AzGuard) becomes a Vaulter `Effect::Allow` in every workspace. | **CONFIRMED** (own run) | Scratch test on an isolated `app` panel (removed after the run): `azguard_global => true`, `azguard_in_project_7 => false`, `vaulter_override => "Allow"`; assertion `override() === null` failed. |
| F2 | medium | Fatals instead of denies: `AssignmentScopeRef::of()` throws `InvalidAssignmentScopeException` for a non-alias drive-owner type (class-name morph); `isSuperAdmin()` throws `TenantRequiredException` on a tenant panel without a current tenant (while `hasPermission` denies); panel selection can throw `PanelNotResolvedException`. Docs claim "a type the panel does not declare is denied" without the alias precondition. | **CONFIRMED** (by reading `IdentityCodec.php:25,40-44`, `SubjectAccess.php` `readScope`/`requiredTenant`) | — |
| F3 | medium | A host panel whose id/prefix is `documents` breaks the default `documents.*` map (prefix conflict at boot or one-segment key `view` → `InvalidPermissionKeyException`); not documented. | **CONFIRMED** (matches the executor's own first fixture failure: `Invalid permission key "view": must have at least two dot-separated segments`) | — |
| F4 | low | Tests cover only an `inherit` panel; `SpyAzgardUser::isSuperAdmin` ignores `on:`, so F1 stays green. | **CONFIRMED** | F1 run |
| F5 | medium | `.github/workflows/azgard.yml` fetches `axiomasoft/azguard-private.git` without a token. | **REJECTED** | `gh repo view axiomasoft/azguard-private` → `{"isPrivate":false,"visibility":"PUBLIC"}`; URL unchanged from base `c0627911`. |
| F6 | low | Stale wording: `tests/Access/AzgardDriverTest.php:20-25` (Corex ContextGuard / azguard/corex), `composer.json` keyword `corex`; `packages/azgard/README.md` selects the driver with `drivers.permission` instead of `access.permission_driver`. | **CONFIRMED** (by reading) | — |

## Correction

Pending owner decision on F1: the fix needs the workspace in the super-admin question, i.e. a change of the public seam `Vaulter\Azgard\Contracts\AzgardGuard::isSuperAdmin()` (signature) or another shape. F2–F4, F6 are folded into the same single owning correction once F1's shape is decided.
