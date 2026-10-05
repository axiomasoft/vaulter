---
title: Multi-tenancy
description: How Vaulter fits into a multi-tenant Laravel app — the host-owned tenant_id column, swapping in a tenant-aware model, and aligning ulid morphs with the Corex / AzGuard ecosystem.
outline: deep
---

# Multi-tenancy

Vaulter is built to drop into a multi-tenant application, but it takes a deliberate
stance: **the host owns tenancy, Vaulter never scopes by it.** Vaulter gives you a
`tenant_id` column on its tables and threads its value through the content-addressed
store; *applying* a tenant scope — making queries see only one tenant's rows — is
your application's job, done by swapping in a tenant-aware model.

::: info The one rule
> Vaulter does not scope by `tenant_id`. Supply a tenant-aware model (via the
> `models` config) that applies your scope.
>
> — `config/vaulter.php`, the `tenant` block

This keeps Vaulter compatible with *any* tenancy strategy — single database with a
discriminator column, a tenancy package, or your own global scopes — instead of
hard-coding one.
:::

## Enabling tenancy

```php
// config/vaulter.php
'tenant' => [
    'enabled' => env('VAULTER_TENANT_ENABLED', false),
    'column'  => 'tenant_id',
],
```

- `tenant.enabled` — the global flag, read by `Config::tenantEnabled()`.
- `tenant.column` — the column name, read by `Config::tenantColumn()` (defaults to
  `tenant_id`).

### A note on the `tenant_id` column

The Drive tables (`v_storages`, `v_nodes`, `v_blobs`, `v_files`, …) **always** carry
a nullable `tenant_id` column, regardless of `tenant.enabled`. This is intentional:
the blob dedup-UNIQUE index keys on `tenant_id` under `dedup.scope = tenant`, so the
column must exist for the index to be defined. When tenancy is off, the index simply
spans a `NULL` column.

```php
// Vaulter\Schema\SchemaBuilder::tenant() — runs for every Drive table
private function tenant(Blueprint $table, IdStrategy $ids): void
{
    $ids->reference(table: $table, column: 'tenant_id', nullable: true);
}
```

The model property is declared on every Drive model
(`Vaulter\Models\Drive\Drive`, `Node`, `File`, `FileRevision`,
`Vaulter\Models\Cas\Blob`, `Vaulter\Models\Access\NodeGrant`,
`Vaulter\Models\Sharing\NodeShare`, …) as `int|string|null $tenant_id`.

::: tip
The `tenant.enabled` flag is what *your* tenant-aware models and migrations should
key on. Vaulter reads it only to decide whether to apply the optional column on the
**legacy DocVault** tables (`Vaulter\Schema\VaulterSchema::tenant()` is conditional);
the modern Drive tables emit the column unconditionally as explained above.
:::

## How `tenant_id` actually flows

Because Vaulter never scopes, it also never *invents* a tenant id. Watch where the
value comes from:

```
your tenant-aware Storage model
        │  sets tenant_id on create (a `creating` observer, a global scope, …)
        ▼
   v_storages.tenant_id
        │  IngestFileAction reads $storage->tenant_id
        ▼
   BlobStore::commit(tenantId: …)
        │  bakes it into the scope segment + dedup key
        ▼
   v_blobs.tenant_id  +  storage_key "t<id>/ab/cd/<sha256>"
```

The provisioning path proves the point — `DriveProvisioner::provision()` creates a
storage **without** a `tenant_id`:

```php
// Vaulter\Drive\DriveProvisioner::provision() — no tenant_id is set here
$storage = $this->store->create([
    'panel'        => $panel,
    'owner_type'   => $owner->getMorphClass(),
    'owner_id'     => $owner->getKey(),
    'name'         => $this->defaultName($owner),
    'disk'         => Config::defaultDisk(),
    'storage_type' => $this->storageType($panel),
    'used_bytes'   => 0,
]);
```

If you want `tenant_id` populated, your swapped-in `Storage` model must set it. From
there it propagates automatically: `Vaulter\Drive\Actions\Files\IngestFileAction` reads it
straight off the storage row and hands it to the blob store:

```php
// Vaulter\Drive\Actions\Files\IngestFileAction
$blob = $this->blobs->commit(
    stream:    $data->stream,
    tenantId:  $data->storage->tenant_id,   // ← from the storage row, host-populated
    storageId: $data->storage->id,
    disk:      $data->storage->disk,
);
```

The same pattern holds elsewhere: `Vaulter\Activity\ActivityRecorder` and
`Vaulter\Uploads\UploadSessionManager` both copy `tenant_id` off the owning
storage/node, never from a global Vaulter setting.

## Swapping in a tenant-aware model

The mechanism is the `models` map. Point any entry at your own subclass and Vaulter
resolves the model through that map everywhere:

```php
// config/vaulter.php
'models' => [
    // …
    'storage' => \App\Models\Vaulter\TenantStorage::class,
    'node'    => \App\Models\Vaulter\TenantNode::class,
    'blob'    => \App\Models\Vaulter\TenantBlob::class,
    // …
],
```

A minimal tenant-aware storage that *stamps and scopes* by the current tenant:

```php
namespace App\Models\Vaulter;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Models\Drive\Drive as BaseStorage;

class TenantStorage extends BaseStorage
{
    protected static function booted(): void
    {
        // Stamp the tenant on create — this is the value that flows into blobs.
        static::creating(function (self $storage): void {
            $storage->tenant_id ??= tenant()?->getKey();
        });

        // Scope reads to the current tenant — the part Vaulter deliberately omits.
        static::addGlobalScope('tenant', function (Builder $query): void {
            if ($tenantId = tenant()?->getKey()) {
                $query->where('tenant_id', $tenantId);
            }
        });
    }
}
```

