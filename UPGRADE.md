# Upgrade Guide

This file documents breaking changes and how to migrate through them. Entries are ordered
newest-first. See `CHANGELOG.md` for the full list of changes per release; this file only covers
changes that require action from consumers of the package.

## 1.0

**There is no migration bridge from 0.x.** Vaulter 1.0 is the first release meant for real projects:
no 0.x installation existed, so the 0.x upgrade path was removed instead of being carried forward.

- Install 1.0 on an empty database: `php artisan migrate` creates the schema, one `create` migration per table
  (`v_<table>`), and `migrate:rollback` drops it again. There are no data backfills, no renames of earlier tables and
  no preflight.
- The 0.x configuration keys, class names and commands are not translated or deprecated; use the keys of the
  published `config/vaulter.php`. The historical record of 0.x stays in `CHANGELOG.md`.
- REST v1, the PHP operations and the schema of the installed tables are the same as in the last 0.x release
  candidate; only the way the schema is created changed.

From 1.0 on, each breaking change of a stable surface is listed here under the release that makes it.
