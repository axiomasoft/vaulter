# Read-only review brief — PLAN2.P6.3 (OpenAPI 3.1 and contract tests for REST v1)

You are an independent, read-only reviewer. Do not modify any file (the sandbox refuses writes to the
repository). Inspect, run read-only commands and tests, report findings, stop.

## What to review

The uncommitted working tree of `/home/vostrikov/projects/packages/vaulter` against `HEAD` (e07b33d3).
Ignore everything under `plans/` except the artifacts named below (owner work in `plans/**/views/` is not
part of this item). New files are untracked, so use `git status --short` and read them directly.

    git status --short -- . ':!plans'
    git diff HEAD -- . ':!plans'

Item contract (JSON, source of truth): `plans/2026.09.29-№1-VAULTER-STABLE/phases/P6/P6.3.json`.
Normative design: `plans/2026.09.29-№1-VAULTER-STABLE/knowledge/P6-design.json` sections `OpenAPI`, `Resources`,
`Validators`, `Routes`, `Egress`; decision D64 in `plans/2026.09.29-№1-VAULTER-STABLE/decisions/`. Executor notes
and recorded deviations: `plans/2026.09.29-№1-VAULTER-STABLE/log.jsonl` (entries for P6.3) and
`artifacts/P6.3/{check-profile.json,tool-pins.md,changelog-entry.md}`.

## Substantive changes (review closely)

- `openapi/vaulter-v1.yaml` — the OpenAPI 3.1 contract (81 operations); `openapi/examples/*.json`.
  The YAML was generated once by a scratch script from the route table, the resource classes and responses
  recorded from the existing feature suites; the YAML is the artifact. Check it against the real routes
  (`vendor/bin/testbench route:list --name=vaulter.v1 --json`) and resources (`packages/*/src/Http/Resources`,
  `Controllers`), not against itself.
- `packages/core/tests/Support/OpenApi/*` — the adapter: `OpenApiContract` (YAML load, operation index, `$ref`
  resolution, hard stop on unsupported constructs), `OpenApiResponseValidator` (status → media type → schema,
  headers, opis/json-schema 2020-12 through a registered raw document), `ValidateOpenApiResponses` (global test
  middleware, fails in `terminate`), `ValidatesOpenApiResponses` trait wired into the three `TestCase`s,
  `OpenApiTestCase`.
- `packages/core/tests/OpenApi/*` and `packages/core/tests/Fixtures/{openapi/negative,golden}` — coverage,
  document, validator self-test with negative fixtures, enabled-switch, route golden, examples.
- `packages/drive/src/Http/Controllers/Nodes/ShowNodeController.php` — one-line fix (`getData(true)` →
  `getData()`): `include=children` answered an empty child `properties` as `[]` (a P6-design#Resources violation
  that the contract validation found). A recorded deviation.
- `.github/workflows/openapi.yml` (oasdiff, pinned `tufin/oasdiff:v1.33.0`, base = last `v*` tag with the file,
  else the PR base branch; never run locally, D66).
- `phpunit.xml`, `packages/{drive,documents}/phpunit.xml`, `tests/Pest.php`, `composer.json` files
  (require-dev `opis/json-schema ^2.4`, `symfony/yaml ^8.0`), docs (`docs/reference/rest-api.md` + `ru`),
  `CONTRIBUTING.md`, `CHANGELOG.md`.

## Risky seams to probe

1. Spec vs code drift: any operation whose documented statuses, headers, request body, parameters, or response
   schema contradict what the controller/resource really does (also statuses the code can emit but the document
   omits, e.g. error statuses of an operation; required vs optional response fields; enums).
2. Validator blind spots: a response the middleware would let through although it breaks the document (wrong
   `$ref` pointer escaping, header checks skipped, media type matching, HEAD/streamed/redirect handling,
   `format: date-time`, `oneOf` with `null`, `allOf` composition, `{}` vs `[]`), or a route silently skipped
   (`vaulter.v1.__probe*` exemption — is it too wide?). Prove with a failing input, not by reading.
3. The coverage test: could an operation pass while its path or method differs from the route (HEAD/OPTIONS
   handling, `GET|HEAD`)?
4. The golden and examples: do they pin what they claim; is `VAULTER_OPENAPI_EXAMPLES_WRITE` / `UPDATE_GOLDEN`
   a way to make a red test green without a reviewable diff?
5. Contract promises of the item: every IM operation declares required `If-Match`, `412`, `428`; `nodes.show` and
   `documents.content.show` declare `If-None-Match` and a body-less `304`; streams declare
   `application/octet-stream` plus `Content-Disposition`/`Cache-Control`; `Problem` lists the catalog codes.
6. CI workflow correctness: base selection, shell quoting, the docker mounts, `--fail-on ERR`, behaviour when the
   file does not exist yet at the base.
7. Anything that contradicts P6-design or the item rules without a recorded deviation.

## Checks you may run (read-only, SQLite in memory)

    env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci packages/core/tests/OpenApi
    cd packages/drive && env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Http
    cd packages/documents && env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Http
    env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci packages/core/tests/Http

Do not run PostgreSQL/MySQL, full suites, mutation tools, docker, oasdiff or anything that needs network.
Scratch PHP/JSON you need goes to a temporary directory outside the repository.

## Report

Start with a one-line verdict: `VERDICT: PASS` or `VERDICT: FINDINGS`. Then, per finding: severity
(blocker / major / minor), file:line, what is wrong, and a **reproduction** — a test you ran or a command and
its output. A finding without a reproduction is at most "plausible"; say so. Keep style nits out unless they
hide a defect.
