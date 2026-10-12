The candidate and its receipts are unchanged. I’ll finish the read-only pass over the full diff and the owning source, using only file reads and search.The driver-selection and Comment-bit edits are in the diff. I’ll read the remaining product changes and check the new contract suite against the same rules.The per-drive driver selection is in place, but one global listing gate still opens every authoritative drive when any other profile cannot use grant narrowing.

## Verdict: FINDINGS

### High — one non-authoritative profile disables grant narrowing for every profile

`packages/core/src/Repositories/Drive/NodeReadRepository.php:508-519`

`grantFilteringApplies()` returns false when any registered profile has grants disabled or its selected driver is not grant-authoritative. Gate, Spatie, and Azgard are not (`AzgardPermissionDriver.php:69`, `SpatiePermissionDriver.php:14-16`). `PanelAccessScope::candidateNodeIds()` then returns null (`packages/filament/src/Support/PanelAccessScope.php:43-44`), and these queries stay unfiltered:

- `packages/filament/src/Resources/DriveResource.php:65-67`
- `packages/filament/src/Resources/NodeGrantResource.php:80-81`
- `packages/filament/src/Resources/NodeShareResource.php:83-84`
- `packages/filament/src/Resources/ActivityResource.php:67-68`

`constrainToAccessible()` (`NodeReadRepository.php:421-422`) does the same. The node table later exact-filters only the loaded page (`ListNodes.php:39-44`). Drive, grant, share, and activity queries do not.

Trigger: profile `hr` uses `database`, profile `eng` uses `gate`, `spatie`, or `azgard` (or `hr` stays authoritative while `eng` sets `grants(false)`). An actor with no grants on an `hr` drive still receives that drive, its grant rows, its share rows, and its activity. Before this change the boolean followed the single active driver, so an authoritative database install stayed narrowed.

Correction: build the candidate set per drive. Keep grant-anchor ids for drives whose selected driver is grant-authoritative and whose grants feature is on. Limit the open set to drives that are not. Do not let one profile turn `grantFilteringApplies()` off for the others. `PanelAccessScope` should keep a real id predicate in that mixed case.

The per-drive subject loops in `candidateNodeIds()` (`536-557`) and `sharedNodeIds()` (`AccessResolver.php:402-424`) already select the profile driver. The global boolean discards that set.

## Checked, no further defect

Comment is bit 32 and `Permission::all()` is 63, with `All` removed. Level masks are 1/33/39/47/63. `Ability::Comment` requires 32 and `Manage` requires 63. `bitsFor()` ORs the level mask at read time (`AccessResolver.php:686-688`); the raw `permissions` column is not rewritten. Reader plus stored 31 does not gain Comment. Owner does, because the level mask is now 63.

`OwnerRef` and `AccessScope` match the scoped shape. `canCreateInWorkspace` is gone from package PHP. The seven live callers call `canCreateIn(actor, $drive)`. `OperationGuard` still applies the profile policy after the ACL branch, including for system actors (`OperationGuard.php:38-40`, `55-57`). No `download(bool)` API was added.

Scalar, batch, and explain all fold role deny the same way (`AccessResolver.php:108-116`, `233-234`, `331-339`). Results are not memoized. The driver cache key is `key|class` and the class is read from config on each resolve (`PermissionDriverManager.php:62-80`). Spatie subject expansion sets the drive-owner team and restores the previous team and relations (`SpatiePermissionDriver.php:138-152`, `203-218`). Role and team grant insertion uses the node drive’s driver (`GrantNodeAccessAction.php:75`).

The public suite checks the shared allow/deny fixture and scalar, batch, and explain parity for every ability (`PermissionDriverContractTests.php:20-33`), and the core contract test runs it for database, stub, gate, and spatie. The architecture allowlist is exactly `Testing/Contracts/PermissionDriverContractTests.php` (`ArchTest.php:338-341`). Anonymous `level=commenter` is rejected on the share request (`StoreShareLinkRequest.php:37`). The public share controller still authorizes only View and Download. No anonymous comment route was added.

Latest recorded receipts for V1–V9, V80, V82, Arch, snapshots, and the isolated negative control are exit 0. This review did not re-run them.

## Non-gating limitations

- `docs/guide/permission-drivers.md`, `docs/reference/contracts.md`, `docs/reference/rest-api.md`, and `docs/guide/sharing.md` still describe `canCreateInWorkspace`, workspace fields, and mask `0..31`. The candidate’s `UPGRADE.md` and `CHANGELOG.md` carry the public delta. Those guides are outside the candidate file list.
- A share created with `level=writer`, `organizer`, or `owner` and no explicit mask stores 39/47/63, including Comment. The public share controller never checks `Ability::Comment`. Mint enforcement remains P3.4.
- The V29 edit leg is `AccessResolver::can(Edit)`, not an edit HTTP call. Commenter mask 33 does not include Update.
- `OperationGuard::tenantBoundary()` is still empty. Strict tenancy remains P3.8.
- Recorded checks cover the five impacted packages only. No whole-phase qualification is claimed.
