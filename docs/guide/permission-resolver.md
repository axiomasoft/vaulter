# Permission Resolver

Vaulter ships **no** authorization logic of its own. Instead, the host application
implements `Vaulter\Contracts\PermissionResolver` and Vaulter calls it for every action.

```php
namespace App\Vaulter;

use Vaulter\Contracts\PermissionResolver as PermissionResolverContract;
use Vaulter\Models\Document;
use Illuminate\Contracts\Auth\Authenticatable;

final class PermissionResolver implements PermissionResolverContract
{
    public function can(Authenticatable $user, string $action, Document $document): bool
    {
        // $action is one of: view | edit | comment | manage | delete
        return $user->can($action, $document);
    }

    public function canCreate(Authenticatable $user, string $workspaceType, int $workspaceId): bool
    {
        return $user->can('create', [$workspaceType, $workspaceId]);
    }
}
```

This keeps Vaulter compatible with any RBAC system — Laravel Gate/Policies, Spatie
Permission, or [AzGuard](https://github.com/axioma-studio/azguard) — without coupling to it.

## Binary attachments

For images and other binary content, implement `Vaulter\Contracts\StorageAdapter` and bind
it via the `storage_adapter` config key.
