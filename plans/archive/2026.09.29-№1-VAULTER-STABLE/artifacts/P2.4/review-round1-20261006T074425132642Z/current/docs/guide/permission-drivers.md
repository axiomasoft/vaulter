---
title: Permission Drivers
description: The four PermissionDriver lanes — Database (default), Gate, Spatie v8 teams, and the AzGuard seam — how to select and configure each, and the separate bridge’s AzgardGuard adapter.
outline: deep
---

# Permission Drivers

The [`AccessResolver`](./authorization) owns the per-node ACL and the final decision.
The **role lane** it folds in is swappable: it is the `PermissionDriver` contract, and
Core ships `database`, `gate` and `spatie`; the separate bridge supplies `azgard`. This is where you bridge your application's identity
and role system into Vaulter.

::: info The contract is node-blind
A `PermissionDriver` never receives a `Node` and never deals in magic-string actions —
only an `Authorizable` actor and an `AccessScope` (panel + workspace coordinates + node
owner). That keeps every driver swappable and the resolver the single authority.
:::

## The PermissionDriver contract

`Vaulter\Contracts\Access\PermissionDriver`:

```php
interface PermissionDriver
{
    // Per-subject role decision, or null when the host has nothing to say.
    // Folded as roleAllow into granted = max(aclAllow, roleAllow) — it can RAISE
    // access but never override a deny (the final veto still applies).
    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision;

    // An absolute super-admin override, or null. Allow/Deny short-circuit BOTH
    // directions before any ACL walk.
    public function override(Authorizable $actor, AccessScope $scope): ?Effect;

    // Expand an actor into the Subjects whose grants apply (user + roles + teams).
    // Vaulter builds WHERE (subject_type, subject_id) IN (...) from this list.
    /** @return list<Subject> */
    public function subjectsFor(Authorizable $actor): array;

    // May the actor create nodes in a workspace? (folder/upload create path).
    public function canCreateInWorkspace(Authorizable $actor, string $workspaceType, int|string $workspaceId): bool;
}
```

A driver's `resolve()` may only **raise** access (it returns an allow `Decision` or
`null`); the deny veto stays the ACL's job. `subjectsFor()` is what lets a
`v_node_grants` row addressed to a role or team match an actor.

## Selecting a driver

The active driver is a single config key (and env var):

```php
// config/vaulter.php
'drivers' => [
    'permission' => env('VAULTER_PERMISSION_DRIVER', 'database'),
],
```

```bash
# .env
VAULTER_PERMISSION_DRIVER=database   # database | gate | spatie | azgard | null
```

The key → FQCN map is `permission_drivers`. Register your own driver by adding an FQCN
that implements `PermissionDriver` (config-cache safe — FQCNs only, no closures):

```php
// config/vaulter.php
'permission_drivers' => [
    'database' => \Vaulter\Access\Drivers\DatabasePermissionDriver::class,
    'gate'     => \Vaulter\Access\Drivers\GatePermissionDriver::class,
    'spatie'   => \Vaulter\Access\Drivers\SpatiePermissionDriver::class,
    'null'     => \Vaulter\Testing\NullPermissionDriver::class, // non-prod only
    // 'tenant' => \App\Vaulter\TenantRoleDriver::class,
],
```

