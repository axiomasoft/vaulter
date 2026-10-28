# Vaulter Tenant binding

`axioma-studio/vaulter-tenant` is an optional adapter for an existing Tenant runtime.
Vaulter core, media and Drive retain their neutral contracts. This adapter has no
CoreX dependency and does not create a physical catalog, schema writer or runtime.

Install the bridge alongside `axioma-studio/vaulter-drive`. Configure the host's
single Tenant runtime, context resolver, binding verifier, central database queue
and immutable `QueueRegistry` before any provider boots. The host must provide a
fresh `Vaulter\Tenant\Contracts\OwnerMapping` implementation: look up the persisted
owner by stable type/key and return its `Tenant\Data\IsolationIdentity`. Do not use
the ambient selection as the owner mapping.

```php
$config->set('vaulter.tenancy.enabled', true);
$config->set('vaulter.tenancy.resolver', \Vaulter\Tenant\TenantResolver::class);
$config->set('vaulter.ids.host_keys', 'string');
$config->set('vaulter.database.connection', 'tenant');

$config->set('vaulter-tenant.schema_connection', 'schema');
$config->set('vaulter-tenant.tenant_columns', [
    'v_drives' => 'tenant_id',
    'v_nodes' => 'tenant_id',
    'v_blobs' => 'tenant_id',
]);
```

`schema` is an explicit read-only setup/catalog connection to the provisioned
schema. Apply each owning Vaulter module's migrations through the host's setup
workflow before application boot. Boot verifies the actual configured tenant
columns are `varchar(64)` or wider. Add every relevant installed tenant column
when changing table names or host models. Runtime credentials need only normal
DML and metadata access.

Both neutral resolver interfaces resolve to the same configured resolver object.
The scalar is `t1.` plus unpadded base64url of a byte containing the namespace
length, followed by namespace and ID bytes. Namespace is at most 32 bytes; their
combined length is at most 44 bytes, producing at most 63 ASCII bytes. Unsupported
identities fail before writes. IDs retain exact case and leading zeros. Missing
and Central refuse ordinary Vaulter work.

For a fixed namespace set `vaulter-tenant.namespace` and allow exactly that one
namespace in the host queue registry. A second namespace fails boot. The qualified
composition uses `laravel-pg-database`; it does not advertise the kernel `single`
profile, a full catalog restore/replay profile, S3, or public raw filesystem paths.

Include the bridge's deferred handler definition in the host `QueueRegistry`:

```php
$handlers[\Vaulter\Tenant\Jobs\TenantJobPublisher::HANDLER]
    = \Vaulter\Tenant\Jobs\TenantJobPublisher::handler($app);
```

Construct the registry once with these handlers, approved namespaces/profiles,
the central profile and a host-owned signing key. Do not resolve a second registry
or rebind it after boot. The handler restores the verified target before the
owning Drive factory, tenant validator, actor reread and effect. Task factories
remain in `Vaulter\Drive\Jobs\DriveJobTasks`; the bridge uses only the neutral
`Vaulter\Jobs\JobRegistry`.

`DeleteDriveAction` and `ScanWorkReconciler` prepare a neutral `PreparedJob` before
registering their afterCommit callback. Purge continuation retains its original
`PublicationContext`. Publication freshly checks the frozen target and purpose:
it enqueues that target or refuses. Unique-until-processing locks include the
resource, incarnation, task and exact operation refs, use the explicit central
store, and release only the delivering lock owner's token. The default neutral
framework publisher is available with tenancy disabled; strict hosts must supply
their own publisher.

For guarded local media IO, select the `vaulter-tenant` filesystem driver on the
Vaulter disk and include its public participant in the host `tenant.compose` hook:

```php
$config->set('filesystems.disks.local', ['driver' => 'vaulter-tenant']);
\Vaulter\Tenant\Storage\StorageFacility::compose($builder, $app, ['local']);
```

Existing handles keep their permanent generation check. Sequential PHP read
streams adapt the public tracked stream, hold its lease until `fclose`, and must
close inside the frame. Seeking after consumption and raw `path()` are unavailable.
A leaked stream prevents switching; a leaked frame at a managed boundary poisons
the runtime. Do not continue processing after a poisoned boundary.

The development monorepo can resolve Tenant from `../tenant/packages/*`.
Qualification uses independent, fresh archive installs on PHP 8.4/Laravel 12 and
13, real PostgreSQL 16/Redis 7, actual Drive operations, workers, file/Redis HTTP
sessions and source cohort rollback. These technical checks are not a package
release or a license/support/security maturity claim.
