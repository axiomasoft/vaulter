# Changelog

## Unreleased

- `VaulterDatabase::transaction()` now retries a callback that raises `ConcurrentUpdateException` once the outer transaction has rolled back. It shares the configured attempt budget with native database retries. Errors raised after commit still propagate immediately.

- Retire VaulterModule/PluginModuleBridge; add native WithModuleRuntime and VaulterPluginRegistrar, canonical resource paths and single slot registration.

- Renamed package namespace to `axiomasoft/vaulter-core`.
- Migrated configuration, plugin composition, error registry, doctor diagnostics, schema sets, and testkit integration onto `axiomasoft/packman`.
- Replaced `spatie/laravel-package-tools` with `Packman\Foundation\Concerns\RegistersPackageRegistrars` and focused registrars.
