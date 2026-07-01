# Vaulter · AzGuard permission bridge

`axioma-studio/vaulter-permissions-azgard` wires the [AzGuard](https://github.com/axioma-studio/azguard)
scoped-permission system (and, through it, the CoreX tenant context) into Vaulter's
composite ACL — so a host running AzGuard authorizes Vaulter documents/folders with its
existing roles and permissions.

Vaulter core carries **no** dependency on AzGuard: its `AzgardPermissionResolver` talks
only to the narrow `Vaulter\Contracts\Access\AzgardGuard` seam. This package provides the
real adapter behind that seam, keeping core installable (and PHPStan-green) with AzGuard
absent.

## How it works

```
Vaulter AccessResolver ─▶ AzgardPermissionResolver ─▶ AzgardGuard (seam)
                                                          │
                                    AzgardGuardAdapter ◀──┘  (this package)
                                                          │
                                    host User (HasAzGuard + HasScopedRoles)
```

- **`AzgardGuardAdapter`** implements the Vaulter seam and delegates to the host's
  AzGuard-backed user, translating the three checks: `hasScopedPermission` (narrowing the
  entity to an Eloquent model), `hasPermissionIn` (the workspace/tenant context lane), and
  `isSuperAdmin` (the AzGuard `*` wildcard).
- **`VaulterAzgardServiceProvider`** binds the seam from the current request's user. A
  request with no AzGuard-backed user gets the deny-all `NullAzgardGuard` — a safe
  pass-through, never a fatal.

## Install

```bash
composer require axioma-studio/vaulter-permissions-azgard
```

Select the driver in `config/vaulter.php`:

```php
'drivers' => ['permission' => 'azgard'],
```

## Host integration (the alignment this package cannot do for you)

1. **Mark your User** as AzGuard-backed for Vaulter — free if it already uses AzGuard:

   ```php
   use AzGuard\Concerns\HasAzGuard;
   use AzGuard\Concerns\HasScopedRoles;
   use Vaulter\Permissions\Azgard\Contracts\AzgardBackedUser;

   class User extends Authenticatable implements AzgardBackedUser
   {
       use HasAzGuard;      // hasPermission / hasPermissionIn
       use HasScopedRoles;  // hasScopedPermission
   }
   ```

   `AzgardBackedUser` simply composes AzGuard's own public contracts (0.2+) —
   `AzGuard\Contracts\HasPermissions` + `HasScopedRoles` — so the `HasAzGuard` +
   `HasScopedRoles` traits already declare every method: no bodies needed, only the
   `implements` clause. (Requires `axioma-studio/azguard-core: ^0.2`.)

2. **The context lane** (`hasPermissionIn`) resolves through AzGuard's bound `ContextGuard`
   — install `axioma-studio/azguard-context` (or the CoreX tenancy provider) so workspace
   checks are answered rather than falling back to a global check.

3. **Align ids / morphs** so the workspace morph AzGuard receives matches your models:
   `vaulter.ids.default=ulid` + `corex.ids.strategy=Ulid` + `AZ_GUARD_MORPH_TYPE=ulid`, and
   register your workspace/owner types as CoreX morph aliases (`EntityRegistry::register`).

4. **Map abilities → permissions** if your keys differ from the defaults
   (`documents.view`, `documents.update`, `documents.create`, …) via `config('vaulter.azgard.permissions')`.

## Config

`config/vaulter-azgard.php` (publish with `--tag=vaulter-azgard-config`):

| Key | Default | Meaning |
|---|---|---|
| `panel_id` | `null` | AzGuard panel threaded through checks; `null` lets AzGuard resolve it per permission key. |

Super-admin is AzGuard's own first-class `isSuperAdmin()` (0.2+) — the adapter forwards to
it, so the wildcard convention lives in AzGuard, not here.

## Tests

The suite proves the adapter's translation and its integration with the **real** Vaulter
`AzgardPermissionResolver` across every lane (super-admin override, workspace-role →
grant-level, create gate), plus the provider binding. It needs `axioma-studio/azguard-core`
as a path repository (an ecosystem-local concern), so it runs in the monorepo / host CI,
not Vaulter's standalone matrix.

```bash
composer install && composer test
```
