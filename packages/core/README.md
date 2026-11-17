# Vaulter Core (`axiomasoft/vaulter-core`)

Kernel for the Vaulter embeddable file drive and document management ecosystem: immutable configuration options, extension plugin wiring, schema set & migration utilities, outbox/idempotency primitives, RFC 9457 error registry, tenancy boundaries, and doctor diagnostics.

## Features

- Immutable `VaulterOptions` and typed section DTOs implementing `Packman\Config\Contracts\OptionsFactory`.
- Composable `CoreModule` implementing `Packman\Plugins\Contracts\Registry\ExtensionPlugin` (`WithConfiguration`, `WithSchema`, `WithCapabilities`, `WithSlots`, `WithDiagnostics`).
- Versioned `CoreSchemaSets` and `VaulterMigration` schema helpers with deterministic PostgreSQL support.
- `VaulterErrorCode` and `VaulterProblemDefinitions` RFC 9457 error catalog and `VaulterDoctorCheck` suite.