The `PermissionDriverManager` resolves and memoises the driver per key; an unknown key
throws `MissingPermissionDriverException::unknownDriver()`, and `null` in production throws
`nullDriverInProduction()` — see [Authorization → fail-loud](./authorization#fail-loud-the-null-driver).

## Database — the default, standalone lane

`Vaulter\Access\Drivers\DatabasePermissionDriver` introduces **no** external role
system. Every decision is a `v_node_grants` row resolved by the `AccessResolver`; the
driver only contributes the actor's own identity to the subject-set.

| Method | Behaviour |
|---|---|
| `subjectsFor` | `[Subject::ofModel($actor)]` — just the user. |
| `override` | `null` — no super-admin bypass. |
| `resolve` | `null` — the ACL is the sole authority. |
| `canCreateInWorkspace` | `true` — per-node `CREATE` grants on the destination still apply via the resolver. |

This is the out-of-the-box lane that makes Vaulter work in a clean Laravel app with no
host authorization. To add role/team subjects without any external package, subclass it
and append `Subject` entries:

```php
final class TeamAwareRoleDriver extends DatabasePermissionDriver
{
    public function subjectsFor(Authorizable $actor): array
    {
        $subjects = parent::subjectsFor($actor); // [Subject(user)]

        foreach ($actor->teams as $team) {
            $subjects[] = new Subject(type: 'team', id: $team->id);
        }

        return $subjects;
    }
}
```

## Gate — Laravel's built-in lane

`Vaulter\Access\Drivers\GatePermissionDriver` bridges a host that already
authorizes through Gate abilities / policies. It ships with the framework — zero extra
dependencies.

- **`override`** asks a configurable super ability (default `vaulter.super`). When the
  Gate allows it, the actor short-circuits to full access; otherwise it defers (`null`,
  never a hard deny).
- **`resolve`** asks the Gate each mapped ability (low → high) and folds the **highest
  allowed level** into the `Decision`. The Gate check is node-blind — it receives the
  `AccessScope`'s `{panel, workspace_type, workspace_id}`, never a `Node`.
- **`subjectsFor`** = `[Subject(actor)]` only. The Gate exposes no enumerable role/team
  subjects, so grant rows addressed to a role/team are not matched by this lane — use
  Spatie/AzGuard for that.
- **`canCreateInWorkspace`** asks the create ability, passed `[$workspaceType, $workspaceId]`.

Every check goes through `Gate::forUser($actor)`, so the driver is stateless. A missing
ability is swallowed as "not allowed" — an unconfigured host gate never throws into the
resolver.

### Configuration

```php
// config/vaulter.php — used only when drivers.permission = 'gate'
'gate' => [
    'abilities' => [
        'reader'    => 'vaulter.view',
        'commenter' => 'vaulter.comment',
        'writer'    => 'vaulter.edit',
        'organizer' => 'vaulter.organize',
        'owner'     => 'vaulter.manage',
    ],
    'super_ability'  => env('VAULTER_GATE_SUPER_ABILITY', 'vaulter.super'),
    'create_ability' => env('VAULTER_GATE_CREATE_ABILITY', 'vaulter.create'),
],
```

The `abilities` map couples a Vaulter `GrantLevel` to a host Gate ability string. Map
only the levels you actually gate. Implement matching policies:

```php
// A host policy / Gate definition
Gate::define('vaulter.edit', fn ($user, array $context) =>
    $user->isEditorOf($context['workspace_type'], $context['workspace_id']));

Gate::define('vaulter.super', fn ($user) => $user->isAdmin());

Gate::define('vaulter.create', fn ($user, string $type, int|string $id) =>
    $user->mayCreateIn($type, $id));
```

## Spatie — roles, permissions, and v8 teams

`Vaulter\Access\Drivers\SpatiePermissionDriver` bridges a host running
`spatie/laravel-permission`, including the **v8 teams** feature. It encapsulates the
entire v8 team-switch dance so it never leaks into the resolver.

- **`subjectsFor`** = the actor + its role Subjects (+ its team Subject when teams are
  enabled), so a grant row addressed to a `role`/`team` matches.
- **`resolve`** checks each mapped permission via `$actor->hasPermissionTo($name)`,
  **team-scoped** to the workspace (`setPermissionsTeamId($scope->workspaceId)`), then
  restores the previous team id and the actor's loaded relations. The highest allowed
  level becomes the `Decision`.
- **`override`** asks a configurable super permission.
- **`canCreateInWorkspace`** asks the create permission in the workspace's team context.

::: warning Optional dependency
`spatie/laravel-permission` is an **optional** host dependency. Every runtime touch of
the Spatie API is guarded by `class_exists(PermissionRegistrar::class)`, so vaulter-core
carries no hard dependency. Selecting `spatie` without installing the package degrades
to a safe deny (`null`/`false`), never a fatal error.
:::

### Configuration

```php
// config/vaulter.php — used only when drivers.permission = 'spatie'
'spatie' => [
    'permissions' => [
        'reader'    => 'vaulter.view',
        'commenter' => 'vaulter.comment',
        'writer'    => 'vaulter.edit',
        'organizer' => 'vaulter.organize',
        'owner'     => 'vaulter.manage',
    ],
    'super_permission'  => env('VAULTER_SPATIE_SUPER_PERMISSION', 'vaulter.super'),
    'create_permission' => env('VAULTER_SPATIE_CREATE_PERMISSION', 'vaulter.create'),
    'teams'             => env('VAULTER_SPATIE_TEAMS', false),
    'role_subject_type' => 'role',
    'team_subject_type' => 'team',
],
```

`teams` falls back to this key **only** when the host's own `config('permission.teams')`
is unset — the host's Spatie config is authoritative. `role_subject_type` /
`team_subject_type` are the morph aliases that role/team grant rows are addressed to.

### The team-switch and the cache seam

The full v8 dance — capture the current team id, `setPermissionsTeamId($workspaceId)`,
check, restore in a `finally` — lives entirely inside the driver, so concurrent panels
never bleed into each other. After the host mutates an actor's roles/permissions
**outside** Spatie's own assign/remove helpers, it must reset the cache so a stale,
team-scoped permission can't grant a revoked level on the next resolve:

```php
use Vaulter\Access\Drivers\SpatiePermissionDriver;

SpatiePermissionDriver::forgetCachedPermissions($actor);
```

This is a no-op when Spatie is not installed.

## AzGuard — the per-resource seam

`Vaulter\Azgard\AzgardPermissionDriver` lives in the separate
`axioma-studio/vaulter-azgard` package; core does not depend on AzGuard/Corex.

```bash
composer require axioma-studio/vaulter-azgard
```

Its Laravel-discovered provider registers `azgard` in `vaulter.permission_drivers`,
preserving an explicit host override, and binds
`Vaulter\Azgard\Contracts\AzgardGuard` to the real adapter. Then select
`VAULTER_PERMISSION_DRIVER=azgard`. Without the installed bridge, the key is unknown
and throws `MissingPermissionDriverException`.

The composite resolution order is node-ACL → workspace-scoped role → global. This driver
owns the **workspace-scoped role** lane:

- **`override`** = `isSuperAdmin()` → `Effect::Allow`, else `null` (never a deny).
- **`resolve`** maps each ranked level's defining ability to an AzGuard permission and
  asks `hasPermissionIn($workspaceType, $workspaceId, $permission)`; the highest allowed
  level becomes the `Decision`. (`AccessScope` is node-blind, so this is the
  workspace-scoped check.)
- **`subjectsFor`** = `[Subject(actor)]` only — AzGuard *answers* checks rather than
  enumerating role-subjects. A host that addresses grant rows to AzGuard roles should
  subclass and append those Subjects.
- **`canCreateInWorkspace`** asks `hasPermissionIn($type, $id, $createPermission)`.

The level → defining-ability probes are: `Reader → View`, `Commenter → Comment`,
`Writer → Edit`, `Organizer → Delete`, `Owner → Manage`.

### Configuration

Unlike Gate/Spatie (keyed by `GrantLevel`), the AzGuard map is **action-centric** —
keyed by `Ability`:

```php
// config/vaulter-azgard.php — used only when drivers.permission = 'azgard'
return [
    'panel_id' => null, // null resolves the panel from the permission key
    'permissions' => [
        'view'     => 'documents.view',
        'download' => 'documents.download',
        'comment'  => 'documents.comment',
        'edit'     => 'documents.update',
        'create'   => 'documents.create',
        'move'     => 'documents.update',
        'copy'     => 'documents.update',
        'trash'    => 'documents.delete',
        'delete'   => 'documents.delete',
        'restore'  => 'documents.delete',
        'share'    => 'documents.manage',
        'manage'   => 'documents.manage',
    ],
    'create_permission' => env('VAULTER_AZGARD_CREATE_PERMISSION', 'documents.create'),
];
```

### The AzgardGuard adapter

`Vaulter\Azgard\Contracts\AzgardGuard` is the narrow seam the driver talks to. It
exposes exactly the three checks the driver needs — and nothing of AzGuard's own API:

```php
interface AzgardGuard
{
    // Per-resource scoped permission (the per-node lane).
    public function hasScopedPermission(string $permission, object $entity): bool;

    // Workspace-scoped permission (the lane resolve()/create actually drive).
    public function hasPermissionIn(string $contextType, int|string $contextId, string $permission): bool;

    // The global SuperAdmin wildcard.
    public function isSuperAdmin(): bool;
}
```

If the actor itself implements `AzgardGuard`, the driver uses it directly.
Otherwise the bridge provider supplies `AzgardGuardAdapter` for the current user
implementing `Vaulter\Azgard\Contracts\AzgardBackedUser`. For guests or other users,
it returns `NullAzgardGuard`, which grants no permissions. The host must add
`AzgardBackedUser` to its user model and align the morph types.

::: details Optionally overriding the supplied adapter
The bridge already binds a real adapter. A host can override `AzgardGuard`, delegating to AzGuard's
`hasScopedPermission` / Corex `ContextGuard` `hasPermissionIn` and the SuperAdmin
wildcard. The method shapes mirror AzGuard 1:1, so the adapter is a thin pass-through:

```php
// In a host service provider
$this->app->bind(\Vaulter\Azgard\Contracts\AzgardGuard::class, function ($app) {
    $actor = $app['auth']->user();

    return new class ($actor) implements \Vaulter\Azgard\Contracts\AzgardGuard {
        public function __construct(private object $actor) {}

        public function hasScopedPermission(string $permission, object $entity): bool
        {
            return $this->actor->hasScopedPermission($permission, $entity);
        }

        public function hasPermissionIn(string $contextType, int|string $contextId, string $permission): bool
        {
            return $this->actor->hasPermissionIn($contextType, $contextId, $permission);
        }

        public function isSuperAdmin(): bool
        {
            return $this->actor->isSuperAdmin();
        }
    };
});
```

**Morph alignment (host responsibility):** the workspace/owner morph types must be
registered Corex morph aliases aligned with `vaulter.ids.default=ulid` +
`corex.ids.strategy=Ulid` + `AZ_GUARD_MORPH_TYPE=ulid`. The driver passes the workspace
type string through untouched — it never resolves the alias itself.
:::

### Testing the AzGuard lane

`Vaulter\Azgard\Testing\FakeAzgardGuard` is an in-memory guard supplied by
`vaulter-azgard`. Install the bridge and its Composer dependencies even for fake-based
tests; the fake replaces live AzGuard checks, not the package's installation requirements.

```php
use Vaulter\Azgard\Testing\FakeAzgardGuard;

// grant one workspace permission
app()->bind(\Vaulter\Azgard\Contracts\AzgardGuard::class,
    fn () => FakeAzgardGuard::grantingWorkspace('team', $teamId, 'documents.update'));

// or a super-admin
app()->bind(\Vaulter\Azgard\Contracts\AzgardGuard::class,
    fn () => FakeAzgardGuard::superAdmin());
```

## Driver comparison

| | Database | Gate | Spatie | AzGuard |
|---|---|---|---|---|
| Extra dependency | none | none (built-in) | optional `spatie/laravel-permission` | `axioma-studio/vaulter-azgard` + its dependencies |
| Map keyed by | — | `GrantLevel` | `GrantLevel` | `Ability` |
| Enumerates role/team subjects | subclass | no | yes | no |
| Super-admin override | no | `vaulter.super` ability | super permission | SuperAdmin wildcard |
| Workspace-scoped | per-node grants | node-blind context | team-scoped | `hasPermissionIn` |
| Safe when not configured | n/a | yes | yes (deny) | only with the bridge installed |

## See also

- [Authorization](./authorization) — how a driver's answers are folded into the final
  decision.
- [Grants](./grants) — the `v_node_grants` rows the subject-set is matched against.
- [Multi-tenancy recipe](../guide/recipes) — end-to-end tenant + role wiring.
- ADR-0002 (authorization delegation), ADR-0007 (the AzGuard seam).
