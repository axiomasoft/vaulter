# Changelog

## Unreleased

- Preserve document protocols, bindings, routes and replacement maps through native Packman declarations.

- Declared `axiomasoft/packman`, `axiomasoft/packman-plugins`, `axiomasoft/packman-schema`, and `axiomasoft/packman-testkit` integration.
- Migrated `DocumentsModule`, `DocumentsOptions`, `DocumentsErrorCode`, `DocumentsProblemDefinitions`, `DocumentsSchemaSets`, and `DocumentTypesCheck` onto Packman primitives.
- Replaced monolithic service provider setup with `RegistersPackageRegistrars` and `DocumentsPackageRegistrar`.
