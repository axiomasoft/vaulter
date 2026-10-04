# P1.9 — Middleware stacks and local resource resolution

Implemented authenticated/public/hub stacks, automatic request metrics, legacy authenticated fallback, local swappable storage/node resolution and binding priority. Removed global route binders. Protected routes default to auth:sanctum; public shares and signed hub calls retain their own token/signature gates and throttles. Existing paths and controller model arguments are preserved. Configuration, installation, upgrade and changelog are updated. The core API golden now freezes Config accessors and HttpResourceResolver.

V25: 4 passed; unauthenticated protected request 401, Sanctum actor 200, anonymous valid share 200, valid signed hub JWT 200 with allowed=true. V26: 3 passed; host model resolves, missing storage/node 404 with drive_not_found/node_not_found. Both specified negative controls exit 1 and restore exact source bytes; focused suites green after restoration.

15 effective package gates GREEN (validation-effective.json): core 596 passed/11 skipped/17 risky; drive 454 passed/8 skipped/2 risky; documents 249 passed/1 skipped; media 128 passed/9 skipped/4 risky; filament 76 passed/1 skipped. Conditional skips and existing risky cases are not claimed executed. Testing uses explicit APP_ENV=testing, SQLite :memory:, serial execution and existing fake filesystem fixtures.

Initial core PHPStan rejected three inferred types. Corrected by reading the mixed parameter array, typing the next Closure and moving the kernel check into a contract-typed helper; no ignores or lowered thresholds. Core Pint/PHPStan/Pest rerun GREEN. API golden regenerated under the approved AD31 change.

Sanctum ^4.0 installed with Laravel 13.34.0. Full Composer dry-run with Laravel ^12.0 succeeds (composer-sanctum-l12-dry-run.log); partial attempts failed because L13 dependencies were locked, not because Sanctum was incompatible. addToMiddlewarePriorityBefore verified in installed sources and official Laravel 12.x/13.x Kernel sources. This is resolver compatibility plus source verification, not a full L12 test run.

review: grok/grok-4.7 GREEN. Fresh session 07c84774-8ce8-4db4-ad6d-71d6db72a04e, actual model grok-4.7-build, high effort, one model call. Static inline diff review; no live reviewer validation is claimed. Verbatim verdict: grok-review.md; route/candidate/raw response preserved.

write-site: pre_mutation: allow · pre_final: allow
 No push, publication, release or unrelated owner change is authorized.
