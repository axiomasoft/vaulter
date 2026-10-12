# Review brief — item P7.4 (read-only)

Repo: /home/vostrikov/projects/packages/vaulter (uncommitted working tree vs HEAD; `git diff -- . ':!plans'` plus untracked files from `git status --short`).
Do not edit the repository. Run tests/commands freely (sqlite: `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci <path>`; per package from packages/<pkg>: `../../scripts/php84 vendor/bin/pest --ci tests/Testing tests/Arch`).

Item scope: host testing kit.
- Factories through Operations as a system actor: `packages/core/database/factories/{VaulterFactory,DriveFactory,NodeFactory,FileRevisionFactory}.php`, `packages/documents/database/factories/DocumentFactory.php`; `HasFactory`/`newFactory` on Drive, Node, FileRevision, Document; autoload in `packages/{core,documents}/composer.json`; root composer `autoload-dev` entry removed.
- `packages/core/src/Testing/`: `InteractsWithVaulter`, `FakeContentScanner`, `FixedTenantResolver`, `Fixtures/{FixtureOwner,FixtureActor}`, `Support/FailingStream`, `Contracts/{FileHandler,ContentScanner}ContractTests`, `Contracts/PermissionFixture`, `PermissionDriverContractTests::assertSameDecisionsAsReference`.
- `packages/drive/src/Testing/Contracts/ScanLifecycleContractTests.php` (C09: (a) scope, (b) policy, (c) fault recovery, (d) webhooks off), `packages/documents/src/Testing/Contracts/ExporterContractTests.php`.
- Arch `TestingBoundaryTest.php` in core, drive, documents, media (production code never uses a Testing namespace).
- Self-proof tests: `packages/{core,drive,documents}/tests/Testing/*`, fixtures `packages/core/tests/Fixtures/Testing`, `packages/documents/tests/Fixtures/Testing`.
- Docs: `docs/guide/{permission-drivers,document-types,export,uploads}.md` (+ `docs/ru`), UPGRADE.md, CHANGELOG.md.
Normative design: `python3 /home/vostrikov/.claude/plugins/cache/swissknifeman/task/0.28.3/scripts/plan.py show "2026.09.29-№1-VAULTER-STABLE" knowledge/P7-design-testkit#TestKit` and the C09 rows of `.../knowledge/security-correction-contract`.

Focus (hard risk: test-boundary, data-integrity, tenant isolation):
1. Factories must never bypass the invariants: every row is created through Operations/Actions (no raw insert); `forTenant()` swaps the TenantResolver and restores it even on exception; a made (not created) model must not be mistaken for a stored one.
2. Testing code must not leak into production: arch test proves it? Is the red case really red (e.g. a production class using `Vaulter\Testing\*`)? Is the subject enumeration complete for each package (root-level files, nested namespaces)?
3. Contract suites: each must catch a violation. Look for suites that pass vacuously (an assertion that cannot fail, a skipped branch, a try/catch that swallows `AssertionFailedError`). Check the broken fixtures really break what they claim.
4. ScanLifecycleContractTests: (a) cross-scope evidence reuse really tested (B uploaded after A is clean); tenancy branch (`FixedTenantResolver`, `--tenant`) correct; (b) legacy path (config mode off, then `ScanPolicyResolver::initialize`) under all_content and new_content_only; (c) lost publication, expired lease, stale attempt; the suite mutates `config` and the container (`Bus::fake`, `ContentScanner`, `TenantResolver`) — is everything restored or leaked into later tests?
5. `FakeContentScanner` / `FailingStream`: infinite loops, resource leaks, stream wrapper registration side effects.
6. `PermissionDriverContractTests::assertSameDecisionsAsReference`: container/config restore in `finally`; does it prove what the docs claim?
7. Public API snapshots (core/documents/drive `public-api.txt`) and docs statements match the code. Any claim in docs/UPGRADE/CHANGELOG that is wrong.
8. Architecture guards touched: `packages/core/tests/Arch/ArchTest.php` (Testing/Contracts listing), `packages/drive/tests/Arch/ArchTest.php` (Testing/Contracts exempt). Were guards weakened beyond need?

For every finding give a reproduction (failing test or command output). A finding without a reproduction is at most plausible. Report findings ranked by severity, then stop; do not fix.
