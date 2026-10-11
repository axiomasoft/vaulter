Local changes. 4 bugs, 3 suggestions, 1 nit. Full notes: `/tmp/grok-vostrikov/grok-review-b3dc7105.md` and `/tmp/grok-vostrikov/grok-review-summary-b3dc7105.md`.

`create()` goes through Operations. `make()` stays unsaved (`exists=false`, no new row). `forTenant()` puts the previous tenant resolver back after an exception. On the default profile, the allow-everything permission driver, the clean-misread scanner, and the node-dependent file handler all fail their contract tests. The core suite for this kit passed (56 tests). Public API snapshots match the new methods. The drive architecture exemption is only `Testing/Contracts`.

## Bugs

**1. `forTenant()` skips the owner-tenant check.** `packages/core/database/factories/VaulterFactory.php:156`

`forTenant()` installs `FixedTenantResolver`, which is only a `TenantResolver`. `TenancyBoundary::assertOwner()` returns immediately unless the resolver is a `ResolvesOwnerTenant`, then the drive is stamped with the factory tenant. An owner stored in tenant A gets a drive in tenant B.

Observed: `fresh forTenant B tenant_id='B' owner=A`.

**2. A profile-pinned driver is never the driver under test.** `packages/core/src/Testing/Contracts/PermissionDriverContractTests.php:65`

The method sets `vaulter.access.permission_driver` to `contract`. `AccessResolver` calls `PermissionDriverManager::forProfile()`, which uses the drive profile's driver key. With the default profile pinned to `database`, `RoleStubDriver(Allow)` is accepted. `docs/guide/permission-drivers.md:466` says the method fails when the driver decides differently. The self-proof stays green because the stock profile names no driver.

Observed: `pinned database: PASS (allow-everything accepted)`. Control, unpinned profile: `RoleStubDriver decides differently from the reference driver`.

**3. Strict tenancy aborts the scan lifecycle suite.** `packages/drive/src/Testing/Contracts/ScanLifecycleContractTests.php:126`

Cross-scope setup does create both drives, and the evidence checks inside `within()` pass. The suite then calls `model()` after the ambient resolver is restored. `model()` only sees the current tenant, so the other drive throws `NodeNotFoundException` and policy, fault recovery, and webhooks never run. `scanHost()` never turns tenancy on. The other tenant id is the literal `contract-other`, which `tenantPredicate()` also rejects for `bigint` and `uuid` host keys.

Observed: `NodeNotFoundException` at line 126. Rows still present: `id=1 tenant='tenant-a'`, `id=2 tenant='contract-other'`.

**4. The architecture guard does not see the factories.** `packages/core/tests/Arch/TestingBoundaryTest.php:10`

The test globs only `src/*`. Factories are production autoload and `VaulterFactory` imports `Vaulter\Testing\FixedTenantResolver`. The boundary test passes. `docs/guide/uploads.md:397`, `docs/ru/guide/uploads.md:387`, and `CHANGELOG.md:23` say production code does not import a `Testing` namespace.

## Suggestions

**5. Sibling testing namespaces are not forbidden.** `packages/drive/tests/Arch/TestingBoundaryTest.php:16`

Drive forbids only `Vaulter\Testing` and `Vaulter\Drive\Testing`. Documents and media are the same shape. `Vaulter\Media\Testing` has zero classes, so that name cannot fail a `not->toUse` check. A `Vaulter\Drive\BoundaryProbe` whose use list contains `ExporterContractTests` sits outside the drive test's dependency layers. Root files and nested namespaces are included (`Vaulter\VaulterManager` 1 class, `Vaulter\Support` 14).

**6. Unreadable bytes are only rejected when the verdict is Clean.** `packages/core/src/Testing/Contracts/ContentScannerContractTests.php:86`

Any exception returns success. `Infected` and a terminal `Failed` (`retryable=false`) also pass. The clean-misread fixture still fails, which is why the core suite stays green.

Observed: `throwing scanner: PASS`, `infected-on-unreadable: PASS`, `non-retryable failure: PASS`.

**7. `Factory::insert()` still hits the query builder.** `packages/core/database/factories/VaulterFactory.php:71`

`create()` uses Operations. Inherited `insert()` does not. On a drive it dies on `NOT NULL owner_id` and leaves no row.

Observed SQL: `insert into "v_drives" ("created_at", "updated_at") ...`. `drives after insert=0`.

## Nit

**8. `vaulter-failing` stays registered.** `packages/core/src/Testing/Support/FailingStream.php:24`

`stream_get_contents` returns `''` immediately. `FakeContentScanner` returns `failed` / `transient_io` / retryable, with no scan recorded. After `fclose`, the wrapper is still registered (`before=no after_close=yes`).