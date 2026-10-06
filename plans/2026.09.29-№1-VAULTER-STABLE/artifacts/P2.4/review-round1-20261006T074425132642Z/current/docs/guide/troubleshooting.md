---
title: Troubleshooting
description: Common Vaulter failure modes and their fixes — missing permission driver in production, Azgard morph aliases, dedup scope, MariaDB recursive-CTE caveats, and migration order.
outline: deep
---

# Troubleshooting

The most common ways a Vaulter install trips, why each happens, and the exact fix. Each entry
maps to a real seam — see [Architecture](/guide/architecture) for the model and
[Configuration](/guide/configuration) for every key.

[[toc]]

## No permission driver in production {#no-permission-driver}

**Symptom.** The app boots fine in local/dev but throws on the first authorized request in
production:

```
[Vaulter] The open null host-role driver [null] is forbidden in production.
Why: it grants every actor full access; running it in prod would expose every node.
Fix: set config(vaulter.drivers.permission) to a real driver (database|gate|spatie|azgard)
or bind your own \Vaulter\Contracts\Access\PermissionDriver.
```

**Why.** The Drive ACL needs a `PermissionDriver` lane. The open `null` driver (every actor is a
super-admin) is deliberately env-gated: `PermissionDriverManager` throws
`MissingPermissionDriverException` whenever a **production** app resolves it, so it can never
silently open a prod install ([ADR-0007](/adr/0007-pluggable-authorization)).

**Fix.** Pick a real driver in `config/vaulter.php`:

```php
// config/vaulter.php
'drivers' => [
    'permission' => env('VAULTER_PERMISSION_DRIVER', 'database'),
],
```

```dotenv
# .env (production)
VAULTER_PERMISSION_DRIVER=database   # or gate | spatie | azgard
```

- **`database`** — the standalone default. Authorizes purely from `v_node_grants` rows, zero
  host dependency. Use it if you only need per-node grants.
- **`gate` / `spatie` / `azgard`** — bridge your existing role system; fill the matching
  `vaulter.gate.*` / `vaulter.spatie.*` / `vaulter-azgard.*` config block.
