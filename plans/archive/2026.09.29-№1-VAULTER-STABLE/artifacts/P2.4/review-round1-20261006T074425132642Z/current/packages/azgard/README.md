# Vaulter · AzGuard permission bridge

`axioma-studio/vaulter-azgard` wires the [AzGuard](https://github.com/axiomasoft/azguard-private)
scoped-permission system (and, through it, the CoreX tenant context) into Vaulter's
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
                                    host User (HasAzGuard + HasScopedRoles)
```

- **`AzgardGuardAdapter`** implements the Vaulter seam and delegates to the host's
  AzGuard-backed user, translating the three checks: `hasScopedPermission` (narrowing the
  entity to an Eloquent model), `hasPermissionIn` (the workspace/tenant context lane), and
  `isSuperAdmin` (the AzGuard `*` wildcard).
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

1. **Mark your User** as AzGuard-backed for Vaulter — free if it already uses AzGuard:

   ```php
   use AzGuard\Concerns\HasAzGuard;
   use AzGuard\Concerns\HasScopedRoles;
   use Vaulter\Azgard\Contracts\AzgardBackedUser;

   class User extends Authenticatable implements AzgardBackedUser
   {
       use HasAzGuard;      // hasPermission / hasPermissionIn
       use HasScopedRoles;  // hasScopedPermission
   }
   ```

   `AzgardBackedUser` simply composes AzGuard's own public contracts from the 0.3 line —
   `AzGuard\Contracts\HasPermissions` + `HasScopedRoles` — so the `HasAzGuard` +
   `HasScopedRoles` traits already declare every method: no bodies needed, only the
   `implements` clause. The current Composer requirement is
   `axioma-studio/azguard-core: dev-main`; the bridge follows AzGuard's public main branch.

2. **The context lane** (`hasPermissionIn`) resolves through AzGuard's bound `ContextGuard`
   — install `axioma-studio/azguard-context` (or the CoreX tenancy provider) so workspace
   checks are answered rather than falling back to a global check.

3. **Align ids / morphs** so the workspace morph AzGuard receives matches your models:
   `vaulter.ids.default=ulid` + `corex.ids.strategy=Ulid` + `AZ_GUARD_MORPH_TYPE=ulid`, and
   register your workspace/owner types as CoreX morph aliases (`EntityRegistry::register`).

4. **Map abilities → permissions** if your keys differ from the defaults
   (`documents.view`, `documents.update`, `documents.create`, …) via `config('vaulter-azgard.permissions')`.

## Config

`config/vaulter-azgard.php` (publish with `--tag=vaulter-azgard-config`):

| Key | Default | Meaning |
|---|---|---|
| `panel_id` | `null` | AzGuard panel threaded through checks; `null` lets AzGuard resolve it per permission key. |
| `permissions` | `documents.*` ability map | Ability value → permission key, read live by `AzgardPermissionMap`. |
| `create_permission` | `documents.create` | Workspace creation permission; override through `VAULTER_AZGARD_CREATE_PERMISSION`. |

Super-admin is AzGuard's own first-class `isSuperAdmin()` (0.2+) — the adapter forwards to
it, so the wildcard convention lives in AzGuard, not here.

## Tests

Three tiers:

- **Driver / shared ACL contract** — the moved driver tests use `Testing\FakeAzgardGuard`
  and retain the parent Writer subtree allow / child deny fixture and its resolver decisions.
- **Adapter / provider / Vaulter-resolver** — the adapter's translation and its integration
  with the **real** Vaulter `AzgardPermissionDriver` across every lane (super-admin
  override, workspace-role → grant-level, create gate), plus the provider binding, driven by
  a controllable user double.
- **`tests/RealAzgard/` — full real-ecosystem e2e** — a real Eloquent AzGuard user with real
  roles / DB-backed **scoped roles** / grant sources, the real **azguard-context**
  `ContextGuard`, and the real adapter → real Vaulter resolver. Proves the adapter's calls
  actually resolve through AzGuard's own engine (super-admin wildcard, workspace-context
  permission → Writer, create gate, scope-precise entity check).

Both use `axioma-studio/azguard-core` and `axioma-studio/azguard-context` from a sibling
`../azguard` checkout through path repositories. The dedicated
[AzGuard workflow](../../.github/workflows/azgard.yml) clones the latest public AzGuard
HEAD without a private repository token and tests Laravel 12 and 13 independently.
Local checks use the existing sibling checkout and installed dependencies.

```bash
composer install && composer test
```
