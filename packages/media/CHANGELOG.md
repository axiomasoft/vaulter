# Changelog

## Unreleased

- Reconcile CAS consumer wiring, DatabaseOptions injection, schedule validation and Packman doctor findings; add registry and real CAS boot regressions.

- Declared `axiomasoft/packman`, `axiomasoft/packman-plugins`, `axiomasoft/packman-schema`, and `axiomasoft/packman-testkit` integration.
- Migrated `MediaModule`, `MediaOptions`, `MediaErrorCode`, `MediaProblemDefinitions`, `MediaSchemaSets`, `BlobPinsCheck`, and `BlobIntegrityCheck` onto Packman primitives.
- Replaced monolithic provider registration with `RegistersPackageRegistrars` and `MediaPackageRegistrar`.
