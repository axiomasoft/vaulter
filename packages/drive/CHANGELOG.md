# Changelog

## Unreleased

- Migration 000221 adds an index for live children ordered by name and ID. Deep folder pages seek from the last returned name.

- Preserve domain activation, routes, capabilities and slots through native WithModuleRuntime declarations and immutable options.

- Declared `axiomasoft/packman`, `axiomasoft/packman-plugins`, `axiomasoft/packman-schema`, and `axiomasoft/packman-testkit` integration.
- Migrated `DriveModule`, `DriveOptions`, `DriveErrorCode`, `DriveProblemDefinitions`, `DriveSchemaSets`, and doctor checks onto Packman primitives.
- Consolidated configuration reading onto immutable `OptionsFactory` DTOs and registered facets via `DrivePackageRegistrar`.
