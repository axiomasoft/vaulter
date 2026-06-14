# Permission Resolver

DocVault ships **no** authorization logic of its own. Instead, the host application
implements `DocVault\Contracts\PermissionResolver` and DocVault calls it for every action.

```php
namespace App\DocVault;

use DocVault\Contracts\PermissionResolver as PermissionResolverContract;
use DocVault\Models\Document;
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

This keeps DocVault compatible with any RBAC system — Laravel Gate/Policies, Spatie
Permission, or [AzGuard](https://github.com/axioma-studio/azguard) — without coupling to it.

## Binary attachments

For images and other binary content, implement `DocVault\Contracts\StorageAdapter` and bind
it via the `storage_adapter` config key.
