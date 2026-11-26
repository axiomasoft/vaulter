# Changelog

## Unreleased

- Retire VaulterModule/PluginModuleBridge; add native WithModuleRuntime and VaulterPluginRegistrar, canonical resource paths and single slot registration.

- Renamed package namespace to `axiomasoft/vaulter-core`.
- Migrated configuration, plugin composition, error registry, doctor diagnostics, schema sets, and testkit integration onto `axiomasoft/packman`.
- Replaced `spatie/laravel-package-tools` with `Packman\Foundation\Concerns\RegistersPackageRegistrars` and focused registrars.
