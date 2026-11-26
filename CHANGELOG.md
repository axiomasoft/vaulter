# Changelog

All notable changes to **Vaulter** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
This file covers the public release channel.

## [Unreleased]

- Long-lived Laravel workers now rebuild permission drivers, transactional services, document handlers and file handlers in each request or job. Register request-dependent extensions by class or container tag. An instance explicitly supplied at application boot keeps the lifetime selected by its host.
- Scan backfills and maintenance resumes now seek directly past a tied admission sequence on PostgreSQL and SQLite. MySQL uses separate index ranges for the sequence and revision ID.

- Folder listings gain a name-order index in migration 000221. Comments and webhook feeds now page across legacy rows with a null creation timestamp.

- MySQL webhook deliveries retain their endpoint foreign key while upgrading the feed index. An existing delivery without an endpoint stops the migration and identifies the row for repair.
- Composer no longer replaces packages under the old `axioma-studio` vendor. Update package requirements to `axiomasoft`.

### Security

- Share passwords are no longer read from the query string. A plain download link of a
  password-protected share gets a five-minute, single-file grant from the new
  `POST v1/shares/{token}/unlock` (`publicShares.unlock` in `@vaulter/vue`), and wrong
  passwords are rate limited per client and link (`vaulter-share-password`, 10 a minute).

### Fixed

- `vaulter:relay:replay` accepts integer `--from` / `--to` values as well as digit strings, so a
  replay invoked from a test or a program with integer sequences no longer exits 2 `INVALID`.
- `scripts/php84` falls back to the current `php` when that interpreter is 8.4 or newer and no
  `php8.4` binary is on `PATH`, so the PHP 8.5 CI leg can run the inventory shim.
- The trusted-host workflow-policy test, which pins PHP 8.4, is skipped on the PHP 8.5 CI leg.
- `WaitForLsn::supportsWaitForLsn` is asserted true from PostgreSQL 19, where the feature exists;
  18 still falls back to the primary.
- I4 and I13 allowlists match the current source (stale package-registrar probes removed; host
  lifecycle, CORS, tenancy restore, WHATWG URL and live document-options probes documented).
  `DocumentWriteMode` is public API, because it already appears on the prepared collaborative
  transition. `DocumentsManager` takes the container so `forContext` no longer calls `app()`.
- The scan backfill and `vaulter:scan:rescan` page `v_file_revisions` through the new
  `v_file_revisions_scan_admission_idx` (`scan_admission_sequence, id`, migration
  `0001_01_01_000220`) with a leading `scan_admission_sequence >= ?` bound, so a deep page seeks
  instead of re-reading the table: 0.05 ms instead of 40 ms at 1M revisions on PostgreSQL. The
  index alone is not enough: with the old OR-only predicate the planner walks it from the start
  (138 ms).
- A malformed, forged or stale pagination cursor on the document revisions and comments, file
  revisions, webhook endpoints and webhook deliveries lists is refused with 422
  `validation_failed` (`errors.cursor`), as the node, grant and share listings already do, instead
  of failing the query with a 500. The cursor format is unchanged. A revision-history cursor issued
  before the history was ordered by revision number alone (base `5cf3cfd2`, whose commit message
  calls such cursors "refused as invalid") returned a 500 until this fix; it is now refused as
  described there. A file-revision cursor of the earlier `(number, id)` order keeps paging.

### Packman migration

- Register Drive configuration diagnostics through Packman's supported SPI and give the shared registry a unique vaulter.config.valid check without changing local vaulter:doctor identifiers.

- Complete native extensions, immutable closed-schema options, canonical errors, diagnostics and schema registration across all PHP packages.
- Move the AzGuard adapter to `axiomasoft/azguard` 2.x and Filament 5 resources to shared Packman authorization.
- Retire the temporary module bridge; preserve `OffersModules` and publish the native runtime registration SPI.
- Reconcile integration commit `56de17df`: canonical resources, single slot registration, valid CAS wiring, scheduling and doctor findings.
- Register eight code packages and the umbrella metapackage in the tooling; generate API, error and config contracts and the 33 AI artifacts.

See [ADR-0039](docs/adr/0039-native-packman-extensions.md) for consumer migration and reconciliation details.


The first release. It targets PHP 8.4 and Laravel 13 on PostgreSQL 18, MySQL 9.7 LTS or SQLite,
and Node 26 for the JavaScript packages. See [Compatibility](docs/reference/compatibility.md) for the
promise that starts with 1.0.

### Fixed

- Media doctor contributions use the canonical Packman contract and finding values, so installing
  media alongside the Packman-based core produces a valid graph and activates blob bindings.
- Media consumes the current immutable database options and schedule parser, and validates its own
  configured implementations without the removed core configuration wrappers.
- The core plugin declares its Packman slots once through its declarative facets.
- The core plugin publishes a canonical absolute resource root without parent-directory segments.

### Added

- `axiomasoft/vaulter-core`: the kernel — module graph, the operation catalog with its concurrency and
  idempotency policies, `Idempotency-Key` receipts, the transactional outbox and `vaulter:relay`, problem
  codes (`vaulter:errors`), the versioned REST prefix, ID strategies (ULID, UUIDv7, bigint), tenancy and
  `vaulter:install` / `vaulter:doctor`.
- `axiomasoft/vaulter-media`: content-addressed blob storage with per-tenant deduplication, pins,
  reconciliation and the integrity scrub.
- `axiomasoft/vaulter-drive`: drives, folders, files and revisions, uploads (`direct`, tus and S3
  multipart), access grants and roles, share links, trash, quotas, the change journal, activity and the
  `Vaulter` entry point.
- `axiomasoft/vaulter-documents`: TipTap documents with versions, comments, export and the
  collaborative-state contract.
- `axiomasoft/vaulter-webhooks`: outbound Standard Webhooks fed by the outbox (opt-in module).
- `axiomasoft/vaulter-filament` (Filament admin) and `axiomasoft/vaulter-azguard` (AzGuard permission
  resolver).
- `axiomasoft/vaulter`: the metapackage of core, media, drive and documents.
- `@vaulter/vue`: the TypeScript client generated from `openapi/vaulter-v1.yaml` and Vue 3 components.
