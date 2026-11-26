# Changelog

## Unreleased

- Identical concurrent blob writes now converge on PostgreSQL at repeatable-read isolation. A Vaulter transaction retries the complete write when a peer committed the blob after its snapshot. An exhausted retry budget returns the retryable `409 concurrent_update` problem.

- Reconcile CAS consumer wiring, DatabaseOptions injection, schedule validation and Packman doctor findings; add registry and real CAS boot regressions.

- Declared `axiomasoft/packman`, `axiomasoft/packman-plugins`, `axiomasoft/packman-schema`, and `axiomasoft/packman-testkit` integration.
- Migrated `MediaModule`, `MediaOptions`, `MediaErrorCode`, `MediaProblemDefinitions`, `MediaSchemaSets`, `BlobPinsCheck`, and `BlobIntegrityCheck` onto Packman primitives.
- Replaced monolithic provider registration with `RegistersPackageRegistrars` and `MediaPackageRegistrar`.
