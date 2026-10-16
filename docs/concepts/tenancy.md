---
title: Multi-tenancy
description: How Vaulter fits into a multi-tenant Laravel app — the host-owned tenant_id column, swapping in a tenant-aware model, and aligning ids and morph aliases with AzGuard.
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
'tenancy' => [
    'enabled' => env('VAULTER_TENANT_ENABLED', false),
],
```

- `tenancy.enabled` — the global flag, read by `TenancyConfig::enabled()`.
- The column is always `tenant_id` (`TenancyConfig::COLUMN`).

### A note on the `tenant_id` column

The Drive tables (`v_storages`, `v_nodes`, `v_blobs`, `v_files`, …) **always** carry
a nullable `tenant_id` column, regardless of `tenancy.enabled`. This is intentional:
the blob dedup-UNIQUE index keys on `tenant_id` under `vaulter-media.dedup.scope = tenant`, so the
column must exist for the index to be defined. When tenancy is off, the index simply
spans a `NULL` column.

```php
// Vaulter\Schema\SchemaHelpers::tenant() — runs for every Drive table
private function tenant(Blueprint $table, IdStrategy $ids): void
{
    $ids->reference(table: $table, column: 'tenant_id', nullable: true);
}
```

The model property is declared on every Drive model
(`Vaulter\Models\Drive\Drive`, `Node`, `File`, `FileRevision`,
`Vaulter\Media\Models\Cas\Blob`, `Vaulter\Models\Access\NodeGrant`,
`Vaulter\Models\Sharing\NodeShare`, …) as `int|string|null $tenant_id`.

::: tip
The `tenancy.enabled` flag is what *your* tenant-aware models and migrations should
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
    'disk'         => app(\Vaulter\Media\Configuration\MediaConfig::class)->disk(),
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
    'drive' => \App\Models\Vaulter\TenantStorage::class,
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
of [the swappable-models design](/guides/configuration#models).

## Interplay with the dedup scope

Tenancy and the [content-addressed store](/concepts/storage-and-blobs) meet at one
setting: `vaulter-media.dedup.scope`.

```php
// config/vaulter-media.php
'dedup' => [
    'scope' => env('VAULTER_DEDUP_SCOPE', 'tenant'), // global|tenant|drive
],
```

For a multi-tenant install the default `tenant` is almost always what you want.
Uniqueness uses `(scope_key, hash)`; each blob retains its scope when the default changes:

| `vaulter-media.dedup.scope` | Behaviour in a multi-tenant app |
|---|---|
| `tenant` *(default)* | Identical bytes are stored **once per tenant**. No cross-tenant **existence leak** — tenant A can never learn that tenant B holds a given file. The `storage_key` is prefixed `t<id>/…`, so one tenant's GC can never touch another's bytes. |
| `global` | Bytes are deduplicated **across tenants** — cheaper storage, but a tenant could infer another tenant holds identical bytes. Choose only when that is acceptable. |
| `drive` | Dedup is per storage container, a tighter boundary than per tenant. |

::: tip Changing the default scope
Existing blobs retain their stored `scope_key`; new uploads use the configured scope.
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

## Aligning ids and morphs with AzGuard

Vaulter defaults its primary-key strategy to **ulid** so its keys line up with the
ecosystem's host models:

```php
// config/vaulter.php
'ids' => [
    'default' => env('VAULTER_ID_STRATEGY', 'ulid'), // ulid|uuid7|bigint (D9)
    // …
],
```

This matters when you run the **AzGuard host-role driver** for authorization in a
tenant-scoped app. That driver (`Vaulter\Azgard\AzgardPermissionDriver`) talks only to the
Vaulter-owned `Vaulter\Azgard\Contracts\AzgardGuard` seam — so vaulter-core carries **no**
dependency on AzGuard — and asks its workspace-scoped questions as AzGuard v1 decisions in an
**assignment scope**: `hasPermission($permission, on: AssignmentScopeRef::of($type, $id))`,
where `$type`/`$id` are the drive owner's morph alias and key.

For those identities to match, the host aligns three things:

1. **Morph aliases.** AzGuard identities are type aliases (`[a-z0-9][a-z0-9_.-]*`), never
   class names. Register the user and every workspace model:

   ```php
   Relation::enforceMorphMap([
       'user' => User::class,
       'team' => Team::class,
   ]);
   ```

   A drive owner typed by a class name throws `InvalidAssignmentScopeException`.

2. **Assignment scope types.** Declare each workspace alias as an assignment scope type of the
   AzGuard panel (`PanelBuilder::scopes(AssignmentScopePolicy::inherit(...))`); an alias the
   panel does not declare is denied. AzGuard **tenants** (`TenantPolicy`) are a separate
   boundary: a panel with tenants needs its current tenant, and without one AzGuard denies.

3. **Host key columns.** If AzGuard stores grants for ulid keys, match its host key type
   (`azguard.ids.host_keys` / `azguard.storages.*.host_keys`) to your models' key strategy.

::: info Install the AzGuard bridge
`composer require axioma-studio/vaulter-azgard` installs the driver, real adapter and
`Vaulter\Azgard\Testing\FakeAzgardGuard`. Its provider registers the driver; the guard
is selected by the `vaulter-azgard.guard` config key; the user model must implement `Vaulter\Azgard\Contracts\AzgardBackedUser`.
Fake-based tests require the bridge's installed dependencies too. See
[Permission drivers](/extending/permission-drivers) and the `vaulter-azgard.*` configuration.
:::

## Putting it together

A typical tenant-scoped Vaulter install:

1. Set `tenancy.enabled = true`.
2. Keep `vaulter-media.dedup.scope = tenant` so blobs are isolated per tenant with no existence
   leak.
3. Subclass the Vaulter models you need to scope (at least `storage`; usually `node`,
   `file`, `blob`, `activity`) and point the `models` map at them. Each subclass
   stamps `tenant_id` on create and applies your global scope.
4. If you use AzGuard for authorization, register the morph aliases, declare the workspace
   aliases as assignment scope types of the AzGuard panel and match its host key type, as above.

From there, every upload threads the tenant through to its blob automatically, and
your model layer — not Vaulter — enforces what each tenant can see.

## See also

- [Storage & Blobs](/concepts/storage-and-blobs) — the content-addressed store the
  `tenant_id` and `vaulter-media.dedup.scope` feed into.
- [Configuration](/guides/configuration) — the full `tenant`, `dedup`, `ids` and
  `models` keys.
- [Authorization › Host role driver](/extending/permission-drivers) — the AzGuard driver and
  the `vaulter-azgard.*` configuration.
