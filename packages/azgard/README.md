# Vaulter · AzGuard permission bridge

`axioma-studio/vaulter-azgard` wires the [AzGuard](https://github.com/axiomasoft/azguard-private)
v1 panel authorization (resources and assignment scopes) into Vaulter's
composite ACL — so a host running AzGuard authorizes Vaulter documents/folders with its
existing roles and permissions.

Neither the Vaulter kernel nor the drive carries a dependency on AzGuard. This package owns the
`Vaulter\Azgard\AzgardPermissionDriver`, the narrow
`Vaulter\Azgard\Contracts\AzgardGuard` seam and its real adapter. The drive's
`AccessResolver` reaches the bridge through the `Vaulter\Drive\Contracts\Access\PermissionDriver` contract; the bridge
requires `axioma-studio/vaulter-drive` (and, through it, core and media), and the drive stays installable with this
package absent.

## How it works

```
Drive AccessResolver ─▶ AzgardPermissionDriver ─▶ AzgardGuard (seam)
                                                          │
                                    AzgardGuardAdapter ◀──┘  (this package)
                                                          │
                                    host User (AzGuard v1 HasAzGuard)
```

- **`AzgardGuardAdapter`** implements the Vaulter seam and delegates to each check's explicit
  actor. It stores only the panel selector, so one configured adapter can serve several actors. It delegates to the host's
  AzGuard v1 subject, translating the two checks into AzGuard decisions:
  `hasScopedPermission` → `hasPermission(on: $entity)` (narrowing the entity to an
  Eloquent model) and `hasPermissionIn` → `hasPermission(on: AssignmentScopeRef::of($type, $id))`
  (the workspace lane).
- **`AzgardPermissionMap`** maps abilities to permission keys through the live
  `Configuration\AzgardConfig` repository.
- **`VaulterAzgardServiceProvider`** offers the `azgard` driver while preserving host
  overrides. It creates no request-bound guard. The optional configured guard is a capability;
  without it the driver adapts an AzGuard-backed actor and grants nothing for other actors.
- **`AzgardPermissionDriver` answers for the decision's actor, not for the request.** It uses
  the actor itself when it implements `AzgardGuard`; else a guard the **host** bound in the
  container (the host's override receives the actor as a required argument); else an
  `AzgardGuardAdapter` when the actor is an `AzgardBackedUser`
  (with `panel_id`). Every check passes that actor explicitly: an
  acting-as/preview check cannot inherit the request user's rights, and a call with no request
  user (the collaboration hub resolves the token subject itself) is decided by the actor's own
  AzGuard rights.

## Install

```bash
composer require axioma-studio/vaulter-azgard
```

Select the driver (`vaulter-drive.access.permission_driver`):

```dotenv
VAULTER_PERMISSION_DRIVER=azgard
```

## Host integration (the alignment this package cannot do for you)

Requires AzGuard v1 (`axiomasoft/azguard`). AzGuard publishes no tagged release, so the
bridge pins one commit: `dev-main#9a6dc0e00c82e48d587d7763baeb2b5aa28c08ba`.

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
| `guard` | `null` | Optional class implementing the actor-aware `AzgardGuard`; every check must use its explicit actor. |
| `permissions` | `documents.*` ability map | Ability value → permission key, read live by `AzgardPermissionMap`. |
| `create_permission` | `documents.create` | Workspace creation permission; override through `VAULTER_AZGARD_CREATE_PERMISSION`. |

There is no super-admin override: AzGuard's `isSuperAdmin()` is not a decision. An AzGuard
super-admin gets the **owner** role through the same workspace decisions, so AzGuard's scope
policy (`isolated` keeps a tenant-wide super-admin out of a workspace), restrictions,
policies and tenant rules all hold.

A Vaulter role is granted only if the actor holds **every mapped ability** of that role's
registered version. A missing mapping refuses that role. For example, `documents.view`
and `documents.comment` without `documents.download` yield only `previewer`: neither
Download nor Comment is added. Comment alone grants no role. This conservative bridge
returns complete Vaulter roles; it does not represent arbitrary partial ability sets.
Node grants remain independent and are still unioned by the drive's resolver.

Previously one defining permission expanded to a whole role. Hosts relying on that
expansion must grant the complete role bundle or deliberately map its abilities to the
host permission that represents that bundle. Review those mappings before upgrading.

Custom implementations of **Vaulter's** `AzgardGuard` must add the required actor argument:

```php
public function hasScopedPermission(Authorizable $actor, string $permission, object $entity): bool;
public function hasPermissionIn(Authorizable $actor, string $contextType, int|string $contextId, string $permission): bool;
```

Resolve permissions for that actor, including when the request has no authenticated user.
Do not read `Auth::user()` or change global authentication to perform these checks.
The standard `AzgardBackedUser` / `HasAzGuard` integration needs no method changes;
the upstream AzGuard package and its interfaces are unchanged.

Misconfiguration fails loud rather than deny: a drive-owner type that is not an AzGuard
alias throws `InvalidAssignmentScopeException`, and an unselectable panel throws AzGuard's
panel exception. With the default map the panel's resource prefix must not be `documents`.

## Tests

Three tiers:

- **Driver / shared ACL contract** — the moved driver tests use `Testing\FakeAzgardGuard`
  and retain the parent Writer subtree allow / child deny fixture and its resolver decisions.
- **Adapter / provider / Vaulter-resolver** — the adapter's translation and its integration
  with the **real** Vaulter `AzgardPermissionDriver` across every lane (workspace-role →
  grant-level, super-admin → owner, create gate), plus the provider binding, driven by
  a controllable user double.
- **`tests/RealAzgard/` — full real-ecosystem e2e** — a real Eloquent AzGuard v1 subject on
  a real panel (stored grants, `project` assignment scopes, the super-admin role) granted
  through AzGuard's `InteractsWithAzGuard` helpers, and the real adapter → real Vaulter
  resolver. Proves the adapter's calls actually resolve through AzGuard's own engine
  (super-admin → owner, complete assignment-scope bundle → Writer, tenant-wide inheritance,
  create gate, scope-precise entity check, undeclared scope type denied).
- **`tests/RealAzgardIsolated/`** — the same engine on an `isolated` panel with a lock
  restriction: a tenant-wide super-admin gets nothing inside a workspace, a workspace
  super-admin gets owner there only, and a locked account is denied despite super-admin.

Both use `axiomasoft/azguard` from a sibling `../azguard` checkout through a path repository
(the AzGuard monorepo keeps the package in `packages/core`, which a Composer VCS repository
cannot address). The path repository installs whatever the sibling checkout holds, so keep
it at the pinned commit locally. CI checks out that same commit and tests the package on Laravel 13.

```bash
composer install && composer test
```
