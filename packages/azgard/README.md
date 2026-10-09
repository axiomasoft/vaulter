# Vaulter · AzGuard permission bridge

`axioma-studio/vaulter-azgard` wires the [AzGuard](https://github.com/axiomasoft/azguard-private)
v1 panel authorization (resources and assignment scopes) into Vaulter's
composite ACL — so a host running AzGuard authorizes Vaulter documents/folders with its
existing roles and permissions.

Vaulter core carries **no** dependency on AzGuard. This package owns the
`Vaulter\Azgard\AzgardPermissionDriver`, the narrow
`Vaulter\Azgard\Contracts\AzgardGuard` seam and its real adapter. The core
`AccessResolver` reaches the bridge through the shared `PermissionDriver` contract;
core remains installable with this package absent.

## How it works

```
Vaulter AccessResolver ─▶ AzgardPermissionDriver ─▶ AzgardGuard (seam)
                                                          │
                                    AzgardGuardAdapter ◀──┘  (this package)
                                                          │
                                    host User (AzGuard v1 HasAzGuard)
```

- **`AzgardGuardAdapter`** implements the Vaulter seam and delegates to the host's
  AzGuard v1 subject, translating the three checks: `hasScopedPermission` →
  `hasPermission(on: $entity)` (narrowing the entity to an Eloquent model),
  `hasPermissionIn` → `hasPermission(on: AssignmentScopeRef::of($type, $id))` (the
  workspace lane), and `isSuperAdmin` → AzGuard's super-admin role of the panel.
- **`AzgardPermissionMap`** maps abilities to permission keys through the live
  `Configuration\AzgardConfig` repository.
- **`VaulterAzgardServiceProvider`** registers the `azgard` driver key while preserving
  host overrides and binds the seam from the current request's user. A
  request with no AzGuard-backed user gets the deny-all `NullAzgardGuard` — a safe
  pass-through, never a fatal.

## Install

```bash
composer require axioma-studio/vaulter-azgard
```

Select the driver in `config/vaulter.php`:

```php
'drivers' => ['permission' => 'azgard'],
```

## Host integration (the alignment this package cannot do for you)

Requires AzGuard v1 (`axiomasoft/azguard: ^1.0`).

1. **Mark your User** as AzGuard-backed for Vaulter — free if it already uses AzGuard:

   ```php
   use AzGuard\Concerns\HasAzGuard;
   use Vaulter\Azgard\Contracts\AzgardBackedUser;

   class User extends Authenticatable implements AzgardBackedUser
   {
       use HasAzGuard;
   }
   ```

   `AzgardBackedUser` is AzGuard v1's own `AzGuard\Contracts\AzGuardSubject`, so the
   `HasAzGuard` trait already declares every method: no bodies needed, only the
   `implements` clause.

2. **Declare the panel** that owns the Vaulter permissions: the User is a subject model
   (`PanelBuilder::for(User::class)`), the mapped permissions are permissions of the panel,
   and every drive-owner morph alias (`team`, `project`, …) is an assignment scope type
   (`PanelBuilder::scopes(AssignmentScopePolicy::inherit(...))`). A workspace type the
   panel does not declare is denied.

3. **Register morph aliases** for the User and the workspace models: AzGuard identities
   are type aliases (`[a-z0-9][a-z0-9_.-]*`), never class names.

4. **Map abilities → permissions** if your keys differ from the defaults
   (`documents.view`, `documents.update`, `documents.create`, …) via `config('vaulter-azgard.permissions')`.

## Config

`config/vaulter-azgard.php` (publish with `--tag=vaulter-azgard-config`):

| Key | Default | Meaning |
|---|---|---|
| `panel_id` | `null` | AzGuard `guard:` of every check; `null` lets AzGuard select the panel (full name or prefix in the permission, else the request panel, else the user model's panel). |
| `permissions` | `documents.*` ability map | Ability value → permission key, read live by `AzgardPermissionMap`. |
| `create_permission` | `documents.create` | Workspace creation permission; override through `VAULTER_AZGARD_CREATE_PERMISSION`. |

Super-admin is AzGuard's own `isSuperAdmin()` — the adapter forwards to it, so the
super-admin role convention lives in AzGuard, not here.

## Tests

Three tiers:

- **Driver / shared ACL contract** — the moved driver tests use `Testing\FakeAzgardGuard`
  and retain the parent Writer subtree allow / child deny fixture and its resolver decisions.
- **Adapter / provider / Vaulter-resolver** — the adapter's translation and its integration
  with the **real** Vaulter `AzgardPermissionDriver` across every lane (super-admin
  override, workspace-role → grant-level, create gate), plus the provider binding, driven by
  a controllable user double.
- **`tests/RealAzgard/` — full real-ecosystem e2e** — a real Eloquent AzGuard v1 subject on
  a real panel (stored grants, `project` assignment scopes, the super-admin role) granted
  through AzGuard's `InteractsWithAzGuard` helpers, and the real adapter → real Vaulter
  resolver. Proves the adapter's calls actually resolve through AzGuard's own engine
  (super-admin, assignment-scope permission → Writer, tenant-wide inheritance, create gate,
  scope-precise entity check, undeclared scope type denied).

Both use `axiomasoft/azguard` from a sibling `../azguard` checkout through a path repository. The dedicated
[AzGuard workflow](../../.github/workflows/azgard.yml) clones the latest public AzGuard
HEAD without a private repository token and tests Laravel 12 and 13 independently.
Local checks use the existing sibling checkout and installed dependencies.

```bash
composer install && composer test
```
