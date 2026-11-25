# Vaulter family: engineering roadmap proposals

Status: proposal, nothing here is decided. Scope: the base repository (`axiomasoft/vaulter`) and the
four private satellites (`vaulter-ai`, `vaulter-collaboration`, `vaulter-editor-pro`,
`vaulter-sheets`), plus the shared Packman and AzGuard dependencies.

Every finding below was checked against the `main` branches on 2026-11-25. Each proposal states the
problem, the change, why it is worth doing, a rough cost, and a priority:

- **P0**: blocks reliable review or release today; do first.
- **P1**: removes a recurring cost or a class of silent breakage; schedule next.
- **P2**: improves consistency; do when the area is touched anyway.

## Summary

| # | Proposal | Priority | Cost |
|---|----------|----------|------|
| [R1](#r1-ci-that-runs-on-every-repository) | CI that runs on every repository | P0 | S (an org decision) |
| [R2](#r2-a-deterministic-static-analysis-entry-point) | A deterministic static-analysis entry point | P0 | S (done in base) |
| [R3](#r3-lock-the-development-toolchain) | Lock the development toolchain | P0 | S |
| [A1](#a1-an-explicit-cross-repository-version-contract) | An explicit cross-repository version contract | P0 | M |
| [R4](#r4-one-packman-and-azguard-pin-for-the-family) | One Packman and AzGuard pin for the family | P1 | S |
| [R5](#r5-one-release-flow) | One release flow | P1 | M |
| [A6](#a6-move-planning-material-out-of-the-public-repository) | Move planning material out of the public repository | P1 | S (decided) |
| [A7](#a7-one-safe-redirect-helper-in-the-base) | One safe redirect helper in the base | P1 | S |
| [A8](#a8-datetime-instead-of-timestamp-columns-on-mysql) | DATETIME instead of TIMESTAMP columns on MySQL | P1 | S |
| [A9](#a9-a-writer-for-the-sheets-projection-tables) | A writer for the sheets projection tables | P1 | M |
| [A10](#a10-one-database-engine-type-instead-of-driver-name-strings) | One database-engine type instead of driver-name strings | P1 | S / M |
| [A12](#a12-one-spelling-per-input-no-synonym-keys-or-values) | One spelling per input: no synonym keys or values | P1 | S |
| [B1](#b1-move-the-engineering-rails-into-packman-devtools) | Move the engineering rails into `packman-devtools` | P1 | M |
| [B2](#b2-one-ecosystem-checkout-step) | One ecosystem checkout step | P1 | S |
| [B3](#b3-shared-root-configuration-with-a-drift-check) | Shared root configuration with a drift check | P1 | M |
| [B4](#b4-one-local-entry-point-that-builds-what-the-php-tests-need) | One local entry point that builds what the PHP tests need | P1 | M |
| [T1](#t1-contract-tests-in-every-satellite-that-implements-a-base-spi) | Contract tests in every satellite that implements a base SPI | P1 | S |
| [T4](#t4-retire-or-repair-the-test-name-baseline) | Retire or repair the test-name baseline | P1 | S |
| [T5](#t5-a-contract-test-for-the-node-converter-error-codes) | A contract test for the Node converter error codes | P1 | S |
| [D1](#d1-english-only-documentation-with-the-same-tree-in-every-repository) | English-only documentation with the same tree everywhere | P1 | M |
| [D3](#d3-contributor-documentation-in-every-repository) | Contributor documentation in every repository | P1 | S |
| [X1](#x1-editor-pro-exchange-recovery-must-re-dispatch-or-fail) | editor-pro: exchange recovery must re-dispatch or fail | P1 | S |
| [X3](#x3-sheets-stream-the-zip-entry-checks) | sheets: stream the ZIP entry checks | P1 | S |
| [S1](#s1-the-projector-contract-and-its-measured-payoff-extends-a9) | The projector contract and its measured payoff | P1 | M |
| [S2](#s2-one-licence-allowlist-instead-of-four-denylists-input-to-b1) | One licence allowlist instead of four denylists | P1 | S |
| [S3](#s3-a-first-party-clamav-scanner-in-the-base) | A first-party ClamAV scanner in the base | P1 | S |
| [S5](#s5-editor-features-still-missing-against-tiptap-pro) | Editor features still missing against TipTap Pro | P1–P3 | M–L each |
| [S6](#s6-remove-the-archive) | Remove the archive | P1 | S |
| [R6](#r6-npm-only-and-a-reviewed-install-script-allowlist) | npm only, and a reviewed install-script allowlist | P2 | S |
| [R7](#r7-pin-runners-and-service-images-and-enforce-it) | Pin runners and service images, and enforce it | P2 | S |
| [R8](#r8-ci-debt-inherited-from-main) | CI debt inherited from main | P1 | S / M |
| [A2](#a2-one-registration-model-for-every-php-package) | One registration model for every PHP package | P2 | M |
| [A3](#a3-one-error-catalogue-per-package) | One error catalogue per package | P2 | M |
| [A4](#a4-typed-options-in-the-satellites) | Typed options in the satellites | P2 | M |
| [A5](#a5-public-surface-snapshots-in-the-satellites) | Public surface snapshots in the satellites | P2 | S |
| [A11](#a11-a-typed-payload-kind-in-the-managed-codec-spi) | A typed payload kind in the managed-codec SPI | P2 | S |
| [B5](#b5-one-javascript-lint-and-format-setup) | One JavaScript lint and format setup | P2 | S |
| [T2](#t2-the-same-test-harness-shape-everywhere) | The same test harness shape everywhere | P2 | M |
| [T3](#t3-scheduled-mutation-testing-on-the-domain-core) | Scheduled mutation testing on the domain core | P2 | M |
| [D2](#d2-one-documentation-site-for-the-family) | One documentation site for the family | P2 | M |
| [H1](#h1-no-planning-codes-in-code-tests-and-comments) | No planning codes in code, tests and comments | P2 | M |
| [H2](#h2-the-same-gitignore-agent-block-everywhere) | The same `.gitignore` agent block everywhere | P2 | S |
| [X2](#x2-editor-pro-the-overlap-detector-is-a-stateful-singleton-nobody-shares) | editor-pro: the overlap detector is a stateful singleton | P2 | S |
| [X4](#x4-editor-pro-unpinning-must-not-clear-an-earlier-keep-forever) | editor-pro: unpinning must not clear an earlier keep-forever | P1 | S |
| [X5](#x5-ai-drop-the-core-config-keys-nothing-reads) | ai: drop the core config keys nothing reads | P2 | S |
| [A13](#a13-ai-backed-enums-for-command-step-and-decision-states) | ai: backed enums for command, step and decision states | P2 | S / M |
| [A14](#a14-stricter-typescript-flags-in-the-satellites) | Stricter TypeScript flags in the satellites | P2 | M |
| [S4](#s4-the-unused-blob-encryption-column-implement-or-drop) | The unused blob encryption column: implement or drop | P2 | S / L |
| [Q1](#q1-a-sorted-index-and-a-seekable-cursor-for-folder-listings) | A sorted index and a seekable cursor for folder listings | P1 | S |
| [Q2](#q2-seekable-keyset-cursors-everywhere) | Seekable keyset cursors everywhere | P1 | S |
| [Q4](#q4-drop-indexes-that-duplicate-a-key-prefix) | Drop indexes that duplicate a key prefix | P1 | S |
| [V1](#v1-realtime-identity-from-the-token-not-the-client) | Realtime identity from the token (id done; display name open) | P1 | S |
| [V2](#v2-a-per-peer-intent-budget-in-sheets-realtime) | A per-peer intent budget in sheets realtime | P1 | S |
| [Q3](#q3-indexed-substring-search-on-names) | Indexed substring search on names | P2 | S (a deployment decision) |
| [Q5](#q5-sheets-export-replay-without-hashing-the-source-blob) | sheets: export replay without hashing the source blob | P2 | S |
| [V3](#v3-share-passwords-out-of-query-strings) | Share passwords out of query strings | P2 | S (resolved) |
| [Q6](#q6-ai-resource-ids-in-the-text-projection-heads) | ai: resource ids in the text projection heads | P1 | M |
| [V4](#v4-ai-commit-checks-the-permission-of-its-command) | ai: commit checks the permission of its command | P1 | S |
| [V6](#v6-ai-an-allowlist-sanitizer-for-streamed-html) | ai: an allowlist sanitizer for streamed HTML | P1 | S |
| [V7](#v7-a-forged-or-stale-cursor-is-a-500-not-a-400) | A forged or stale cursor is a 500, not a 400 | P1 | S |
| [Q7](#q7-push-chat-events-instead-of-a-php-worker-per-open-tab) | Push chat events instead of a PHP worker per open tab | P2 | M |
| [Q8](#q8-seekable-pages-for-the-scan-backfill-and-rescan) | Seekable pages for the scan backfill and rescan | P2 | S |
| [Q9](#q9-one-read-snapshot-of-the-drive-per-operation) | One read snapshot of the drive per operation | P3 | S (an owner decision) |
| [V5](#v5-ai-a-resource-scoped-tool-port-only-reaches-its-resources-resolved) | ai: a resource-scoped tool port only reaches its resources | — | resolved |

Cost: **S** is up to a day, **M** a few days, **L** more than a week.

## Reproducibility and CI

### R1. CI that runs on every repository

**Problem.** Every recent workflow run on `axiomasoft/*` (the base and all four satellites) ends as
a failure within 3 to 6 seconds, with jobs that have zero steps. Nothing executes: this is an
account or billing condition, not a broken workflow. The base gets real CI only through the
`axioma-studio/vaulter-mirror` copy; the satellites get none. Their `CI gate` jobs exist but have
never gated anything.

**Update.** The repositories were made public for the CI work, which lets Actions start on
`axiomasoft/*` for now. They go private again afterwards, so the decision below is only postponed.

**Proposal.** Before they go private, pick one of: enable Actions billing for the `axiomasoft`
organization (simplest); a mirror per satellite, as for the base; or one self-hosted runner pool
shared by the family. Then make
`CI gate` a required status check on `main` in every repository.

If mirrors are chosen, use one template for all of them: a mirror repository per satellite with
Actions enabled, Docker available on the runners (the PostgreSQL and MySQL service containers), and
one repository secret (for example `VAULTER_READ_TOKEN`) with read access to `axiomasoft/vaulter`,
`axiomasoft/vaulter-collaboration` and `axiomasoft/packman`, which the sibling checkout (B2) sends as
an `Authorization` header.

**Rationale.** Without this, every other proposal here is checked only on a laptop. The satellites
have already drifted (see R3 and B4) precisely because nothing runs.

**Cost.** S of engineering once the organization decides. Mirrors add a sync job per repository and
a second place to look at results, so billing or a shared runner is preferable.

### R2. A deterministic static-analysis entry point

**Problem.** PHPStan on a fresh base checkout reported anywhere from about 35 to 135 errors from run
to run. The cause was not the PHPStan release: Larastan boots the Testbench skeleton
(`vendor/orchestra/testbench-core/laravel`) to read console command definitions, and on a fresh
install that skeleton has no `vendor` link and no `bootstrap/cache/{packages,services}.php`.
Parallel PHPStan workers race to create them, and a worker that loses the race silently falls back
to untyped command options, which surfaces as a varying set of errors.

**Proposal.** Done in the base on `refactor/polish`: `composer analyse` and the CI quality job run
`vendor/bin/testbench package:discover` before PHPStan, so the skeleton exists before any worker
starts. Apply the same step to every satellite that runs PHPStan with Larastan on Testbench.

The per-package Quality jobs had a second gap: Composer never discovers the root package, so the
package under analysis was not booted and Larastan again saw untyped command options. A
`testbench.yaml` that registers the package's own provider fixes it (done in drive, documents and
media); every package that ships console commands needs one.

**Rationale.** A gate that changes its verdict between runs on the same commit cannot be required.
After the fix the base gives the same result on every fresh install (0 errors at level max).

**Cost.** S per repository.

### R3. Lock the development toolchain

**Problem.** No repository tracks `composer.lock`. Tool versions float inside ranges, and the ranges
disagree. On the `refactor/polish` branches the base and collaboration require
`phpstan/phpstan ^2.3.6`, sheets and editor-pro `~2.3.7`, and ai still `^2.2.16 <2.3.6`; on `main`
the ranges differ again. The same code can pass in one repository and fail in another
only because of the resolved analyser version. GitHub Actions are already pinned by commit in the
base (`release/tool-pins.json`), but the PHP and Node tools are not.

**Proposal.** Track `composer.lock` and `package-lock.json` for each repository's root development
workspace. The root `composer.json` is the development workspace, not a published package (the
split packages are published from `packages/*`), so a lock file there does not constrain consumers.
Use one PHPStan, Larastan, Pint, Rector and Pest range across the family and bump it in one pull
request per repository.

The shared Rector set shows why: `PackmanRectorSet` in `packman-devtools` 0.1.1 references
`SetList::EARLY_RETURN`, a constant the installed Rector 2 no longer has, so `rector process` aborts
with "Undefined constant" before it looks at a single file in every repository that uses the set.
Fix it once in Packman (move to the current set or the explicit rules) and release it, instead of
per-repository workarounds.

**Rationale.** It makes a red build reproducible and a tool upgrade a reviewable change, instead of
a side effect of whatever was released last night.

**Cost.** S. The alternative (exact pins in `require-dev` without a lock file) is weaker, because
transitive versions still float.

### A1. An explicit cross-repository version contract

**Problem.** The satellites require base packages at `1.0.x-dev` and resolve them from a sibling
checkout of the base `main`. A change in the base can break four repositories without anyone
noticing, and a satellite build cannot be reproduced later because "base `main`" moves.

**Proposal.** Each satellite records the commits it was tested against in a small manifest (for
example `release/ecosystem.json` with base, collaboration and sheets commits), and a pull request
bumps it. Once satellite CI runs (R1), add a downstream canary: a scheduled base workflow that runs
the satellite suites against the base `main`.

**Rationale.** The manifest makes every satellite build reproducible and every base upgrade a
visible change; the canary finds breakage before the bump rather than after.

**Cost.** M: the manifest and the bump are small; the canary needs cross-repository read access.

### R4. One Packman and AzGuard pin for the family

**Problem.** editor-pro pins Packman 0.1.2; the base, ai, collaboration and sheets pin 0.1.1. Only
the base pins AzGuard (by commit, in `scripts/ci/checkout-ecosystem.sh`).

**Proposal.** Keep a single source for these pins (the manifest from A1, or the base
`composer.json` path-repository versions, which the checkout script already reads), and have the
satellites read it.

**Rationale.** Two Packman versions in one family mean two sets of gate behaviour and two sets of
generated artefacts.

**Cost.** S.

### R5. One release flow

**Problem.** The base has changesets for npm, a monorepo split, a qualification checklist and tags.
The satellites have a `release/packages.json` with the same schema, but no tags, no split workflow
and, except for one collaboration changelog, no `CHANGELOG.md`.

**Proposal.** One release workflow shape everywhere: a tag per release line, split, ZIP and npm
tarball built from `release/packages.json`, changesets for npm packages, a Keep a Changelog file per
published package, and release notes drafted from Conventional Commits.

**Decided rule: intra-repository dependencies use `self.version`.** Packages that live in the same
repository require each other as `self.version` and are released together on one version line;
versions will not stabilize for a while, so independent per-package versions would only add
bookkeeping. The base already follows this in all eight packages. ai and editor-pro still require
their sibling packages as `^1.0` and move to `self.version` on their branches; the split workflow
tags every package of a repository with the same version. Dependencies on another repository of the
family stay ranges (see A1).

**Rationale.** Integrators then upgrade every package the same way, and a release can be reviewed
from one checklist.

**Cost.** M, mostly porting the base workflow and its policy checks.

### R6. npm only, and a reviewed install-script allowlist

**Problem.** sheets still carries pnpm leftovers (`pnpm-workspace.yaml` and a `pnpm` block in
`package.json`) next to an npm lockfile; its Univer patch already runs from a `postinstall` script.

**Proposal.** Remove the pnpm leftovers, keep the patch as the npm `postinstall` script, and order
npm workspaces topologically (or build them from one explicit script). Review which dependency
install scripts are allowed to run and keep that list in the repository.

**Cost.** S.

### R7. Pin runners and service images, and enforce it

**Problem.** The base pinned its service images by digest but ran every job on `ubuntu-latest`;
the satellites pin `ubuntu-24.04` but collaboration, sheets and one editor-pro job pull
`postgres:<tag>` without a digest. The satellite work also found a Redis image that is pinned
differently from the base, and Dockerfile base images without a digest in collaboration.

**Proposal.** Done for runners in the base on `refactor/polish` (all jobs on `ubuntu-24.04`). Pin the
remaining service images by digest, and extend `scripts/ci/workflow-policy.py` to reject `-latest`
runner labels and undigested images, then run the same policy in every repository (see B1).

**Rationale.** A moving image changes the toolchain under every job at once, with no commit to
bisect.

**Cost.** S.

### R8. CI debt inherited from main

**Problem.** On `refactor/polish` these jobs are red for causes that came in with `main`, not with
the polish work. They are listed so nobody reads them as regressions of this branch:

- *Repo harness, PHP 8.5.* `WorkflowPolicyTest` calls `scripts/php84`, which requires PHP 8.4, on
  the PHP 8.5 leg added with the 8.5 matrix (H-01).
- *Repo harness.* `BoundaryScanTest` (scan I4), `SurfaceRulesTest` ×2 (I13) and the aggregate
  `inventory.php --check` in `InventoryHarnessTest`: the committed allowlists no longer match the
  source merged on 23 November (new container probes in core tenancy, modules and CORS middleware,
  webhooks and documents; stale entries in the package registrars; a documents enum in a
  `CollaborativeStateTransition` signature).
- *Fast / integration.* `OutboxRelayTest` ×3, `OperationsUseRunnerTest`,
  `ProvidersDeclareOnlyTest`, `ServiceLocatorRatchetTest`, `IdempotencyContractTest` and
  `OwnershipRegistryTest` (migration numbering after H-01).

**Proposal.** Fix each at its source, not by recounting the allowlists: either the new code moves
to constructor injection and the probes disappear, or each entry gets a reason, a category and a
removal version, reviewed by the owner of the series that added it. The PHP 8.5 leg either skips
the 8.4-only shim or `scripts/php84` accepts the job's interpreter.

**Rationale.** A gate that is red for known reasons hides new failures; `CI gate` cannot become a
required check until these are gone.

**Cost.** S for the 8.5 leg and the allowlists, M for the integration ratchets.

## Shared tooling

### B1. Move the engineering rails into `packman-devtools`

**Problem.** Each satellite has an inline "stop-cranes" job in `ci.yml` of 360 to 439 lines of
shell and Python. Pairwise they are 79 to 89 % identical. Three near-identical PHPStan SQL rules
exist (`VaulterSqlStopRail`, `VaulterAiSqlStopRail`, `EditorProSqlStopRail`), and `unindexed-fks.sql`
exists in four copies under two different paths (`scripts/ci/` and `scripts/ci/sql/`). The
constraint scanners themselves have diverged into separate scripts: collaboration has
`scripts/ci/stop-cranes.py`, sheets and the base each have their own `scripts/ci/engineering-rails.py`.

**Proposal.** Ship these from `packman-devtools`, which already provides `phpstan-base.neon` and the
Rector set: one rails command with a per-repository configuration file (denylist, allowed licences,
extra bans) that replaces the per-repository scanner scripts, one configurable PHPStan rule, and
one SQL gate. Each repository keeps only its
configuration.

**Rationale.** A fix to a rail currently has to be made five times, and the copies have already
diverged.

**Cost.** M: one Packman release plus a small migration per repository. It removes on the order of
1,500 duplicated lines.

### B2. One ecosystem checkout step

**Problem.** The sibling checkout logic (`fetch_dependency`) is defined seven times: once in the base
`scripts/ci/checkout-ecosystem.sh` and six more times inline in the ai, editor-pro and sheets
workflows.

**Proposal.** Use the base `scripts/ci/checkout-ecosystem.sh` in every satellite instead of the
inline copies: it reads the Packman tag from the `composer.json` path repository, pins AzGuard by
commit, and sends the token as an `Authorization` header instead of embedding it in the clone URL.
Later, drive it from the A1 manifest. If cross-repository actions are not available for private
repositories, vendor the script and check that the copies match.

**Cost.** S.

### B3. Shared root configuration with a drift check

**Problem.** `pint.json`, the Rector skeleton, the PHPStan include, the `.gitignore` agent block and
the Node and TypeScript ranges are copied by hand and drift. In the base alone, the six package
`pint.json` files had lost the root `imports_order` rule.

**Proposal.** Keep the source of truth in `packman-devtools` (it already has `phpstan-base.neon`),
let each repository extend it, and add a `packman gate` check that fails when a repository diverges.

**Cost.** M.

### B4. One local entry point that builds what the PHP tests need

**Problem.** The base has `scripts/t` and a Makefile. The satellites only have `composer test`, and
on a fresh checkout their PHP suites fail until the npm workspaces are built: editor-pro's exchange
tests fail with "DOCX CLI binary not found", and the sheets calculation tests (also run from ai)
fail until the sheets engine is built. After `npm ci` and the workspace builds, the same suites pass.

**Proposal.** In every repository: `make setup` (siblings, `composer install`, `npm ci`, workspace
builds in dependency order, Playwright browsers where needed), `make test`, `make quality` and
`make test-pg` (PostgreSQL on a free local port). Port `scripts/pg-local.sh` from the base. Declare the build as a `composer prepare` script that
`make setup` and CI run before `composer test`, and have `composer test` fail early with a clear
message when a required build output is missing.

**Rationale.** A contributor, and CI, should get a green run from a documented sequence, not from
knowing which Node packages a PHP test silently depends on.

**Cost.** M.

### B5. One JavaScript lint and format setup

**Problem.** No repository in the family runs ESLint, Prettier or Biome on its TypeScript and Vue
sources.

**Proposal.** One shared configuration (Biome, or ESLint flat config plus Prettier) and a `node:lint`
CI lane in every repository.

**Cost.** S, mostly mechanical formatting churn in one commit per repository.

## Architecture

### A2. One registration model for every PHP package

**Problem.** Three models coexist. The base and collaboration register through Packman
(`RegistersPackageRegistrars` / `OffersModules`); sheets and editor-pro use `OffersModules` with
manual configuration; ai uses plain Laravel service providers, the largest at 134 and 147 lines.
Only sheets has a `packman.json` among the satellites.

**Proposal.** Every package ships a module plus a package registrar, keeps its provider thin, and
every repository has a `packman.json` so `packman gate` can check the layering: base kernel, base
capabilities, then collaboration, sheets and editor-pro, then ai.

Make the providers uniform while doing it:

- one name per package, `Vaulter<Package>ServiceProvider` (the base, collaboration and sheets follow
  it; ai has six differently named providers and editor-pro two);
- one rule for `singleton` versus `singletonIf`: `singletonIf` only for bindings a host is meant to
  replace, `singleton` for internals (today the base mixes 26 and 7, ai 67 and 11, editor-pro uses
  only `singleton`);
- a shared test that every provider registers and boots without `APP_KEY` and without optional
  services, with heavy dependencies (SDK clients, engines) registered lazily;
- architecture tests for the package layers in every repository, so a lower layer cannot import a
  higher one, next to the `packman gate` check.

**Cost.** M in ai, S elsewhere.

### A3. One error catalogue per package

**Problem.** The base pattern is an `ErrorCode` enum plus `ProblemDefinitions`. collaboration and
editor-pro follow it; ai has an `ErrorCode` enum but no problem definitions; sheets has neither.

**Proposal.** One catalogue per package, exported through the Packman error-codes check and merged
into one family catalogue, so hosts get one `vaulter:errors` listing (and one generated reference
page). Each problem `type` URL points at the documentation of the package that owns the code
(per-source type URLs), instead of every package pointing at the base error page.

**Cost.** M.

### A4. Typed options in the satellites

**Problem.** sheets reads configuration through 31 raw `config()` calls outside its config files;
the base uses closed-schema options objects.

The shallow-merged config arrays also misbehave in sheets:

- the published file and the code fallbacks disagree: `max_snapshot_bytes` is 32 MiB in the file and
  7 MiB in `WorkbookCodec`; `max_message_bytes` is 64 MiB in the file and in `ExchangeWire`, but 8 MiB
  in `RuntimeWire`;
- some keys are read but missing from the config file, so they can only be set by knowing the code:
  `locale`, the top-level `queue_connection` (the file only has `exchange.queue_connection`) and
  `realtime.checkpoint_interval`;
- the import size limit is configured through different environment variables in the PHP package and
  in the Node engine;
- the package config is merged at the top level only, so overriding one nested key in a host drops
  its sibling keys back to the code fallbacks.

**Proposal.** Typed settings objects plus an exported `config.schema.json` per package, built from one
source of defaults, so the `vaulter:doctor` configuration check also covers the satellites. Add a
shared test that every published configuration key is read somewhere, so dead keys cannot ship.

**Cost.** M in sheets, S elsewhere.

### A5. Public surface snapshots in the satellites

**Problem.** The base snapshots its PHP and npm public surface; among the satellites only
collaboration snapshots its Vue API.

**Proposal.** `#[Api]` markers, `packman php-api --check` and an API extractor for the npm packages
in every repository.

**Rationale.** It is what makes "no breaking change" reviewable once the private packages have
consumers.

**Cost.** S per repository.

### A7. One safe redirect helper in the base

**Problem.** vaulter-ai had an open redirect: a `redirect_to` value taken from the request was
followed without checking that it stays on the host application. After that fix it still had a
second path out: when `redirect_to` was rejected, the fallback `url()->previous()` follows the
`Referer` header, so a form posted from a foreign site redirected back to it (fixed on the ai
branch in `b14c1b2`).

**Proposal.** One helper in the base core that accepts only relative paths or URLs on the
configured application host, and applies the same rule to every fallback (`url()->previous()`, the
`Referer` header), falling back to a fixed in-app route instead. Every package that redirects after
an action uses it; a shared test covers `//host`, `\\host`, scheme-relative and foreign-Referer
cases.

**Cost.** S.

### A8. DATETIME instead of TIMESTAMP columns on MySQL

**Problem.** collaboration creates its lease and timing columns as `TIMESTAMP(6)` on MySQL
(`CollaborationSchema`). MySQL `TIMESTAMP` ends in January 2038 and is converted through the session
time zone.

**Proposal.** Use `DATETIME(6)` stored in UTC across the family, and add a schema rail (B1) that
rejects `TIMESTAMP` columns on MySQL. There are no users yet, so the migration can be changed in place.

**Cost.** S.

### A9. A writer for the sheets projection tables

**Problem.** vaulter-sheets creates the `v_sheets_projection_*` tables (builds, heads, sheets, tables,
columns, rows, cells) and runs a pre-publish check over them (`scripts/ci/sql/sheets-checks.sql`), but
no code in any repository writes to them: neither sheets nor vaulter-ai has a projector. The tables
stay empty, and the check passes on nothing.

**Proposal.** Keep the tables and implement the projector. Decide first:

- the owning package: sheets (the projection is a read model of its own workbook format, useful to
  search, exports and the AI alike) or ai (if the AI tools are its only reader). Sheets is the
  natural owner, with ai as a consumer;
- when it runs: synchronously on save (always consistent, but adds latency to every save) or as a
  queued job keyed by the revision (the save stays fast, readers see the previous build until the new
  one is published; the `builds`/`heads` tables already fit this model).

Then write the contract down: which revision a build reflects, how a head moves atomically from one
complete build to the next, and what readers see while a build is pending or failed. Cover it with
tests: a build per saved revision, an atomic head switch, idempotent rebuilds, and the pre-publish
check failing on a real inconsistency instead of on empty tables.

**Rationale.** A read model nobody writes is worse than none: it looks finished, and the check that
guards it gives a false green.

**Cost.** M.

### A10. One database-engine type instead of driver-name strings

**Problem.** Engine-specific code compares `getDriverName()` with string literals. Laravel reports
MariaDB as `mariadb`, so every `=== 'mysql'` check silently treats MariaDB as "not MySQL".
collaboration had this bug (fixed on its branch in `a6703e9`), and the base had it in
`NodeReadRepository::siblingQuery()`, where the locking sibling read after the tree lock did not run
on MariaDB (fixed on `refactor/polish`). In the base about a dozen checks of this shape remain, all
in schema code: `DocumentsSchema` (five), `DriveSchema` (five), `MediaSchema` and two in
`SchemaHelpers`. Some may be deliberate (MariaDB DDL differs), but the code cannot say which.

**Proposal.** A `DatabaseEngine` enum (`Sqlite`, `Pgsql`, `Mysql`, `Mariadb`) in core with
`VaulterDatabase::engine()` and intent methods (`isMysqlFamily()`, `hasRepeatableReadSnapshots()`,
`supportsDeferredUniqueness()`, ...), used by every package of the family, plus a PHPStan rule (B1)
that rejects comparing `getDriverName()` with a literal. Each remaining check is then reviewed once
and written as either the family or the exact engine.

**Cost.** S for the enum and the rule, M for the review of the schema checks (they touch the
in-flight schema series, so after it lands).

### A11. A typed payload kind in the managed-codec SPI

**Problem.** `ManagedDocumentCodec::replacePayloadReference(string $kind, ...)` receives the magic
strings `'working'` and `'revision'`; documents passes them as literals in four places, and the
sheets `WorkbookCodec` compares `$kind === 'working'` and builds its pin owners as
`'sheets.'.$kind`. A typo on either side compiles and silently pins nothing.

**Proposal.** A backed enum `PayloadKind` (`Working = 'working'`, `Revision = 'revision'`) in the
documents contracts, taken by the SPI method; sheets uses `$kind->value` for the pin owner. One
coordinated change in the base and sheets (the SPI has no other implementers).

**Cost.** S.

### A12. One spelling per input: no synonym keys or values

**Problem.** vaulter-ai accepts several spellings for one thing: `AgenToolsLlmPort` reads the
response format from six synonymous keys, and the `decide` endpoint accepts `approve`, `approved`,
`reject` and `rejected`. These are compatibility layers, and there are no users to be compatible
with; every synonym is another branch to test and to document.

**Proposal.** One key and one backed enum per input (for `decide`: `Decision::Approve`,
`Decision::Reject`, validated with `Rule::enum()`), and a 422 for anything else. Apply the same rule
in review across the family.

**Cost.** S, after the in-flight ai series that touches these files.

### A13. ai: backed enums for command, step and decision states

**Problem.** Three core DTOs carry their lifecycle as plain strings: `CommandOutcome::$state`
(`running`, `committed`, `rejected`), `OfficeRunStep::$state` (`dispatched`, `waiting`, `finished`,
`failed`, `verified`) and `StoredProposal::$decision` (`pending`, `approved`, `rejected`). Callers
compare them with literals (`$outcome->state === 'rejected'`, `$proposal->decision !== 'approved'`)
in the commit path, the step coordinator, the approved-command job and the workspace views, so a
typo compiles and PHPStan cannot check that a `match` covers every state.

**Proposal.** `CommandState`, `StepState` and `ProposalDecision` backed enums whose values are the
stored strings (no migration), constructed once in the repositories with `::from()`, and exhaustive
`match` at the decision points. The wire format keeps `->value`. The files involved (`Commit`,
`Runs`, `Proposals`) are being changed by the in-flight ai series, so this lands after it.

**Cost.** S per enum, M together.

### A14. Stricter TypeScript flags in the satellites

**Problem.** The Vue and Node packages compile with `strict`, but not with the flags that catch
unchecked indexing and `undefined` passed to optional properties. Measured on `refactor/polish`
with each flag turned on alone:

| Package | `noUncheckedIndexedAccess` | `exactOptionalPropertyTypes` | `noImplicitOverride` |
|---------|---------------------------|------------------------------|----------------------|
| ai `vue` | 20 errors | 13 | 0 |
| editor-pro `vue` | 105 | 52 | 0 |
| editor-pro `docx` | 560 | 28 | 0 |

Most of the errors are in files the in-flight editor and ai series are changing.

**Proposal.** Turn on `noImplicitOverride` now (it costs nothing), then `exactOptionalPropertyTypes`,
then `noUncheckedIndexedAccess` package by package once those series land; each flag stays on once
its package is clean.

**Cost.** S for the first two flags, M for `noUncheckedIndexedAccess` in docx.

### A6. Move planning material out of the public repository

**Problem.** The public MIT base repository carries `plan/` and `audits/`: Russian planning
documents, orchestration scripts and review prompts. They are not product documentation, and they
are the main source of planning codes in comments (H1).

**Proposal.** After the current migration series lands, move them to the private workspace
repository and keep only ADRs in `docs/adr`.

**Decided.** The repositories are public only for the duration of the CI work and go private again
afterwards, so the history does not need rewriting; deleting the folders after the migration series
is enough (see S6).

**Cost.** S.

## Documentation

### D1. English-only documentation with the same tree in every repository

**Problem.** The base kept a partial Russian mirror (`docs/ru`, 69 files) that was always behind the
English pages. collaboration still has a Russian `docs/ru`, and all four sheets documentation
pages are in Russian.

**Proposal.** Done in the base on `refactor/polish` (the Russian tree and its mirror checks are
removed). Use one English tree in every repository with the same sections: getting started,
concepts, guides, reference, security, ADRs.

**Cost.** M, mostly in sheets.

### D2. One documentation site for the family

**Problem.** Only the base deploys a VitePress site. Integrators of the private packages have no
single place to read.

**Proposal.** Add a "Private extensions" section built from the satellites' `docs/` at release time
(a private deployment or a PDF bundle). Generate the reference pages (configuration, errors,
commands, PHP API) from the Packman exporters in every repository instead of writing them by hand.

**Cost.** M.

### D3. Contributor documentation in every repository

**Problem.** The base `CONTRIBUTING.md` pointed at the old organization and omitted the sibling
checkout that `composer install` needs (fixed on `refactor/polish`). The satellites have no
contributor guide.

**Proposal.** CONTRIBUTING (sibling layout, setup, test lanes, local ports), SECURITY, and the commit
convention (English Conventional Commits, package scope, a body that explains why, no planning codes)
in every repository.

**Cost.** S.

## Testing

### T1. Contract tests in every satellite that implements a base SPI

**Problem.** The base ships contract test traits (`DocumentTypeContractTests`,
`CollaborationProtocolContractTests`, `StorageDriverContractTests`). collaboration and sheets each
use one; ai and editor-pro use none, although editor-pro implements the base `EventSink` SPI.

**Proposal.** Every satellite that implements a base SPI runs the matching contract trait.

**Rationale.** It is the cheapest guard against the "the base changed under me" class of breakage
from A1.

**Cost.** S.

### T2. The same test harness shape everywhere

**Proposal.** The same groups (`database`, `serial`, `extended`, `stand`), architecture tests in every
repository, type coverage on every pull request, and line coverage weekly.

**Cost.** M.

### T3. Scheduled mutation testing on the domain core

**Problem.** No repository in the family runs mutation testing.

**Proposal.** A weekly Infection run on the domain-heavy packages (core idempotency and outbox, drive
access, collaboration journal and fencing, editor-pro review) with a minimum MSI that only ratchets
up, and Stryker for the DOCX mapper. Not per pull request.

**Cost.** M.

### T4. Retire or repair the test-name baseline

**Problem.** `scripts/t baseline --compare` (the `composer test:baseline` script) is meant to catch
deleted or renamed tests against `tests/baseline/original/*.list` and a ledger. It is not run in CI
and currently reports thousands of missing names on `main` (for example 1,908 in core and all 1,120
originals in drive), because the suites were moved after the originals were frozen.

**Proposal.** Either regenerate the originals at the next release tag and run the comparison in CI,
or delete the tool and its data. Keeping a guard that nobody can pass is worse than either.

**Cost.** S.

### T5. A contract test for the Node converter error codes

**Problem.** The DOCX converter (a Node CLI in editor-pro) reports failures as error codes that the
PHP exchange package maps to problem codes. The two lists live in different languages and nothing
checks that they agree: a new or renamed Node code falls through to a generic failure on the PHP
side. The sheets Node engine has the same shape.

**Proposal.** The Node side exports its codes as JSON at build time; a PHP test asserts that every
exported code has a mapping and every mapped code still exists. The same test template in every
repository with a Node worker.

**Cost.** S.

## Hygiene

### H1. No planning codes in code, tests and comments

**Problem.** Planning and review codes (`F-P1.4-12`, `SEC-R19`, `D19`, `P2.5`, ...) point at documents
outside the repository. On `refactor/polish` they are gone from the base source and from the test
names, except the frozen public-API snapshot tests and two names in `DocumentCopyLifecycleTest`
(files the in-flight series is changing). About 110 lines in the base tests and scripts still carry
them (mostly identifiers inside strings and dataset keys, 17 comment lines, the `scripts/t` header),
and test files and directories are named after plan phases (`packages/drive/tests/Uploads/P3`,
`R07*ContractsTest.php`). collaboration has a few
as well.

**Proposal.** Replace each code with the reason the code exists, as part of normal changes to those
files, and add a rail (B1) that rejects new ones.

**Cost.** M overall, spread over normal work.

### H2. The same `.gitignore` agent block everywhere

**Problem.** The base ignores agent and tool configuration (`.claude/`, `.cursor/`, `AGENTS.md`,
`.mcp.json`, ...) at any depth; none of the satellites does.

**Proposal.** The same block in every repository, shipped from B3 so it cannot drift.

**Cost.** S.

## Satellite defects waiting on in-flight work

Real defects found by the reviews of the satellite branches whose files are being changed by the
in-flight series right now. Each is fixed on the satellite branch once that series lands.

### X1. editor-pro: exchange recovery must re-dispatch or fail

**Problem.** The exchange `recover` command resets jobs with an expired lease to `queued` but does
not dispatch them again, so they stay queued until purge. The same happens when the queue worker is
killed by its timeout while the lease is still live: nothing ever picks the job up, and the client
polls forever. (A crashed worker now marks the job `failed` on the branch; these two paths remain.)

**Proposal.** `recover` re-dispatches every job it requeues, bounded by the attempt budget, and
marks a job `failed` with an error code once the budget is spent; a test kills a worker mid-job and
asserts that the job finishes or fails. After the series that changes the exchange jobs.

**Priority.** P1. **Cost.** S.

### X2. editor-pro: the overlap detector is a stateful singleton nobody shares

**Problem.** The review overlap detector caches the index of the last document it saw (that cache is
what made it fast), so it is no longer stateless, yet `ReviewBindings` still binds it as a
singleton, while `SuggestionOperations` creates its own instance with `new`. The singleton is unused
on that path, and anywhere it is used, a long-lived worker carries one document's index into the
next request.

**Proposal.** Drop the unused singleton binding, or pass the index explicitly so the detector stays
stateless; then one construction path. After the series that changes the review operations.

**Priority.** P2. **Cost.** S.

### X3. sheets: stream the ZIP entry checks

**Problem.** `ZipArchiveGuard` reads every entry into memory to count its bytes, up to 50 MiB per
entry, so one XLSX import can hold far more than its compressed size in PHP memory, which is the
resource the guard exists to protect.

**Proposal.** Read each entry through a stream in fixed chunks, count the inflated bytes and stop
at the per-entry and total limits as soon as either is crossed; a test with a crafted archive
asserts the peak memory stays bounded.

**Priority.** P1. **Cost.** S.

### X4. editor-pro: unpinning must not clear an earlier keep-forever

**Problem.** `RevisionPinOperations::unpin(releaseRetention: true)` (the `release_retention` query
parameter of the unpin endpoint) clears the revision's keep-forever flag unconditionally. If the
revision was already kept forever before it was pinned, unpinning silently drops a retention
decision the pin never made, and the revision becomes eligible for pruning. `releaseExpired()`
deliberately leaves retention alone, so the two paths disagree.

**Proposal.** Record on the pin whether pinning set the flag (a `set_retention` column), and release
retention on unpin only when it did; a test pins a revision that is already kept forever, unpins
with `release_retention` and expects the flag to stay. The column belongs to the review schema,
which an in-flight series is changing.

**Priority.** P1. **Cost.** S.

### X5. ai: drop the core config keys nothing reads

**Problem.** The published core config (`config/vaulter-ai.php`) carries `default_profile`
(`VAULTER_AI_DEFAULT_PROFILE`) and `chat.max_retries`, `chat.retry_backoff_ms`,
`chat.request_timeout_seconds`. No code reads them: retries and timeouts of model calls are executor
settings (`vaulter-ai-laravel-ai.chat.*`, which `LaravelAiLlmPort` does read), and the profile comes
from `CallContext::$profile`, which the host's `CallContextResolver` sets. An operator who changes
the core keys gets no effect and no error.

**Proposal.** Remove the four keys (pre-1.0, no compatibility layer) rather than wire them: wiring
would give each setting two sources of truth. The configuration reference already marks them as
unread; drop that note in the same change. The ai core and laravel-ai suites pass with the keys
removed. The config file is being changed by the in-flight ai series, so after it.

**Priority.** P2. **Cost.** S.

Resolved on the satellite branches since the review, kept here for the record:

- sheets realtime now re-validates every peer's token during the session, as the collaboration hub
  does: an expired token closes the connection with code 4001 so the client reconnects with a fresh
  one, and each peer is re-checked with its own token at least once a minute (`revalidateMs`), so a
  revoked grant closes only that peer (vaulter-sheets `c23f1ab`, `eeb23d5`, docs `5f3b934`).

## Performance and security

Found by the measured performance and security pass of 2026-11-25 (PostgreSQL 18, a drive with
512k nodes and 1M activity rows) and by the same pass over collaboration and sheets. What could be
fixed on `refactor/polish` without touching in-flight files has landed; these items remain.

### Q1. A sorted index and a seekable cursor for folder listings

**Problem.** A folder listing orders by `name, id` but the only index is `(drive_id, parent_id)`,
so every page sorts all live children: 5.5 ms per page at the default 10k-child cap, 44 ms at 200k.
An index alone is not enough: `ExactListing` pages with `name > ? OR (name = ? AND id > ?)`, which
the planner cannot seek, so with the index a deep page gets slower (91 ms) instead of faster.

**Proposal.** Add `(parent_id, name, id) WHERE trashed_at IS NULL` (a plain composite on MySQL) and
give `ExactListing` the leading bound of Q2 (`name >= ?` before the tie-breaker). With both, the
first page is 0.2 ms and deep pages cost the same. Waiting on the schema series: `DriveSchema` and
the schema expectation fixtures are being changed by H-01.

**Priority.** P1. **Cost.** S.

### Q2. Seekable keyset cursors everywhere

**Problem.** The family writes keyset cursors as `a < ? OR (a = ? AND b < ?)`. PostgreSQL cannot
start an index scan from that predicate, so it walks the index from the first row and filters: the
activity feed spent 142.8 ms on a deep page of 1M rows, its first page 0.08 ms. The same shape is in
`ExactListing` (base) and `LogTables::scan` (collaboration, the compaction lease scans).

**Proposal.** One rule, ideally one builder helper in the base: put a leading bound on the first sort
column (`a <= ?`) in front of the tie-breaker. It is portable and needs no raw SQL. A row-value
comparison `(a, b) < (?, ?)` seeks too on PostgreSQL, SQLite and MySQL 8, but MariaDB optimises it
less reliably, so the leading bound is the safer default. On MySQL and MariaDB the compaction scans
also have no partial indexes and walk the primary key; a plain index on the filter column (or a
generated column that is non-null only for candidate rows) is the substitute there. Done for the
activity feed (base `5e95eb1f`: 142.8 ms to 0.076 ms), the document revision history (base
`5cf3cfd2`: pages by revision number alone, 11.4 ms to 0.03 ms at 50k revisions), the file
revisions (base `36edca09`: deep page 4.5 ms to 0.03 ms) and the webhook delivery feed of an
endpoint (base `3acdb762`: an `(endpoint_id, created_at, id)` index and a leading bound, 23.6 ms to
0.055 ms at 150k deliveries). Still on the OR shape, small per node: comments, grants and shares.

**Priority.** P1. **Cost.** S.

### Q3. Indexed substring search on names

**Problem.** Name search is `lower(name) LIKE '%term%'`, a sequential scan of the whole nodes table:
67 ms at 500k rows, linear in the drive size.

**Proposal.** On PostgreSQL a `pg_trgm` GIN index on `lower(name)`, behind a capability check
because the extension needs a superuser or a managed-database setting; MySQL keeps the scan or uses
a FULLTEXT index with prefix semantics. This is a deployment decision as much as a code change.

**Priority.** P2. **Cost.** S.

### Q4. Drop indexes that duplicate a key prefix

**Problem.** A B-tree whose columns are a prefix of another index only costs writes. Found:
`v_drive_changes (drive_id)` next to `(drive_id, node_id)` and `(drive_id, seq)`; `v_files` indexes
`node_id` twice; `v_drive_change_heads` indexes `drive_id` twice; `v_file_revisions (file_id)` is
covered; `v_document_revisions (document_id)` is covered by `(document_id, number)`. The four
document payload and sync-state tables also index their primary-key column a second time: the
schema meant to skip it with `index(false)`, but Laravel creates a fluent index for `false` on a new
column, so the line has no effect. In collaboration the heads, updates and receipts logs index
`document_id`, which leads their primary keys; there the copy tripled the cost of 200k inserts
(3575 ms against 1168 ms).

The same no-op `->index(false)` after `reference()` is in the satellites, so each of these columns
still gets the single-column index its author meant to skip (a duplicate wherever the column leads
a composite index or the primary key):

- ai `AiDocumentsSchema`: `ai_text_commands.document_id`, `ai_text_projection_heads.document_id`,
  `ai_text_blocks.document_id`.
- editor-pro exchange `ExchangeSchema`: `document_exchange_jobs` `document_id`, `source_blob_id`,
  `source_revision_id`, `output_blob_id`.
- editor-pro review `ReviewSchema`: `document_revision_pins.revision_id`,
  `document_comment_anchors.comment_id`, `document_comment_states.comment_id`.
- base `DocumentsSchema`: the payload and sync-state key column (`getColumns()[0]->index(false)`).

editor-pro already works around it for `document_id` with a private `withoutLeadingDocumentIndex()`
that clears the fluent flag, copied into both schemas.

**Proposal.** `IdStrategy::reference()` now takes `index: false` (base `587a8f22`). Use it for
each column above, delete every `->index(false)` line and both `withoutLeadingDocumentIndex()`
copies, and drop the duplicates in the same migration as Q1 (one migration per repository),
regenerating the schema expectation fixtures once. A grep check in the shared rails (B1) that
rejects `->index(false)` keeps the no-op from coming back.

The satellites cannot adopt `index: false` before the base merge: their CI checks out the base from
`main` (`scripts/ci/checkout-ecosystem.sh`, `VAULTER_REF` defaults to `refs/heads/main`), where
`reference()` has no such parameter yet. Collaboration's heads, updates and receipts logs switch in
the same change that follows the base merge.

`v_node_comments` has the same overlap: `foreignKeyIndex(node_id)` (created on MySQL only) next to
`(node_id, created_at)`. `v_webhook_deliveries` keeps the single `endpoint_id` index of
`reference()`, now covered by `v_webhook_deliveries_endpoint_feed_idx` (base `3acdb762`); drop it in
the same migration.

**Priority.** P1. **Cost.** S.

### Q5. sheets: export replay without hashing the source blob

**Problem.** Replaying an export recomputes the hash of the full source blob to decide whether the
cached result is still valid, so a replay costs a full read of the workbook.

**Proposal.** Store the source SHA-256 next to the export when it is produced and compare that
column instead of re-reading the blob.

**Priority.** P2. **Cost.** S.

### Q6. ai: resource ids in the text projection heads

**Problem.** AI body-text search finds matching blocks through `ai_text_blocks` and
`ai_text_projection_heads`, which know only the `document_id`. To return resources, the directory
lists every node the caller can see and resolves each one's document to match the hits. Reading
metadata instead of the full payload (ai `f4681bd`) removed the 603 payload and sync-state queries
of a 200-document drive, but the search is still linear in the number of visible documents.

**Proposal.** Store the drive node id (`resource_id`) in `ai_text_projection_heads` when the
projection is written, index `(tenant_id, resource_id)`, and let the search join hits to resource
ids directly; the directory then checks access only for the hit set (the batched `filterAccessible`
of ai `f426591`). Needs a backfill for existing heads.

**Priority.** P1. **Cost.** M.

### Q7. Push chat events instead of a PHP worker per open tab

**Problem.** The ai chat event stream (SSE) is a PHP loop that polls the run's events for up to
25 s per connection, so every open chat tab holds a PHP-FPM worker. Idle polls now back off from
200 ms to 2 s (ai `368eeac`: 15 polls per idle connection instead of 125, 4 queries each), but the
worker is still held: a few hundred open tabs exhaust a typical FPM pool.

**Proposal.** Publish run events from the writer and deliver them over the collaboration hub's
long-lived connection, or over PostgreSQL `LISTEN/NOTIFY` in a small event-loop process
(ReactPHP, Swoole or the hub itself), keeping `Last-Event-ID` replay from the events table for
reconnects. The polling endpoint stays as the fallback for MySQL and for hosts without the hub.

**Priority.** P2. **Cost.** M.

### Q8. Seekable pages for the scan backfill and rescan

**Problem.** `ScanBackfillReconciler` and `RescanFileRevisionsCommand` page `v_file_revisions` with
the OR keyset of Q2, and `scan_admission_sequence` has no index; each page also filters through
`EXISTS` on the file's node. A rescan of a large drive therefore re-reads the table from the start on
every page.

**Proposal.** An index on `v_file_revisions (scan_admission_sequence)` (partial on PostgreSQL where
the backfill filter allows) and the Q2 leading bound in both pagers. Waiting on the schema series:
`DriveSchema` is being changed by H-01.

**Priority.** P2. **Cost.** S.

### Q9. One read snapshot of the drive per operation

**Problem.** One node read still loads its drive by primary key several times: `DriveHandle::context`,
`TenancyBoundary::assertDrive`, the lifecycle check of `OperationGuard::authorize`, `abilities()`,
`NodeSnapshotMapper::tenants` and `AccessResolver`'s `loadMissing` each read it again (about 7 for a
GET of a node). `CollaborationAccessPolicy::evaluateReference`, which the hub runs on every backend
call, read it 13 times; reusing the drive `abilities()` already holds for its nine profile gates
(base `e452964c`) brings that to 4 (22 queries to 13), and the abilities of one node from 12 drive
reads to 3. Each remaining read is a primary-key lookup and fresh by design: the policy is the live
authority, and `ChangeDocumentWriteModeAction` re-evaluates it after taking its row locks.

**Proposal.** An owner decision first: whether an operation may decide on one drive snapshot. If yes,
thread the drive the operation already resolved (`DriveContext` holds it) into the guard and the
mapper as an explicit argument, re-reading only where a lock was just taken. A request-scoped memo
(a scoped container binding, safe under Octane) would also remove the reads, but it hides staleness:
an archive or a delete earlier in the same request, or a lock taken after the first read, would be
decided on the old row. Explicit passing keeps the freshness visible at each call site.

**Priority.** P3. **Cost.** S.

### V1. Realtime identity from the token, not the client

**Problem.** Collaboration awareness and sheets presence took the user's name and id from what the
client sends, so a peer could show itself to others as someone else.

**Done.** The id is the server's: the collaboration hub stamps `user.id` / `user.type` of every
awareness state with the principal the backend verified and refuses awareness for another live
connection's client id (collaboration `bf4b3c7`); sheets realtime does the same from the room token
the owner accepted and gives each socket its own presence `client_id` (sheets `220abc6`).

**Remaining: a server-side display name.** The name and colour are still the client's, because the
room token carries only `sub` and `sub_type`. Either the base mints a `name` claim into the room
token (a host-provided display-name resolver behind `ActorRef`, so the satellites stay
user-model-agnostic), or the base exposes a batched `user.id` → display-name endpoint the editors
call for the ids they see in presence. The first keeps the rooms self-contained and costs one claim
per token; the second keeps names out of tokens and logs. Either way the hub and the sheets room
then overwrite `user.name` as they now overwrite `user.id`.

**Priority.** P1. **Cost.** S.

### V2. A per-peer intent budget in sheets realtime

**Problem.** Sheets realtime has no per-peer limit on intents, so one peer can fill the room's
queue and starve the others.

**Proposal.** A token bucket per peer, with a close code once it is exceeded, configured like the
collaboration hub's limits; a test floods from one peer and asserts another peer's intents still
apply.

**Priority.** P1. **Cost.** S.

### V3. Share passwords out of query strings

**Problem.** `ResolveShareToken` accepts the share password from a `password` query parameter on
GET routes, which puts it in access logs, proxy logs and browser history. It probably exists so a
plain `<a>` link can download a password-protected share.

**Proposal.** Exchange the password once (POST) for a short-lived, share-scoped cookie or signed
token and drop the query fallback, or keep it behind an explicit option documented as logging the
secret.

**Resolved** on the base `refactor/polish` (decided by the owner: POST and a signed link, no query
fallback). The query parameter is ignored; `POST v1/shares/{token}/unlock` trades the password for
a five-minute grant bound to the link, its password and one file; wrong passwords are limited per
client and link. See the sharing guide.

**Priority.** P2. **Cost.** S.

### V4. ai: commit checks the permission of its command

**Problem.** `Commit::authorize()` asks the directory for `edit` on the resource, while describe
and prepare use the descriptor's `requiredPermission`. A command declared with a stricter
permission (for example `manage`) is prepared under that permission but committed under `edit`;
if the caller loses the stricter permission between prepare and commit, the commit still runs.

**Proposal.** Resolve the command descriptor from the proposal's plan in `authorize()` and pass its
`requiredPermission` to `findAccessible()`, with a test that revokes the stricter permission after
prepare and expects `resource_unavailable` on commit.

**Priority.** P1. **Cost.** S.

### V5. ai: a resource-scoped tool port only reaches its resources (resolved)

**Problem.** `OfficeToolPort::forContext($context, $runId, $resources)` narrowed only the advertised
descriptors; `call()` executed any `resource_id` the user could access.

**Resolution.** ai `ba624bf`: a scoped port refuses calls on resources outside the scope, and search,
with `resource_unavailable`; an unscoped port is unchanged.

### V6. ai: an allowlist sanitizer for streamed HTML

**Problem.** `StreamContent.ts` sanitizes streamed HTML fragments with a blocklist of tags
(`BLOCKED_TAGS`). Anything not on the list (a new element, an unexpected attribute) reaches the DOM
parser, and the list has to be kept in step with browsers.

**Proposal.** Derive an allowlist from the editor's ProseMirror schema: parse the fragment with the
schema's `DOMParser` into a detached document, keep only nodes, marks and attributes the schema
defines (dropping event handlers and non-http(s) URLs), and test it with a corpus of known XSS
payloads.

**Priority.** P1. **Cost.** S.

### V7. A forged or stale cursor is a 500, not a 400

**Problem.** Every cursor-paginated endpoint passes the client's `cursor` straight to Laravel's
`cursorPaginate()`. A cursor without the expected parameter throws `UnexpectedValueException`, and
a parameter of the wrong type reaches PostgreSQL and fails the cast (`QueryException`): both are an
HTTP 500. Affected: document revisions and comments, file revisions, grants, shares, the nodes of a
public share, webhook endpoints and deliveries. Cursors issued before `5cf3cfd2` and `36edca09`
(which dropped `id` from the revision order) hit the first case; the message of `5cf3cfd2` says
they are refused as invalid, but they are a 500 until this lands.

**Proposal.** Decode the cursor in one place (the Q2 builder in the base), check its parameters and
their types against the sort columns, and answer an invalid one with the 400
`drive.invalid_cursor` problem the drive already defines. Signing the cursor (an HMAC with the app
key) also stops clients from crafting positions. This changes the error contract of these endpoints, so it needs an owner decision.

**Priority.** P1. **Cost.** S.

## Merge order notes

- After the base `refactor/polish` merges (the managed-codec SPI gains `array<string, mixed>`
  payload types), remove the sheets PHPStan ignore for `method.childParameterType` on
  `WorkbookCodec`: it only exists because the base SPI was untyped.
- Satellites resolve the base from a sibling checkout of `main` (A1), so merge the base first, then
  collaboration, then sheets and editor-pro, then ai.

## Salvaged from the planning archive

`plan/` and `audits/` (332 files, about 5 MB, Russian) hold the design work behind the ten delivery
phases, including the `MVP-2` track (range comments, tracked changes, DOCX exchange, XLSX/CSV,
collaborative workbooks, workbook references in TipTap) and a study of every TipTap Pro module.
All 33 entries of the orchestration manifest (the scaffold and tasks `0.1` to `10.2` of both tracks) have landed in the five repositories; the
executable checks, reference implementations, SQL drafts and probes in the archive are superseded by
the tests and migrations in the packages. What follows is what the archive still knows and the code
does not do yet. Items are checked against `main` and `refactor/polish` on 2026-11-25.

### S1. The projector contract and its measured payoff (extends A9)

**Problem.** A9 needs a contract for the projection writer before anyone builds it. The archive has
one, plus the measurement that justified the projection tables.

**Proposal.** Build the projector to these rules:

1. The writer commits the change together with an outbox row (or a link to the recoverable journal).
   The projector receives the document id, the generation and the range of source positions.
2. A delta is applied only on top of the published base of the same generation: an exact duplicate
   is ignored; a gap makes the projector read the missing range or rebuild; an event from an older
   generation (after a restore) is dropped.
3. Rows and the head pointer are published in one transaction. A large rebuild fills a new build and
   re-checks the source, generation and base before the head moves. A reader takes one snapshot (or
   one query joined to the head), so it can never mix a new head with old rows.
4. A read after a receipt asks for at least the receipt's position. If the projection lags, the
   reader either reads a bounded fragment from the authoritative state (re-checking access) or
   returns "pending"; the position check includes the generation.
5. Verification "as of commit" uses the immutable receipt or the historical state, not the current
   projection; a formula cache counts as current only with confirmed dependencies.
6. Deleting a document hides it from authorised reads at once; projection rows and blobs are swept
   later, idempotently and per tenant.

Review vectors worth turning into tests: a stale paragraph returned under a newer receipt position
is a defect; a projector of generation 3 must not move the head after a restore to generation 4;
access revoked between search and snippet hides title, snippet and position; with `B2=12, B3=8,
D2=SUM(B2:B3)` and a write `B2=15`, the stale cached `D2=20` must not be reported as recalculated;
two deliveries of one event publish once; equal local ids in two tenants never authorise each other.

**Rationale.** Measured on PostgreSQL 18.4 (warm cache, 50 samples, response hashes verified), reading
20 cells `B2:E6` from a 100,000-cell workbook took **133 ms p95 from the JSONB monolith and
0.15–0.23 ms from the projection**; reading three paragraphs of a 1,100-block contract took 1.6–2.0 ms
from JSONB and 0.12 ms from the projection. The tables are worth filling.

**Priority.** P1, together with A9. **Cost.** M.

### S2. One licence allowlist instead of four denylists (input to B1)

**Problem.** The family's licence rule is "production dependencies are MIT, Apache-2.0, BSD or ISC
only; no TipTap Pro, `@univerjs-pro/*`, Handsontable, HyperFormula, GPL, AGPL or SSPL". The base
enforces it as an allowlist (`scripts/ci/engineering-rails.py`). The satellites enforce it as
regex denylists that disagree: sheets accepts `proprietary`, collaboration rejects `PROPRIETARY`
and `SEE LICENSE`, editor-pro rejects `UNLICENSED` but not `proprietary`, and ai keeps its own
inline copy. A new licence string passes or fails depending on the repository.

**Proposal.** Ship the base allowlist and the named package denylist as the B1 rails configuration,
with an explicit exemption only for the family's own private packages.

**Rationale.** Licence-clean production dependencies are a product requirement (closed products ship
on top of these packages without royalties), so the rule must not depend on which copy runs.

**Priority.** P1, with B1. **Cost.** S.

### S3. A first-party ClamAV scanner in the base

**Problem.** The base has the `ContentScanner` contract, its contract test suite and a doctor check
that refuses a bound ClamAV older than 1.5.5, but no scanner implementation: every host has to write
the clamd client itself, the most security-sensitive piece of the upload path.

**Proposal.** Ship a `ClamdContentScanner` in media: an external `clamd` over a Unix socket or an
isolated network, `zINSTREAM` in 64 KiB chunks (bounded PHP memory on a 1 GiB file), fail-closed on
any error or timeout, and the documented daemon settings (`StreamMaxLength 1024M`, `MaxScanSize
1024M`, `MaxFileSize 512M`, `MaxRecursion 16`, `AlertEncrypted yes`, `AlertBrokenExecutables yes`).
Run `ContentScannerContractTests` against a real clamd in the extended CI lane.

**Rationale.** The quarantine, verdict and doctor plumbing already exist; the adapter is the missing
piece that makes the secure default usable.

**Priority.** P1. **Cost.** S.

### S4. The unused blob encryption column: implement or drop

**Problem.** `v_blobs.encryption_key_id` (with an index) is created by `MediaSchema` and declared on
the `Blob` model, but nothing reads or writes it.

**Proposal.** Either drop the column, or implement the encryption profile it was added for:
segmented envelope encryption with `sodium_crypto_secretstream_xchacha20poly1305_*` in independent
4 MiB segments (64 × 64 KiB chunks), the DEK wrapped by the tenant's KMS key with AAD
`dek:{tenant}:{sha256}`, a per-segment subkey derived from the DEK and the segment index, chunk AAD
`vaulter:v1:{tenant}:{sha256}:seg={n}`, and `sodium_memzero` after use. This keeps O(1) HTTP `Range`
reads at about 0.03 % size overhead and makes cross-tenant blob substitution fail authentication.

**Rationale.** A column with no behaviour suggests encryption at rest that does not exist. No users
yet, so dropping it now is free; adding it later is a normal migration.

**Priority.** P2 (decide), P1 if a product needs encryption at rest. **Cost.** S to drop, L to
implement.

### S5. Editor features still missing against TipTap Pro

**Problem.** The archive classified all 93 TipTap Pro catalogue entries. Collaboration, REST,
JWT, webhooks, comments, snapshots, compare, tracked changes, DOCX import/export, Markdown and the
AI toolkits are now covered by the family. The rest was deferred and is not built anywhere.

**Proposal.** Keep them as independent, opt-in extensions (never in the base), each with its own data
owner, schema version, server-side enforcement and acceptance corpus. The per-module notes are in
[vaulter-editor-pro `docs/concepts/tiptap-pro-parity.md`](https://github.com/axiomasoft/vaulter-editor-pro/blob/main/docs/concepts/tiptap-pro-parity.md).
Suggested order:

| Priority | Feature | Note |
| --- | --- | --- |
| P1 | Office paste normalisation (Word, Outlook, Google Docs/Sheets HTML) | DOCX import does not cover the clipboard, which is how most content arrives |
| P1 | File drop/paste into Media (`@tiptap/extension-file-handler`) | Callbacks bound to Media upload, limits and ACL |
| P1 | PDF export | Server render of a pinned revision with pinned fonts and an access check; the base only has the exporter SPI |
| P1 | Find and replace, table of contents | Reuse the OSS extensions; anchors for the ToC must follow `blockId` |
| P2 | Page layout and page breaks in the editor | The DOCX model already carries `pageBreak`; the editor schema does not, so breaks are not editable |
| P2 | Template slots and variables | Typed fill-only fields resolved from authorised sources, with a server-side commit for headless fills (contracts, lessons) |
| P2 | Content protection for human edits | `ContentProtectionPolicy` is enforced only on AI writes in vaulter-ai; the hub and the base API do not consult it |
| P2 | Comment mentions, unread state and notifications | Threads, replies and resolve exist; nothing tells a person they are addressed |
| P3 | ODT and EPUB export, Diagrams, Whiteboard | Only on a product request |

**Rationale.** Without this list the next "TipTap Pro parity" question has to redo the research.

**Priority.** As in the table. **Cost.** M to L per feature.

### S6. Remove the archive

**Problem.** Besides A6: the repository is public now. `plan/tiptap-pro/` documents the internals of
TipTap Pro packages read from their installed `dist/` bundles under a Pro licence, together with
probe scripts and the account's team id in `plan/tiptap-pro/.env.example`. Deleting the folder
removes it from the tree but not from the public history.

**Proposal.** Delete `plan/` and `audits/` once the migration series has moved what the satellites
need (items S1–S5 above keep the rest).

**Decided.** No history rewrite: the repositories are public only while CI is brought up and go
private again afterwards.

**Priority.** P1. **Cost.** S.
