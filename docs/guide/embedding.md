# Embedding Vaulter

Vaulter is designed to drop into very different hosts — from a plain Laravel app to a
multi-tenant CoreX-based SaaS — without forking it.

## Plain Laravel (defaults)

Auto-incrementing integer keys, no tenant column, one resolver:

```php
use Vaulter\Facades\Vaulter;

Vaulter::resolvePermissionsWith(new \App\Vaulter\PermissionResolver());
```

That's it — the published migrations and default models work as-is.

## Multi-tenant / CoreX

Vaulter never scopes by tenant itself; instead you swap in your own models and switch the
key type. In `config/vaulter.php`:

```php
'id_type' => 'ulid',                 // ULID primary + foreign + morph keys
'tenant'  => ['enabled' => true, 'column' => 'tenant_id'],
'models'  => [
    'document' => \App\Models\Document::class,   // extends Vaulter\Models\Document
    // …version, folder, permission
],
```

Your model adds the tenant scope and ULID behaviour:

```php
namespace App\Models;

use Vaulter\Models\Document as BaseDocument;

class Document extends BaseDocument
{
    use \App\Concerns\BelongsToTenant; // your global TenantScope + ULID base
}
```

- `id_type => 'ulid'` makes the published migrations emit `ulid` primary keys,
  `ulidMorphs()` for the workspace/subject relations, and `foreignUlid` references.
- `tenant.enabled => true` adds the `tenant_id` column; **your** model applies the scope.
- Because Vaulter resolves models through `config('vaulter.models')`, every query, relation
  and factory uses your subclass.

## Authorization

Vaulter delegates every check to your [`PermissionResolver`](/guide/permission-resolver) and
ships computed [abilities](/guide/abilities) to the front-end. Documents attach polymorphically
to any host entity via the `workspace_type` / `workspace_id` columns, so you can scope them to
a deal, project, or organization.
