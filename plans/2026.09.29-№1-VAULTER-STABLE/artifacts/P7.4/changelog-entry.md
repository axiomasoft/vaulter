### Host testing kit: factories, `InteractsWithVaulter` and contract suites (PLAN2.P7.4)

- Added: `Drive::factory()`, `Node::factory()`, `FileRevision::factory()` (core) and `Document::factory()`
  (documents). They create through the Operations as a system actor, so quota, sequences, blobs and scan work hold;
  `forTenant()` picks the tenant. The factories are autoloaded from the packages (`autoload.psr-4`), and the
  monorepo's `autoload-dev` entry for `Vaulter\Database\Factories` is gone.
- Added: `Vaulter\Testing\InteractsWithVaulter` (`vaulterFakeStorage()`, `vaulterActingAs()`,
  `assertVaulterEventRecorded()`, `assertVaulterEventNotRecorded()`, `assertNodeVisibleTo()`,
  `assertNodeNotVisibleTo()`) and `FakeContentScanner`.
- Added: contract suites for host extensions: `FileHandlerContractTests`, `ContentScannerContractTests`,
  `PermissionFixture` with `PermissionDriverContractTests::assertSameDecisionsAsReference()`,
  `Vaulter\Drive\Testing\Contracts\ScanLifecycleContractTests` (scope, policy, fault recovery) and
  `Vaulter\Documents\Testing\Contracts\ExporterContractTests`. Each is proved on a reference and on a broken fixture.
- Added: an architecture test in core, drive, documents and media that no production class uses a `Testing` namespace.
