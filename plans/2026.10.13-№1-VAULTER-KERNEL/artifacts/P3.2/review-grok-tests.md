# Review: tests and harness, P3.2-P3.4 (f5476019, 3eda09b2, 4d38b6e5)

Only defects reproduced or confirmed by reading are listed.

## Findings

### 1. HIGH - tests/Repo/Arch/IdentitiesTest.php:428 - committed test is red
Scenario: `scripts/t sqlite -p repo tests/Repo/Arch/IdentitiesTest.php` -> 34/35 pass, the test "list the event types, problem codes,
queues, casts and hand-written formats" fails with "72 is identical to 71". `identities/protocol.json` has 72 `problem:` items
(`problem:module_graph_invalid` is among them). The test still expects 71. The fixture came with P3.1 (9532b008). P3.2 edited
IdentitiesTest.php in the same area and left 71. The test stayed red through P3.2-P3.4.
Fix: `toBe(72)`, or take the count from an independent source instead of a magic number.

### 2. HIGH (cause not isolated, may be my environment) - core Modules tests fail at HEAD
Scenario: `scripts/t sqlite -p core packages/core/tests/Modules/ActivationTest.php` -> 10/10 errors.
`scripts/t sqlite -p core packages/core/tests/Modules/RouteCacheActivationTest.php` -> 3/3 errors.
Both fail with `[Vaulter] Unknown vaulter.access.permission_driver "null"` from ConfigValidator.php:66.
The call chain is TestCase::setUp -> VaulterServiceProvider.php:100 -> ConfigValidator::validateDrive (added in 3eda09b2).
The driver map does not contain `null` when validate runs.
Reading the code, normalize() in ModuleApplier::register() should fill `access.permission_drivers` before boot.
I did not find why it does not. I used up the run budget and did not bisect.
The P3.2 tests are therefore unverified by me. The failure shows up on the first boot, so it is not specific to FreshApplication
or the skeleton partition. If the executor's runs were green, check the HEAD state or the environment. Otherwise this is a P3.3 regression
(validateDrive reads `access.*` before the config is normalized, with a partial host `vaulter.access`).
Fix: bisect 3eda09b2 against 4d38b6e5 with a single test file. Keep an assertion for the case where the host sets only `vaulter.access.permission_driver`.

### 3. LOW - IdentitiesTest.php:33 - ID_ACCEPTED_DECISIONS is a hand-edited constant
`['D21','D22','D25','D29','D36','D39']` is not checked against `plans/.../decisions/*.json` (status accepted).
The "accepted decisions only" check would pass for anything the author adds to the constant.
D36 and D39 are `accepted` but untracked in git (`??`), while committed code and the ledger cite them. A fresh checkout has no source for them.
Fix: commit D36/D39 with the code, or read the status of `Dn` from the decisions directory (or accept `D\d+` and require the file).

### 4. LOW - ModuleKernelBootTest "seals a valid graph" - the exact module list is narrowed
Before: `modules()` mapped to an exact list. Now the list is filtered to `host/optional/plugin`, and `core` and `webhooks` are checked
only through `isActive`. The module set can grow unnoticed (an extra offered module would not fail the test).
This is not a hidden removal of an assertion, but the check is weaker.
Fix: assert the full id list, in order, or at least `toContain` for core/webhooks.

## Checked, no defect
- tests/suites.json: all files in the partitions exist, none are duplicated, and every test file under core/drive/documents is listed.
  `fingerprint == sha256(key)[:12]` holds. For the `#skeleton` keys the hash is taken over the key without the `#skeleton` suffix. This is the
  existing convention, not a drift. `RouteCacheActivationTest` has `skeleton_writer: true` in its own partition, and the file is removed in `finally`.
- Fixture-write/unlink in RouteCacheActivationTest runs under try/finally, so state is restored. If the test is run outside scripts/t
  it uses the shared skeleton and deletes any pre-existing `routes-v7.php`. This is minor and is covered by the README rule about `-j1` and its own TESTBENCH_APP_BASE_PATH.
- `FreshApplication` restores Container and Facade. The static Eloquent `Model::$resolver` is left pointing at the fresh application.
  The next test's setUp recreates it, so this is not a confirmed leak.
- `tests/Repo/Arch/Fixtures/identities/changes.json`: every record has a reason and a decision. The nine redefined routes match the nine
  routes of routes/webhooks.php (ActivationTest asserts 9). The CLI `vaulter:webhooks:*` change from always-on to opt-in has no record. The ledger
  covers only what the schema checks. The ledger shape is checked by `checkChanges`.
- `tests/baseline/CHANGES.json`: the replacement entry for `ConsoleRegistrarTest` has a reason and a decision (D39), and the replacement test exists.
- Weakened expectations: the webhook-off checks (CoreDoctorChecksTest, PruneWebhooksTest, RedeliverWebhookTest) are rewritten to the
  new semantics (`unmatched`, "Webhooks module is inactive"), not dropped. SchedulingRegistrarTest still covers the invalid frequency for
  changes_prune and for webhooks through `ConfigValidator`.
  `WebhookTestHelpers::switchModule` is an acknowledged test seam, documented as such.
