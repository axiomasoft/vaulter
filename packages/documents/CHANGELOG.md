# Changelog

## Unreleased

- Declared `axiomasoft/packman`, `axiomasoft/packman-plugins`, `axiomasoft/packman-schema`, and `axiomasoft/packman-testkit` integration with `replace` compatibility for `axioma-studio/vaulter-documents`.
- Migrated `DocumentsModule`, `DocumentsOptions`, `DocumentsErrorCode`, `DocumentsProblemDefinitions`, `DocumentsSchemaSets`, and `DocumentTypesCheck` onto Packman primitives.
- Replaced monolithic service provider setup with `RegistersPackageRegistrars` and `DocumentsPackageRegistrar`.
