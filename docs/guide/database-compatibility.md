---
title: Database compatibility
description: Which databases Vaulter supports, what each engine needs, and the Postgres-specific behaviour (jsonb, bytea, advisory locks) the schema relies on.
outline: deep
---

# Database compatibility

Vaulter is **Postgres-first, portable to MySQL, and runs on SQLite**. The schema is one
shared `v_*` set of tables (see [Storage Panels](/guide/storage-panels)); nothing about it
is engine-locked, but a few features light up only on a real server. This page states what
each engine gives you and the handful of Postgres specifics the schema depends on.

## Supported engines

| Engine | Status | Use it for |
|---|---|---|
| **PostgreSQL 14+** | **Recommended.** The full test suite runs against Postgres in CI across every id strategy. | Production. Everything below works natively. |
| **SQLite** | Fully supported. The default test lane runs on in-memory SQLite. | Local development, tests, small single-file installs. |
| **MySQL 8+ / MariaDB** | Supported and portable — the schema avoids engine-specific SQL. | Existing MySQL hosts. |

::: tip PHP extension
Postgres needs the `pdo_pgsql` extension. It ships with most PHP distributions; on a stock
image install it (e.g. `docker-php-ext-install pdo_pgsql`) before pointing Vaulter at Postgres.
:::

## Why Postgres is the recommended lane

A few parts of Vaulter are a **no-op on SQLite** and only do their real work on a server —
they are exercised on Postgres in CI so they cannot regress:

- **Content-addressed blob safety.** The race-free blob commit and the destructive
  garbage-collector run their critical section under `pg_advisory_xact_lock` + `FOR UPDATE`,
  and the GC sweep runs `SERIALIZABLE` with a retry on serialization failure. On SQLite these
  collapse to the single-writer guarantee. See [Storage & Blobs](/guide/storage-and-blobs).
- **`jsonb` containment indexes.** The activity feed's `context` and the document
  version `content` are `jsonb` on Postgres with `gin (… jsonb_path_ops)` indexes for
  containment queries; other engines store plain `json`/text and skip the gin index.
- **Binary document state.** The Yjs CRDT snapshot (`yjs_state`) is a binary column. Vaulter
  stores it so it round-trips losslessly on every engine, including Postgres `bytea` (which
  otherwise truncates binary at the first NUL byte through a plain string bind).

None of this needs configuration — point Vaulter at Postgres and it applies automatically.

## Id strategy × engine

The primary-key strategy is configurable (see
[Configuration → id strategy](/guide/configuration)). All three work on every engine:

| Strategy | Column | Notes |
|---|---|---|
| `ulid` (default) | `char(26)` | Lexicographically sortable; lines up with the Corex/Azgard morph keys. |
| `bigint` | `bigserial` / auto-increment | Classic integer keys. |
| `uuid7` | native `uuid` on Postgres | Time-ordered UUIDs; uses Postgres' native `uuid` type. |

The recursive folder tree (`v_nodes.parent_id → v_nodes.id`) is a self-referencing foreign
key, and it migrates cleanly under all three strategies on every engine.

## Case-insensitive search

Node- and document-name search is **case-folded** (`lower(col) like lower(?)`), so a query
matches regardless of case on every engine — including Postgres, whose `like` is
case-sensitive (unlike SQLite/MySQL). No configuration needed.

## Spatie role grants and the typed subject key

If you use the optional [`spatie` permission driver](/guide/permission-drivers): a node grant
addressed to a spatie **role** stores that role's **primary key** in `v_node_grants.subject_id`
— the same typed handle every subject uses (a user's key, a team's key). So role grants fit the
typed `subject_id` column and work on **every database and every id strategy**, exactly like
user and permission grants. Address a role grant by the role's key (`$role->getKey()`), not its
name.

## Dedicated database connection

By default every Vaulter table and model reads/writes through the host application's default
Laravel database connection. Set `config('vaulter.connection')` (or `VAULTER_DB_CONNECTION`) to a
named connection from `config('database.connections')` to route the entire `v_*` schema — every
migration `PanelTablesBuilder` creates and every shipped model's `getConnectionName()` — onto that
connection instead, e.g. to shard Vaulter onto its own database or isolate a heavy CAS/activity
workload. Leaving the key `null` (the default) keeps the current behaviour exactly as it is today.

## Schema change policy

Vaulter is pre-`v0.1.0` today, so every table is create-only or empty for every consumer — a
breaking `ALTER` costs nothing right now. That stops being true the moment the package tags its
first release, so future schema changes to the shipped `v_*` tables follow these rules:

- **New columns are always nullable or carry a `default`.** A migration that adds a column to an
  existing table must not require a backfill before existing rows become valid again.
- **Destructive operations (`drop column`, `drop table`, a `NOT NULL` added to a column that isn't
  brand new) only ship behind an explicitly named migration and a `CHANGELOG.md` entry** calling
  out the removal/tightening — never silently folded into an unrelated feature migration.
- **`NOT NULL` on a column of a non-empty table is a two-step change**: ship a migration that
  backfills the column, release it, THEN ship the constraining migration in a later release — never
  both in the same migration.
- **No blocking `ALTER TABLE` against a populated production table without a documented
  maintenance window.** If a change cannot avoid taking a table-level lock (adding a column with a
  volatile default, rewriting a column type), the release notes must say so explicitly.

This policy governs the six entity migrations Vaulter ships (`packages/core/database/
migrations`); it does not (yet) have runtime enforcement — it is a contributor/reviewer checklist,
not a lint rule.

## Running your own tests on Postgres

The package's test suite defaults to in-memory SQLite. To exercise it against Postgres (the
way CI does), point the test connection at a server with the standard `DB_*` variables:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret \
vendor/bin/pest
```

Each `TestCase` migrates its own schema on start, so run one schema group per invocation
(the CI `test-pgsql` job splits them into per-schema steps for exactly this reason).
