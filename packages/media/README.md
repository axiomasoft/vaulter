# Vaulter · Media (`axiomasoft/vaulter-media`)

Content-addressed blob storage layer (CAS) for Vaulter (`axiomasoft/vaulter-media`): SHA-256 deduplication, race-free staging/commit promotion, pin references, two-phase garbage collection, scrubbing, and Flysystem storage drivers on top of `axiomasoft/vaulter-core` and `axiomasoft/packman`.

## What It Owns

- **Configuration:** `config/vaulter-media.php` (`MediaOptions`, validated by `OptionsFactory`)
- **Plugin Manifest:** `MediaModule` (`vaulter.media` `ExtensionPlugin` with `WithCapabilities`, `WithConfiguration`, `WithDiagnostics`, `WithSchema`, `WithSlots`)
- **Schema & Migrations:** `v_blobs`, `v_blob_references` (`MediaSchemaSets`, `VaulterMigration`)
- **Diagnostics & Errors:** `BlobPinsCheck`, `BlobIntegrityCheck`, `MediaErrorCode`, `MediaProblemDefinitions` (`urn:packman:problem:media.*`)
- **Contracts:** `BlobStore`, `ContentStore`, `StorageDriver`, `ContentScanner`, `BlobPins`

## Contracts

- Public PHP API snapshot: [`resources/contracts/php-api.json`](resources/contracts/php-api.json)
- Error catalog: [`resources/contracts/error-codes.json`](resources/contracts/error-codes.json)
- Configuration JSON schema: [`resources/schemas/config.schema.json`](resources/schemas/config.schema.json)
