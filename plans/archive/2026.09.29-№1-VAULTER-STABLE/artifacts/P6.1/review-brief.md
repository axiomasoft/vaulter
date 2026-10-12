# Read-only review: PLAN2 P6.1 — REST v1 HTTP kernel and core routes

You are an independent reviewer. The repository is /home/vostrikov/projects/packages/vaulter (Laravel monorepo, PHP 8.4 via `scripts/php84`). The change is UNCOMMITTED in the working tree: run `git status` and `git diff` (plus read the untracked files under `packages/` and `docs/`). Ignore the modified `plans/**/views/*.md` files (owner work). Do not edit anything; inspect, run tests and commands, then report.

## What the change must do (normative)
- `plans/2026.09.29-№1-VAULTER-STABLE/knowledge/P6-design.json`, sections `Kernel`, `Validators`, `Egress`, `Routes` (CORE part only), `Resources`.
- `plans/2026.09.29-№1-VAULTER-STABLE/knowledge/P6-invariants.json`: P6-I1 (no 304/412/428/stream/302 before authentication, tenant resolution and Operations authorization), P6-I2 (unknown, foreign-tenant and unreadable resources answer the same 404 body except request_id), P6-I3 (node ETag changes with any change of the caller's representation), P6-I4 (no lost update on If-Match routes: strong compare + CAS sequence), P6-I5 (bytes only through ContentResponder with a fresh check per request; exact revision), P6-I6 (no shared caching of personal responses; no storing tokens/secrets/bytes), P6-I9 (public share: token never in Referer/cache, throttled, no-store), and the Bypass section.
- Item scope and rules: `plans/2026.09.29-№1-VAULTER-STABLE/phases/P6/P6.1.json` (scope.include, rules, guidance).
- Intentional deviations are logged in the plan log (`plans/2026.09.29-№1-VAULTER-STABLE/log.jsonl`, events with `"item": "P6.1"`): LegacyNodeResource for the unversioned drive/documents routes, three new problem codes, ApplyResponseCachePolicy + middleware priority, SharedNodeResolver and opaque `?node=`, share downloads always streamed, ChangeOperations feature-off → profile_restriction, resource field differences, `onDrive` concealment, inheritance lock-out answer, forced test/doc edits, and seams (load stand, @vaulter/vue, changes existence oracle, PG ids). Do not report these as defects unless the implementation of the deviation itself is wrong.

## Main files
- Kernel: `packages/core/src/Http/Support/{HttpOperationContextFactory,HttpResourceResolver,RepresentationTag,Preconditions,ContentResponder,DownloadHeaders,SharedNodeResolver}.php`, `packages/core/src/Http/Middleware/{AssignCorrelationId,AddApiVersionHeader,ApplyResponseCachePolicy,ResolveShareToken,VaulterHttpOperationMap,VaulterThrottledRouteMap,RecordVaulterRequestMetrics}.php`, `packages/core/src/Configuration/RoutesConfig.php`, `packages/core/src/Providers/HttpRegistrar.php`, `packages/core/routes/api.php`.
- Controllers/requests/resources: `packages/core/src/Http/Controllers/{Access,Sharing,Activity,Changes,Webhooks}/*`, `packages/core/src/Http/Requests/**`, `packages/core/src/Http/Resources/**`.
- Batch abilities: `packages/core/src/Access/OperationGuard.php::abilitiesMany`, `packages/core/src/Contracts/Drive/NodeOperations.php`, `packages/drive/src/Operations/NodeOperations.php`.
- Tests: `packages/core/tests/Http/*` (new), `packages/core/tests/Support/NodeProbeRoutes.php`, `packages/core/tests/Arch/HttpV1ArchTest.php`.

## Review axes (real defects only, not style)
1. Access boundary / ordering (P6-I1, P6-I2): any v1 route that answers 304, 412, 428, a stream or a redirect before the node/drive is found and authorized through the Operations; any path where unknown vs invisible vs foreign-tenant resources are distinguishable (status, code, body, headers, timing-free); grant/share/endpoint/delivery ids of another parent that can be read or changed.
2. Validators (P6-I3, P6-I4): RepresentationTag canonicalization (key order, `{}` vs `[]`, numeric-string keys, floats), If-Match parsing (lists, weak tags, `*`, malformed values), expected sequence actually passed to the CAS.
3. Egress (P6-I5): header injection via filenames (CR/LF, quotes, path separators, invalid UTF-8), direct_signed revision pinning and signer misuse, 423 before any byte/URL, previewer download, public share `?node=` escaping the link's node-set or tenant.
4. Caching and leakage (P6-I6, P6-I9): any v1 response that a shared cache could store, Vary missing on validated responses, token/secret/password in logs, metrics labels, headers or bodies other than the one-time store/rotate answers; 401/404/429 public answers without the share headers.
5. Correctness of the Laravel pipeline: middleware order after priority sorting (`prependToMiddlewarePriority`), exceptions rendered inside middleware, host routes untouched (no headers, no problem+json), legacy unversioned drive/documents routes unchanged in shape.
6. Batch abilities: `abilitiesMany` must equal `abilities()` per visible node (profile and tenant adjustments included), skip invisible nodes, and not issue per-node queries.

## How to verify
Run only light checks (SQLite, a few minutes each):
- `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci packages/core/tests/Http packages/core/tests/Arch packages/core/tests/Sharing`
- from `packages/drive`: `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Arch tests/PublicApi tests/Feature`
- Write a throwaway Pest test under `/tmp` only if you need a reproduction; never under the repository.
Known and out of scope: 2 failures of `packages/core/tests/Feature/EventTypeTest.php` (pre-existing).

## Output
Reply with a JSON object `{"verdict": "GREEN"|"RED", "findings": [{"id": "F1", "severity": "high|medium|low", "file": "...", "line": N, "claim": "...", "repro": "<failing test or command output that proves it>"}]}`. A finding without a reproduction is at most plausible: mark it `"repro": null`. Do not report style, naming or pre-existing issues. No findings is a valid answer.
