# Changelog

## Unreleased

- Declared `axiomasoft/packman`, `axiomasoft/packman-plugins`, `axiomasoft/packman-schema`, and `axiomasoft/packman-testkit` integration with `replace` compatibility for `axioma-studio/vaulter-drive`.
- Migrated `DriveModule`, `DriveOptions`, `DriveErrorCode`, `DriveProblemDefinitions`, `DriveSchemaSets`, and doctor checks onto Packman primitives.
- Consolidated configuration reading onto immutable `OptionsFactory` DTOs and registered facets via `DrivePackageRegistrar`.
