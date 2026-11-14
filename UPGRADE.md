# Upgrade Guide

This file documents breaking changes and how to migrate through them. Entries are ordered
newest-first. See `CHANGELOG.md` for the full list of changes per release; this file only covers
changes that require action from consumers of the package.

## 1.0

**There is no migration bridge from 0.x.** Vaulter 1.0 is the first release meant for real projects:
no 0.x installation existed, so the 0.x upgrade path was removed instead of being carried forward.

- The qualified platform is PHP 8.4, Laravel 13, PostgreSQL 18 and MySQL 9.7 LTS. Laravel 12 is not a target. SQLite remains the fast test lane. There is no install to move off Laravel 12: 1.0 has not shipped.
- Install 1.0 on an empty database: `php artisan migrate` creates the schema, one `create` migration per table
  (`v_<table>`), and `migrate:rollback` drops it again. There are no data backfills, no renames of earlier tables and
  no preflight.
- The 0.x configuration keys, class names and commands are not translated or deprecated; use the keys of the
  published `config/vaulter.php`. The historical record of 0.x stays in `CHANGELOG.md`.
- The unreleased baseline includes the contract and schema corrections below. Use the current 1.0 schema and
  SPI definitions when preparing an integration; there is no compatibility bridge for earlier development snapshots.

### Corrections before the first 1.0 installation

- Custom classes implementing `Vaulter\Azgard\Contracts\AzgardGuard` must accept
  `Illuminate\Contracts\Auth\Access\Authorizable $actor` as the first argument of both permission methods and
  evaluate that actor. Do not capture the request user. The standard `AzgardBackedUser` integration and upstream
  AzGuard require no change. A Vaulter role now requires every mapped permission of that role; one Comment or Edit
  permission no longer implies its other abilities.
- Built-in `bigint` models retain native integer keys within PHP's integer range and decimal strings above it.
  This includes MySQL unsigned keys, auto-generated IDs and key-list lookups. `HasVaulterKeys` reports string
  key metadata to keep Eloquent's integer-list optimization from truncating those IDs. Code consuming
  `IdStrategy::cast()` must use the returned Eloquent cast rather than assume the literal `integer`.
- Strict webhook management includes only the exact current tenant. Null-tenant endpoints are not visible or
  adopted by tenant managers. Assign them through an explicit administrative data migration when enabling tenancy;
  an ordinary update preserves the endpoint's tenant. Every management write rechecks ownership after locking
  the stored row. Tenantless event delivery remains tenantless-only.
- Comment anchors must encode to at most 16384 JSON bytes with PHP's default escaping and at most 16 levels.
  HTTP rejects larger anchors with `422 validation_failed` on `anchor`; `AddCommentInput` rejects them with
  `InvalidArgumentException` before an operation or idempotency fingerprint is created.
- Document update, snapshot and restore receipts require their original available node. PHP retries for a missing
  or trashed node answer `node_not_found`; Edit on the drive root does not reveal the stored document result.
- The fresh media schema compares `scope_key` and `storage_key` exactly, including case and trailing spaces,
  while retaining their existing character limits. Signed local disks use staged promotion rather than the atomic
  S3 PUT path.
- The fresh outbox schema includes nullable microsecond-precision `staged_at` in UTC. The writer sets it after the event's
  sequence is allocated and before the same transaction commits. Reconciliation ages this timestamp;
  `created_at`, event envelopes and subscription eligibility keep their existing meaning.

From 1.0 on, each breaking change of a stable surface is listed here under the release that makes it.
