# Vaulter · Drive (`axiomasoft/vaulter-drive`)

Virtual drive, hierarchical folder/file trees, access drivers (`database`, `gate`, `spatie`), quota enforcement, resumable uploads (Tus & S3 multipart), malware scanning workflows, shares, activity logs, and change feeds for Vaulter (`axiomasoft/vaulter-drive`), built on `axiomasoft/vaulter-core`, `axiomasoft/vaulter-media`, and `axiomasoft/packman`.

## What It Owns

- **Configuration:** `config/vaulter-drive.php` (`DriveOptions`, validated by `OptionsFactory`)
- **Plugin Manifest:** `DriveModule` (`vaulter.drive` `ExtensionPlugin` with `WithCapabilities`, `WithConfiguration`, `WithDiagnostics`, `WithSchema`, `WithSlots`)
- **Schema & Migrations:** `v_drives`, `v_nodes`, `v_files`, `v_file_revisions`, scan, sharing, activity, upload, and change-feed tables (`DriveSchemaSets`, `VaulterMigration`)
- **Diagnostics & Errors:** `DriveErrorCode`, `DriveProblemDefinitions` (`urn:packman:problem:drive.*`), and `Packman\Doctor` checks
- **Contracts:** `DriveHandle`, `DriveHandleFactory`, `PermissionDriver`, `FileHandler`, `UploadProtocolHandler`, `NodeContentLifecycle`, `ProfileOption`

## Contracts

- Public PHP API snapshot: [`resources/contracts/php-api.json`](resources/contracts/php-api.json)
- Error catalog: [`resources/contracts/error-codes.json`](resources/contracts/error-codes.json)
- Configuration JSON schema: [`resources/schemas/config.schema.json`](resources/schemas/config.schema.json)
