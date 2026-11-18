# ADR-0039: Native Packman extensions and immutable configuration

Status: accepted, 2026-11-17. Completes rollout slices B2 and C on the B1 foundation.

All eight PHP code packages declare native `ExtensionPlugin` manifests and composable
`With*` facets. There are no database-backed module definitions. `OffersModules` remains
the Vaulter host integration contract; `WithModuleRuntime` supplies domain routes,
operations, bindings, schedules and activation policy through `PluginModuleSpec`.
`VaulterModule` and `PluginModuleBridge` are retired. Package registrars use the published
`VaulterPluginRegistrar` SPI so separately installed consumers do not import kernel internals.
The core still seals the module graph before boot and refuses invalid dependency graphs.

Configuration uses closed-schema `final readonly *Options` factories. Unknown keys are
rejected, including removed webhook settings. The container recompiles snapshots when
configuration changes; objects already handed to callers stay immutable. Validation of
collaboration secrets also remains available to diagnostics and fail-closed token handling.
Model class-string options are internal members; their implementation types do not become
public API through a configuration DTO. Attributes, coded exceptions, problem definitions,
diagnostics and schema providers use the canonical Packman contracts.

AzGuard consumes `axiomasoft/azguard` 2.x. Only the Filament package depends on Filament 5
and `packman-filament`; its plugin implements `FilamentPanelPlugin` and resources use shared
authorization hooks. Host Laravel Gate decisions are preserved. Drive handles continue to
authorize snapshot rows and mutations. These resources have no separate headless panel
admission model, so there is no duplicate domain panel definition.

The eight code packages are registered in Packman, PHPStan max and Rector, with generated
PHP API, error-code and configuration-schema artifacts. The ninth Composer package
(`axiomasoft/vaulter`) is a metapackage registered in `packman.json` with `"profile": "metapackage"`
and `"type": "metapackage"`: it has no PHP namespace, `src/` root, configuration or error
catalog, and Packman's metapackage profile enforces its Composer type, README, changelog
and acyclic layer dependencies without fabricating empty code-package artifacts. `ai:sync`
manages the standard 33 local instruction artifacts.

## Integration reconciliation

The full 53-file change `56de17dfcee26aa047761bca4d1b0ba5f2c2d866` was compared with B1:

- Core resource roots now resolve to an existing canonical path. Slot definitions and
  contributions occur only in native facets; `register()` does not duplicate them.
- All media attributes, SPI kinds and doctor contracts already migrated in B1 are retained.
  Doctor findings use `remediation`; both pin and integrity checks keep their behavior.
- Blob pin wiring and the Blob model use `DatabaseOptions`. CAS store, storage driver,
  content store and model validation remain in `MediaOptions`, including invalid class
  rejection, without resurrecting the removed `ConfigValidator` or `MediaConfig` classes.
- Scheduler validation and task registration share `SchedulingRegistrar::frequencies()`
  using the canonical schedule parser. Malformed schedule roots fail at boot.
- Runtime metadata is offered alongside native facets, preserving real CAS bindings,
  commands, schedules, held references and doctor contributions for external consumers.
- The compatibility regression covers the real Packman registry, exact slot counts,
  canonical roots, implementation validation and malformed schedules. A provider boot test
  commits, reads and transactionally pins bytes through the actual CAS services with only
  core and media installed.

## Architectural budgets and migration

The kernel budget is remeasured for the immutable options, error definitions and native
extension contracts introduced by B1/B2/C. Its exact production dependencies now include
Packman and the Illuminate components it directly uses. The prohibition on sibling
Vaulter dev dependencies stays in force; Packman testkit is an external testing dependency.
I4 probe entries retain exact symbol/target/count matching and document configuration
validation or host lifecycle use. Stale live-configuration entries are removed. I13 remains
unchanged: internal implementation types are hidden instead of adding exceptions.

Consumers must change `*Config` injection to `*Options`, custom modules to native facets,
and surface attributes to `Packman\Attributes`. These changes are part of the unreleased
1.0 migration, not a compatibility promise for the retired bridge. Published configuration
keys, CAS behavior and domain activation semantics remain covered by the engine suites.