- A **custom** driver — see the [recipe](/guide/recipes#permission-driver).

::: warning Config cache hides the env you forgot
If you ran `php artisan config:cache` before setting `VAULTER_PERMISSION_DRIVER`, the cached
config still has the old value. Re-run `php artisan config:cache` (or `config:clear`) after
changing `.env`.
:::

## Azgard / Corex morph aliases not aligned {#azgard-morphs}

**Symptom.** With `drivers.permission = 'azgard'`, permission checks return `false` for users
who clearly hold the role, or a `Class "AzGuard\…" not found` style error surfaces, or grants
addressed to a workspace never match.

**Why.** The driver and `Vaulter\Azgard\Contracts\AzgardGuard` contract live
in `vaulter-azgard`, rather than core. The bridge supplies a real adapter, but the host
must add `Vaulter\Azgard\Contracts\AzgardBackedUser` to its user model and align
workspace/owner morph types with AzGuard/Corex.

**Fix.**

1. Install the bridge and its dependencies; its provider registers the `azgard` driver
   and the real adapter automatically:

   ```bash
   composer require axioma-studio/vaulter-azgard
   ```

   Add `AzgardBackedUser` to the user model implementing the AzGuard contracts through
   the `HasAzGuard` and `HasScopedRoles` traits. Bridge settings use `vaulter-azgard.*`.

2. Align the ULID morph strategy across all three systems so the morph types line up:

   ```php
   // config/vaulter.php
   'ids' => ['default' => 'ulid'],
   ```

   ```dotenv
   # .env
   VAULTER_ID_STRATEGY=ulid
   AZ_GUARD_MORPH_TYPE=ulid
   # corex.ids.strategy=Ulid in the Corex config
   ```

3. Register your workspace/owner model types as **Corex morph aliases** (the EntityRegistry).
   Vaulter passes the workspace `type` string through **untouched** — it never resolves the
   alias itself, so an unregistered alias silently never matches.

::: tip The seam mirrors AzGuard 1:1
`hasScopedPermission($perm, $entity)`, `hasPermissionIn($type, $id, $perm)` and `isSuperAdmin()`
mirror AzGuard's methods exactly, so the adapter is a thin pass-through. The mismatch is almost
always the morph type, not the adapter.
:::

## Dedup scope: cross-tenant leaks or "wrong" dedup {#dedup-scope}

**Symptom A — existence leak.** In a multi-tenant app, uploading bytes that already exist for
another tenant is observably cheaper (no new physical write) — a confused-deputy side channel.

**Symptom B — bytes not deduping where you expected**, or a GC in one scope deleting another
scope's object.

**Why.** `config('vaulter.dedup.scope')` is the boundary the `sha256` content address is unique
within, and it drives **both** the dedup-unique index and the scope-qualified storage key
`<scope>/ab/cd/<sha256>` ([ADR-0012](/adr/0012-dedup-scope)):

| Scope | Unique within | Use when |
|---|---|---|
| `tenant` (default) | `(tenant_id, sha256)` | Multi-tenant — no cross-tenant existence leak. |
| `storage` | `(storage_id, sha256)` | Each storage is its own dedup island. |
| `global` | `sha256` across the whole install | Single-tenant or fully-trusted tenants only. |

**Fix.**

```php
// config/vaulter.php
'dedup' => [
    'scope' => env('VAULTER_DEDUP_SCOPE', 'tenant'),
],
```

- Keep `tenant` (or `storage`) for any deployment where tenants don't trust each other.
- Only choose `global` consciously, for a single-tenant install or tenants that trust one
  another.

::: danger Don't change dedup scope on a populated install
The scope is baked into the physical storage key of every existing blob. Switching it later
re-namespaces new keys but leaves old objects under the old prefix — plan a migration, don't
flip the flag in place.
:::

## MariaDB: recursive-CTE descent caveats {#mariadb-cte}

**Symptom.** Tree listing/move works on PostgreSQL but a descendant query is fatal or wildly
slow on MySQL/MariaDB; or an arch test fails with
*"non-test src never uses whereHas/withCount on descendants (H.4)"*.

**Why.** The node tree is an adjacency list; descent (`descendants()` / `ancestors()`) uses
`staudenmeir/laravel-adjacency-list` recursive CTEs scoped by `storage_id`
([ADR-0005](/adr/0005-unified-node-tree)). Recursive **descendant aggregation** —
`whereHas('descendants')` or `withCount('descendants')` — generates correlated recursive
subqueries that are fatal on MariaDB and pathological elsewhere, so they are **banned from
request paths** and enforced by an arch test:

```php
// tests/Arch/ArchTest.php
test('non-test src never uses whereHas/withCount on descendants (H.4)', ...);
// matches /(whereHas|withCount)\(\s*['"]descendants['"]/ and fails the build.
```

**Fix.**

- Never write `whereHas('descendants')` / `withCount('descendants')` in domain code. Do subtree
  work by **walking** the bounded chain (the `AccessResolver` ancestor walk is the model) or with
  an explicit recursive relation, not an aggregate.
- Sibling uniqueness uses a partial unique index on `(parent_id, lower(name))` on Postgres and a
  **stored generated column** on MySQL — both ship in the migrations; you don't add them by hand.
- For million-node trees or hot ACL resolution, an `ltree`/closure-table read-model is an opt-in
  driver added only when profiling demands it — never the default.

::: tip Prefer Postgres for large trees
The recursive-CTE descent is cheapest and most predictable on PostgreSQL. MariaDB works for the
shipped paths, but if you plan very deep/wide trees, Postgres is the smoother road.
:::

## Migration order & prefix issues {#migrations}

**Symptom.** `php artisan migrate` fails on a foreign-key reference to `v_nodes` /
`v_storages` / `v_blobs`, or tables land without the `v_` prefix, or a second package's tables
collide with Vaulter's.

**Why & fix.**

- **Order.** Vaulter's migrations are timestamp-ordered (`..._000001_…` → `..._000021_…`) so the
  parent tables (`v_storages`, `v_nodes`, `v_blobs`) are created before the tables that reference
  them. The package discovers and runs its own migrations; if you **publish** them with
  `vendor:publish --tag=vaulter-migrations`, keep the published filenames' ordering intact — don't
  renumber them.

  ```bash
  php artisan vaulter:install        # publishes config + migrations, offers to run them
  # or, manually:
  php artisan vendor:publish --tag=vaulter-config
  php artisan vendor:publish --tag=vaulter-migrations
  php artisan migrate
  ```

- **Prefix.** Every Vaulter table reads `config('vaulter.table_prefix')` (default `v_`). The
  published migrations and the models both read it, so change it in **one** place. If you flip the
  prefix **after** migrating, the models look for `<newprefix>…` tables that don't exist — set the
  prefix **before** the first migrate, or rename the tables to match.

  ```php
  // config/vaulter.php
  'table_prefix' => env('VAULTER_TABLE_PREFIX', 'v_'),
  ```

- **Per-entity rename.** To rename a single table without changing the global prefix, use
  `config('vaulter.table_names')` — `SchemaBuilder` and the models resolve through it.

- **Profiles.** All profiles share the configured schema; own-table generation is removed.

## Broadcast / collaboration not connecting {#collaboration}

**Symptom.** The editor loads but real-time sync never connects, or the private channel
auth 403s.

**Why & fix.**

- **Hocuspocus driver.** The Node hub calls back into `collab/authenticate` + `collab/store`.
  Those endpoints accept **only** a valid shared secret or a signed room JWT — never open. A 401
  from the hub usually means `VAULTER_COLLAB_WEBHOOK_SECRET` (or the driver `secret`) differs
  between Laravel and the hub.

## Blob disk mismatch / missing bytes {#blob-disk}

**Symptom.** A node exists but downloading it 404s the bytes, or GC deletes objects that are
still referenced.

**Why & fix.**

- **Disk resolution.** A blob's bytes live on the storage's `disk` if it pins one, else
  `config('vaulter.storage.default_disk')` (`VAULTER_DISK`). If you change `VAULTER_DISK` after
  uploading, old blobs still point at the old disk — don't move the disk out from under existing
  blobs.
- **Never version the CAS bucket.** On S3 the CAS bucket must be **unversioned**. S3
  lifecycle/versioning as a deletion mechanism will fight Vaulter's two-phase GC. The only
  lifecycle rule to add is `AbortIncompleteMultipartUpload` ([ADR-0006](/adr/0006-content-addressed-blobs-gc)).
- **GC grace too short.** If GC seems to delete live bytes, raise `config('vaulter.gc.grace_hours')`
  to ≥ your worst-case multipart-commit latency, so an in-flight upload can resurrect a tombstoned
  blob first. See the [blob GC recipe](/guide/recipes#blob-gc).
- **Drift.** If `ref_count` looks wrong, it's a cache — run `php artisan vaulter:blobs:reconcile`
  to rebuild it from the edge table (the source of truth).

## Panel validation errors at boot {#panel-validation}

**Symptom.** The app throws on boot from `InvalidProfileException` or `UnknownProfileException`.

**Why & fix.** Check that the requested profile is registered:

- **`UnknownProfileException::forName`** — `Vaulter::for($entity, panel: 'x')` (or a route) named a
  panel that isn't registered. Register its provider FQCN in `config('vaulter.panels')`, or use
  the `default` panel.

## Empty "Last updated" in the docs {#last-updated}

**Symptom.** Building these docs yourself, the per-page *Last updated* timestamp is empty.

**Why & fix.** VitePress reads `lastUpdated` from git history. A shallow CI checkout has no
history, so the timestamp is blank. Check out with full history:

```yaml
# .github/workflows/docs.yml
- uses: actions/checkout@v4
  with:
    fetch-depth: 0   # else lastUpdated is empty
```

## See also

- [Architecture](/guide/architecture) — the layers and contracts behind these failures.
- [Recipes](/guide/recipes) — the working configurations.
- [Error Reference](/guide/errors) — every Vaulter exception, with its anchor and HTTP status.
