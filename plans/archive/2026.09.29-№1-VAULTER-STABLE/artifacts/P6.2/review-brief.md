# Read-only review: PLAN2 P6.2 — Problem Details (RFC 9457)

You are an independent reviewer. The repository is /home/vostrikov/projects/packages/vaulter (Laravel monorepo, PHP 8.4 via `scripts/php84`). The change is UNCOMMITTED in the working tree: run `git status` and `git diff` (plus read the untracked files). Do not edit anything; inspect, run tests and commands, then report.

## What the change must do (normative)
- `plans/2026.09.29-№1-VAULTER-STABLE/knowledge/P6-design.json`, section `Errors` (contract), and `knowledge/P6-invariants.json` P6-I7 (no internals in the error body: `detail` only from lang, `meta` through an allowlist, 500 -> `internal_error` without detail) and P6-I8 (host routes never get problem+json or Vaulter headers).
- Item scope: `plans/2026.09.29-№1-VAULTER-STABLE/phases/P6/P6.2.json` (scope.include / rules / guidance). Logged deviations are in the plan log (`log.jsonl`, events for P6.2) and are intentional: docs() unchanged, no second report(), forced adjacent edits, pre-existing EventTypeTest failure.
- Public surface: `ProblemException::problemDetails()`, trait `ProvidesProblemDetails`, `PreconditionRequiredException`, `ProblemRenderer`, `ProblemCatalog`, `ProblemCodeSource`, `ErrorsReference`, command `vaulter:errors:reference`, lang files in core/drive/documents, `docs/reference/errors.md` (+ru), UPGRADE.md / CHANGELOG.md.

## Review axes (look for real defects, not style)
1. Info disclosure: any path where an exception message, trace, SQL, storage path, tenant id or token reaches the problem body or headers (including `errors`, `meta`, `detail`, `title`, headers copied from exceptions). Allowlist bypass (key case, nested lists, `errors` map).
2. Scope of the renderer: any request outside `vaulter.v1.*` / `{routes.prefix}/v1` that now gets different behaviour (host routes, legacy routes, `v10`, console, non-Foundation handler, requests with a route name set but another path).
3. Correctness of the Laravel pipeline: `$e->render()` precedes renderables; exception mapping done by `prepareException`; status/code/headers (`Retry-After`, `Allow`, `Upload-Offset`, `Tus-Resumable`); legacy `{message, code}` bodies unchanged.
4. Catalogue drift: every `ProblemException` code/status in `packages/*/src/Exceptions` is in a `ProblemCodeSource`, lang en/ru complete, generated docs current (`php artisan vaulter:errors:reference --check` via `scripts/php84 vendor/bin/testbench vaulter:errors:reference --check`).
5. Backwards compatibility of the moved `renderExtras()` -> `problemDetails()`; any implementor of `ProblemException` left without the new method.

## How to verify
Run only light checks (SQLite):
- `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci packages/core/tests/Http packages/core/tests/Support/DomainExceptionStatusTest.php packages/core/tests/Arch`
- from `packages/drive` and `packages/documents`: `../../scripts/php84 vendor/bin/pest --ci tests/Http` (drive) and `tests/Document/DomainExceptionStatusTest.php` (documents).
Known and out of scope: 2 failures of `packages/core/tests/Feature/EventTypeTest.php` (pre-existing).

## Output
Reply with a JSON object `{"verdict": "GREEN"|"RED", "findings": [{"id": "F1", "severity": "high|medium|low", "file": "...", "line": N, "claim": "...", "repro": "<failing test or command output that proves it>"}]}`. A finding without a reproduction is at most plausible: mark it `"repro": null`. Do not report style, naming or pre-existing issues. No findings is a valid answer.
