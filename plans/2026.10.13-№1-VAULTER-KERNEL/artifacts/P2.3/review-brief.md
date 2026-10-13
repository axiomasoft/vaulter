# Review brief — PLAN3 P2.3 «Инвентарь идентичностей» (read-only)

You are a fresh, independent reviewer. You may read everything and run tests/commands; the repository is mounted
read-only, do NOT try to edit or create files in it. Report findings; the executor repairs.

## What was built (uncommitted working tree, repo root = cwd)
- `scripts/inventory/lib/Identities.php` — shared format, `IdentityOwners` (hand-written owner rules: exact > longest prefix > glob; stale/unclassified = error), `IdentityGenerator`, `AstFiles`.
- `scripts/inventory/lib/Identities/{Schema,Config,Cli,Http,Protocol}.php`, `scripts/inventory/generators/identities-*.php`, `scripts/inventory/boot/routes.php` (separate-process Testbench boot).
- `tests/Repo/Arch/Fixtures/identities/{schema,config,cli,http,protocol}.json` (generated) and `identities/owners/*.json` (hand-written), `tests/Repo/Arch/IdentitiesTest.php`, `tests/suites.json` (+1 file).
Item contract: `plans/2026.10.13-№1-VAULTER-KERNEL/phases/P2/P2.3.json` (scope, rules, guidance, validation) and `knowledge/p2-design.json` section `identities` — read them first. Do not read plans from code under review (tests/generators must not).

## What to check (correctness only, in this order)
1. Does each generator really implement the item guidance (schema: 48 presets × 3 engines compared, variants by axis, FK pgsql=mysql with `engine_diff` instead of smoothing, `original_diff`; config: leaves, env, registerDefaults, publish tags; cli: Parser::parse; http: separate-process boot per feature flag + OpenAPI match; protocol: events, problem codes, morph, serialized FQCN, manual formats with existing source symbol)?
2. Can a fixture stay green while the underlying truth changed (a hole in the check)? Examples to try: a column type changing on one engine only, a new route, a renamed env var, a deleted owner rule, a stale rule, a format whose source symbol disappears.
3. Determinism: byte-identical output on repeated runs; no time, absolute path or line number in fixtures; sorted items.
4. Product untouched: `git diff --quiet 35718fd2 -- packages/*/src packages/*/database packages/*/config packages/*/routes packages/*/composer.json`.
5. Owner correctness against `plans/…/knowledge/architecture.json` (#packages, #ownership-map, D23/D28/D29): wrong owners in `identities/owners/*.json`.

Run only targeted checks: `scripts/php84 php scripts/inventory/inventory.php identities-schema identities-config identities-cli identities-http identities-protocol --check` and `scripts/t sqlite -p repo -- tests/Repo/Arch/IdentitiesTest.php`. No full matrix, no mutation, no coverage, no load tests.

## Output (your final message, nothing else after it)
`VERDICT: PASS` or `VERDICT: FINDINGS`, then for each finding: `file:line`, severity (high|medium|low), one-sentence defect, and a REPRODUCTION (a command and its output, or a failing test). A finding without a reproduction is at most `plausible` — say so. Do not report style/Pint/PHPStan nits (they are green) and do not propose rewrites.
