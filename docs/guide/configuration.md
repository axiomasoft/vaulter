# Configuration

Publish the config with `php artisan vendor:publish --tag=vaulter-config`. It lands at
`config/vaulter.php`.

## Models (swappable)

```php
'models' => [
    'document'   => \Vaulter\Models\Document::class,
    'version'    => \Vaulter\Models\DocumentVersion::class,
    'folder'     => \Vaulter\Models\DocumentFolder::class,
    'permission' => \Vaulter\Models\DocumentPermission::class,
],
```

Point any of these at your own subclass to add behaviour (ULID keys, tenant scope, audit).
Vaulter always resolves models through this map. See [Embedding](/guide/embedding).

## Keys & tenancy

| Key | Default | Description |
|---|---|---|
| `id_type` | `id` | `id` (auto-increment) or `ulid`. Drives the published migrations. |
| `tenant.enabled` | `false` | Adds a tenant column to every table (your model applies the scope). |
| `tenant.column` | `tenant_id` | Tenant column name. |
| `table_prefix` | `v_` | Prefix for every Vaulter table (migrations + models). |

## Authorization & storage

| Key | Default | Description |
|---|---|---|
| `permission_resolver` | `null` | FQCN of your `PermissionResolver`, or register at runtime. |
| `storage_adapter` | `DefaultStorageAdapter::class` | Binary-attachment storage. |
| `share_token_generator` | `RandomShareTokenGenerator::class` | FQCN of your `ShareTokenGenerator` (short/hashed/prefixed share tokens). |

## API & documents

| Key | Default | Description |
|---|---|---|
| `route_prefix` | `api/vaulter` | Prefix for all routes. |
| `middleware` | `['api']` | Middleware applied to the routes. |
| `max_versions` | `50` | Versions kept per document (`0` = unlimited). |
| `enabled_document_types` | `['richtext','table','markdown','template']` | Allowed types. |
| `max_document_size_kb` | `5120` | Per-document size limit. |
| `exporters` | `['html' => …, 'markdown' => …]` | Map of format → `DocumentExporter` (add PDF/DOCX/custom). |

## Collaboration

```php
'collaboration' => [
    'driver' => env('VAULTER_COLLAB_DRIVER', 'reverb'), // reverb | hocuspocus
    'channel_prefix' => 'vaulter.documents',
    'drivers' => [
        'reverb'     => ['class' => \Vaulter\Collaboration\ReverbCollaborationDriver::class, /* … */],
        'hocuspocus' => ['class' => \Vaulter\Collaboration\HocuspocusCollaborationDriver::class, /* url, secret, token_ttl */],
    ],
],
```

See [Collaboration](/guide/collaboration).