::: tip Reach for an existing tenancy package
`tenant()` above is a stand-in. If you use a tenancy package (e.g. one that already
provides a `BelongsToTenant` trait and a current-tenant resolver), apply *its* trait
to your subclass instead of hand-rolling the scope. Vaulter does not care *how* the
scope is applied — only that the model applies one.
:::

Because the models are swappable and the `tenant_id` column already exists, you add
tenant isolation **without a Vaulter migration or core change** — the layer-mirror
of [the swappable-models design](/guide/configuration#models).

## Interplay with the dedup scope

Tenancy and the [content-addressed store](/guide/storage-and-blobs) meet at one
setting: `dedup.scope`.

```php
// config/vaulter.php
'dedup' => [
    'scope' => env('VAULTER_DEDUP_SCOPE', 'tenant'), // global|tenant|storage
],
```

For a multi-tenant install the default `tenant` is almost always what you want:

| `dedup.scope` | Behaviour in a multi-tenant app |
|---|---|
| `tenant` *(default)* | `UNIQUE (tenant_id, sha256)`. Identical bytes are stored **once per tenant**. No cross-tenant **existence leak** — tenant A can never learn that tenant B holds a given file. The `storage_key` is prefixed `t<id>/…`, so one tenant's GC can never touch another's bytes. |
| `global` | `UNIQUE (sha256)`. Bytes are deduplicated **across tenants** — cheaper storage, but a tenant could infer another tenant holds identical bytes. Choose only when that is acceptable. |
| `storage` | `UNIQUE (storage_id, sha256)`. Dedup is per storage container, a tighter boundary than per tenant. |

::: warning Pick the scope at install time
`dedup.scope` is baked into every blob's `storage_key` **and** the unique index. It
is effectively a one-way door — changing it later means rewriting keys and the index.
For tenant isolation with no existence leak, keep it at `tenant`.
:::

::: details Why `tenant` prevents an existence leak
Under `global` dedup, the first uploader of some bytes creates the object; a second
tenant uploading the *same* bytes gets a dedup hit and stores nothing — observable
through timing/accounting, which leaks that *someone* already holds those bytes.
Under `tenant` dedup the second tenant gets its own physical object, so there is
nothing to observe. The scope segment in the key (`t<id>/…`) makes this concrete on
disk.
:::

## Aligning ulid morphs with Corex / AzGuard

Vaulter defaults its primary-key strategy to **ulid** precisely so its morph keys
line up with the Corex / AzGuard ecosystem:

```php
// config/vaulter.php
'ids' => [
    'default' => env('VAULTER_ID_STRATEGY', 'ulid'), // ulid|uuid7|bigint (D9)
    // …
],
```

> Default is ulid so morph keys line up with the Corex/Azgard ecosystem.
> — `config/vaulter.php`, the `ids` block

This matters when you run the **AzGuard host-role driver** for authorization in a
tenant-scoped app. That driver
(`Vaulter\Access\Drivers\AzgardPermissionDriver`) talks only to the
Vaulter-owned `Vaulter\Contracts\Access\AzgardGuard` seam — so vaulter-core carries
**no** hard dependency on `azguard` / `corex` — and routes its workspace-scoped role
checks through `hasPermissionIn($contextType, $contextId, $permission)`, which is
the Corex `TenantContextGuard` contextual check (the tenant/workspace lane).

For those morph types to resolve correctly across packages, the host must align the
ulid morph configuration end to end:

```dotenv
# Vaulter
VAULTER_ID_STRATEGY=ulid

# AzGuard
AZ_GUARD_MORPH_TYPE=ulid
```

```php
// Corex
'ids' => ['strategy' => \Corex\…\Ulid::class],   // corex.ids.strategy = Ulid
```

…and register the workspace / owner morph types as **Corex morph aliases**
(`EntityRegistry` / Corex ADR-003), as documented on the `AzgardGuard` contract:

```php
// Vaulter\Contracts\Access\AzgardGuard (docblock)
// The $contextType / $entity morph types MUST be registered Corex morph aliases
// aligned with vaulter.ids.default=ulid + corex.ids.strategy=Ulid + AZ_GUARD_MORPH_TYPE=ulid.
```

::: info You must bind a real `AzgardGuard`
No real `AzgardGuard` binding ships by default — tests bind
`Vaulter\Testing\FakeAzgardGuard`. A production host binds a real adapter delegating
to AzGuard `hasScopedPermission` / Corex `ContextGuard` `hasPermissionIn`. See
[Authorization › Host role driver](/guide/permission-drivers) for the full driver
wiring and the `azgard` config block.
:::

## Putting it together

A typical tenant-scoped Vaulter install:

1. Set `tenant.enabled = true` and keep `tenant.column = 'tenant_id'`.
2. Keep `dedup.scope = tenant` so blobs are isolated per tenant with no existence
   leak.
3. Subclass the Vaulter models you need to scope (at least `storage`; usually `node`,
   `file`, `blob`, `activity`) and point the `models` map at them. Each subclass
   stamps `tenant_id` on create and applies your global scope.
4. If you use AzGuard for authorization, keep `ids.default = ulid` and align the
   Corex / AzGuard morph configuration as above.

From there, every upload threads the tenant through to its blob automatically, and
your model layer — not Vaulter — enforces what each tenant can see.

## See also

- [Storage & Blobs](/guide/storage-and-blobs) — the content-addressed store the
  `tenant_id` and `dedup.scope` feed into.
- [Configuration](/guide/configuration) — the full `tenant`, `dedup`, `ids` and
  `models` keys.
- [Authorization › Host role driver](/guide/permission-drivers) — the AzGuard driver and
  the `azgard` config block.
