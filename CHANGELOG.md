# Changelog

All notable changes to **Vaulter** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
This file covers the public channel; the commercial collaboration package keeps its own
`packages/collaboration/CHANGELOG.md`.

## [Unreleased]

The first release. It targets PHP 8.4 and Laravel 13 on PostgreSQL 18, MySQL 9.7 LTS or SQLite,
and Node 26 for the JavaScript packages. See [Compatibility](docs/reference/compatibility.md) for the
promise that starts with 1.0.

### Added

- `axioma-studio/vaulter-core`: the kernel — module graph, the operation catalog with its concurrency and
  idempotency policies, `Idempotency-Key` receipts, the transactional outbox and `vaulter:relay`, problem
  codes (`vaulter:errors`), the versioned REST prefix, ID strategies (ULID, UUIDv7, bigint), tenancy and
  `vaulter:install` / `vaulter:doctor`.
- `axioma-studio/vaulter-media`: content-addressed blob storage with per-tenant deduplication, pins,
  reconciliation and the integrity scrub.
- `axioma-studio/vaulter-drive`: drives, folders, files and revisions, uploads (`direct`, tus and S3
  multipart), access grants and roles, share links, trash, quotas, the change journal, activity and the
  `Vaulter` entry point.
- `axioma-studio/vaulter-documents`: TipTap documents with versions, comments, export and the
  collaborative-state contract.
- `axioma-studio/vaulter-webhooks`: outbound Standard Webhooks fed by the outbox (opt-in module).
- `axioma-studio/vaulter-filament` (Filament admin) and `axioma-studio/vaulter-azguard` (AzGuard permission
  resolver).
- `axioma-studio/vaulter`: the metapackage of core, media, drive and documents.
- `@vaulter/vue`: the TypeScript client generated from `openapi/vaulter-v1.yaml` and Vue 3 components.
