# P7.4 review — grok/grok-4.7/high (session 01a12c45-7e1a-7170-9acb-61cbfdca529f)
Verdict: FINDINGS (8: 4 bugs, 3 suggestions, 1 nit), all confirmed by the executor with its own run (reviewer reproductions) and fixed in one correction; no re-review (local fixes, each covered by a new test).
1. Bug — `forTenant()` skipped the owner-tenant check (`FixedTenantResolver` was not a `ResolvesOwnerTenant`). Fix: `PinnedTenantResolver::over()` keeps delegating owner lookups to the replaced resolver when it can answer them (`PinnedOwnerTenantResolver`); `FixedTenantResolver` removed. Test: FactoriesTest "keeps the owner tenant check…".
2. Bug — a profile that pins its permission driver ignored the driver under test. Fix: the suite registers the driver under the profile's pinned key too (config restored in `finally`). Tests: ContractSuitesTest (pinned profile rejects the allow-all driver; config restored).
3. Bug — strict tenancy aborted ScanLifecycleContractTests (`model()` called outside the drive's tenant; tenant literal not valid for bigint/uuid keys). Fix: handles are read under their own tenant; the other tenant is derived in the shape of the current one (digits, uuid, ulid). Test: ScanLifecycleContractTest with tenancy on (two tenants).
4. Bug — the arch guard ignored the factories (production autoload) and `VaulterFactory` imported a Testing class. Fix: the tenant resolver moved to `Vaulter\Database\Factories`; TestingBoundaryTest now also covers the factory namespaces (core, documents); red proven with a temporary violating class.
5. Suggestion — sibling Testing namespaces not forbidden. Fix: every package forbids all four Testing namespaces.
6. Suggestion — unreadable bytes passed with Infected/non-retryable failures. Fix: must be a `Failed` result (or a throw). Test: ContractSuitesTest.
7. Suggestion — inherited `Factory::insert()` bypassed Operations. Fix: `insert()` throws. Test: FactoriesTest.
8. Nit — `vaulter-failing` wrapper stayed registered. Fix: `FailingStream::release()`. Test: ContractSuitesTest.
Confirmed OK by the reviewer: `create()` goes through Operations, `make()` stays unsaved, `forTenant()` restores the resolver after an exception, the broken allow-all driver / clean-misread scanner / node-dependent handler fail their suites, public API snapshots match, the drive exemption is only Testing/Contracts.
Stream: review-grok-stream.jsonl; full report: review-grok-full.md
Route proof: its own session record ~/.grok/sessions/…vaulter/01a12c45-7e1a-7170-9acb-61cbfdca529f/updates.jsonl shows modelId=grok-4.7; effort and contextWindowTokens are not recorded there (no context capacity requested; effort high passed on the launch line).
After the correction: V1–V6 rerun, all exit 0.
