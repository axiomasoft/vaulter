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

# Round 2 — re-review of correction `0dafc5a9` (grok/grok-4.7/high)

- Launch: same bridge argv (`review-launch.json`), brief `re-review-brief.md`, stream `re-review-grok-stream.jsonl`, result `re-review-grok-result.txt`.
- Reviewer session `01a11f6c-21ba-78f2-9e62-d016829bf3f3`: `model_id grok-4.7-build`, `reasoning_effort high` (53 calls), `contextWindowTokens 500000`, 1734 s.
- Reviewer runs: azgard Pest 49 passed (142 assertions), PHPStan no errors (`/usr/bin/php8.4`, Testbench skeleton copied to a writable `APP_BASE_PATH`).
- Round-1 status: F1 resolved, F2 resolved, F3 resolved, F4 resolved (the tenant-wide isolated test fails on `e9a3c54b`), F5 rejected-confirmed, F6 resolved. Check 1 confirms by AzGuard code that `decide()` is the only decision API, owner ≡ `Permission::all()`, and that `superAllow` adds no ability.
- Reviewer verdict: **FINDINGS** (2 low).

| # | Sev | Finding | Executor verdict | Evidence |
|---|---|---|---|---|
| R2-1 | low | `docs/reference/contracts.md:96-103` (+ru `:112-119`) still list `AzgardGuard::isSuperAdmin()`; `docs/guide/troubleshooting.md:99-102` (+ru) say the seam mirrors it. | **CONFIRMED**, wider: the whole AzGuard block of `troubleshooting.md` (en/ru, lines ~70-103) still describes 0.x — Corex morph aliases, `AZ_GUARD_MORPH_TYPE`, "an unregistered alias silently never matches" (v1 throws `InvalidAssignmentScopeException` for a non-alias). | grep; files outside P4.14 task_files |
| R2-2 | low | The documented workaround "map full names `<panel>:documents.view`" for a panel with prefix `documents` cannot work: AzGuard refuses a catalog permission whose first segment is a panel prefix. | **CONFIRMED** (by reading `../azguard/packages/core/src/Panels/PanelCompiler.php:737-745` — `PrefixConflictException: give the panel another resourcePrefix() or rename the permission`) | `docs/guide/permission-drivers.md:343-345`, ru `:345-347`, `UPGRADE.md:42-44`, `config/vaulter-azgard.php` comment |

Review budget: 2 completed rounds and the one owning correction are used. R2-1/R2-2 are docs-only; a further correction needs an owner request.
