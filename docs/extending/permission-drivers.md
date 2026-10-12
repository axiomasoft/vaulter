---
title: Permission Drivers
description: The four PermissionDriver lanes — Database (default), Gate, Spatie v8 teams, and the AzGuard seam — how to select and configure each, and the separate bridge’s AzgardGuard adapter.
outline: deep
---

# Permission Drivers

The [`AccessResolver`](/concepts/access-model) owns the per-node ACL and the final decision.
The **role lane** it folds in is swappable: it is the `PermissionDriver` contract, and
Core ships `database`, `gate` and `spatie`; the separate bridge supplies `azgard`. This is where you bridge your application's identity
and role system into Vaulter.

::: info The contract is node-blind
A `PermissionDriver` never receives a `Node` and never deals in magic-string actions —
only an `Authorizable` actor and an `AccessScope` (profile + drive coordinates + tenant). That keeps every driver swappable and the resolver the single authority.
:::

## The PermissionDriver contract

`Vaulter\Contracts\Access\PermissionDriver`:

<!-- snippet: signature Vaulter\Contracts\Access\PermissionDriver -->

```php
interface PermissionDriver
{
    // Per-subject role decision, or null when the host has nothing to say.
    // The role the host grants here (Decision{Role}), or null. It is UNIONED with the
    // node grants — it can RAISE access but never lowers it.
    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision;

    // An absolute super-admin override, or null. Allow/Deny short-circuit BOTH
    // directions before any ACL walk.
    public function override(Authorizable $actor, AccessScope $scope): ?Effect;

    // Expand an actor into the Subjects whose grants apply (user + roles + teams).
    // Vaulter builds WHERE (subject_type, subject_id) IN (...) from this list.
    /** @return list<Subject> */
    public function subjectsFor(Authorizable $actor): array;

    // May the actor create nodes in a workspace? (folder/upload create path).
    public function canCreateIn(Authorizable $actor, AccessScope $scope): bool;
}
```

A driver's `resolve()` may only **raise** access (it returns an allow `Decision` or
`null`); an administrative block is `override()`'s job, never a `Decision`. A driver that
grants several roles returns `Decision::union($roles)`. `subjectsFor()` is what lets a
`v_node_grants` row addressed to a role or team match an actor.

## Selecting a driver

The active driver is a single config key (and env var):

<!-- recipe: packages/core/tests/Recipes/PermissionDriverRecipeTest.php#permission-driver-select -->

```php
// config/vaulter.php
'access' => [
    'permission_driver' => env('VAULTER_PERMISSION_DRIVER', 'database'),
],
```

<!-- snippet: shell -->

```bash
# .env
VAULTER_PERMISSION_DRIVER=database   # database | gate | spatie | azgard | null
```

The key → FQCN map is `access.permission_drivers`. Register your own driver by adding an FQCN
that implements `PermissionDriver` (config-cache safe — FQCNs only, no closures):

<!-- recipe: packages/core/tests/Recipes/PermissionDriverRecipeTest.php#permission-driver-map -->

```php
use Vaulter\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Access\Drivers\GatePermissionDriver;
use Vaulter\Access\Drivers\SpatiePermissionDriver;
use Vaulter\Testing\NullPermissionDriver;

// config/vaulter.php
'access' => ['permission_drivers' => [
    'database' => DatabasePermissionDriver::class,
    'gate' => GatePermissionDriver::class,
    'spatie' => SpatiePermissionDriver::class,
    'null' => NullPermissionDriver::class, // non-prod only
    // 'tenant' => \App\Vaulter\TenantRoleDriver::class,
]],
```

The `PermissionDriverManager` resolves and memoises the driver per key; an unknown key
throws `MissingPermissionDriverException::unknownDriver()`, and `null` in production throws
`nullDriverInProduction()` — see [Authorization → fail-loud](/concepts/access-model#fail-loud-the-null-driver).

## Database — the default, standalone lane

`Vaulter\Access\Drivers\DatabasePermissionDriver` introduces **no** external role
system. Every decision is a `v_node_grants` row resolved by the `AccessResolver`; the
driver only contributes the actor's own identity to the subject-set.

| Method | Behaviour |
|---|---|
| `subjectsFor` | `[Subject::ofModel($actor)]` — just the user. |
| `override` | `null` — no super-admin bypass. |
| `resolve` | `null` — the ACL is the sole authority. |
| `canCreateIn` | `true` — per-node `CREATE` grants on the destination still apply via the resolver. |

This is the out-of-the-box lane that makes Vaulter work in a clean Laravel app with no
host authorization. To add role/team subjects without any external package, subclass it
and append `Subject` entries:

<!-- snippet: host -->

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
  never a block).
- **`resolve`** asks the Gate each mapped role's ability and returns the **union of the
  roles** it allows as the `Decision`. The Gate check is node-blind — it receives the
  `AccessScope`'s `{profile, drive_owner, drive_id, tenant_id}`, never a `Node`.
- **`subjectsFor`** = `[Subject(actor)]` only. The Gate exposes no enumerable role/team
  subjects, so grant rows addressed to a role/team are not matched by this lane — use
  Spatie/AzGuard for that.
- **`canCreateIn`** asks the create ability, passed the same context array.

Every check goes through `Gate::forUser($actor)`, so the driver is stateless. A missing
ability is swallowed as "not allowed" — an unconfigured host gate never throws into the
resolver.

### Configuration

<!-- snippet: config -->

```php
// config/vaulter.php — used only when access.permission_driver = 'gate'
'access' => ['gate' => [
    'abilities' => [
        'previewer' => 'vaulter.preview',
        'reader'    => 'vaulter.view',
        'commenter' => 'vaulter.comment',
        'writer'    => 'vaulter.edit',
        'organizer' => 'vaulter.organize',
        'owner'     => 'vaulter.manage',
    ],
    'super_ability'  => env('VAULTER_GATE_SUPER_ABILITY', 'vaulter.super'),
    'create_ability' => env('VAULTER_GATE_CREATE_ABILITY', 'vaulter.create'),
]],
```

The `abilities` map couples a Vaulter role key to a host Gate ability string. Map
only the roles you actually gate; a key that is not a role throws `UnknownRoleException`. Implement matching policies:

<!-- snippet: host -->

```php
// A host policy / Gate definition
Gate::define('vaulter.edit', fn ($user, array $context) =>
    $user->isEditorOf($context['drive_owner']->type, $context['drive_owner']->id));

Gate::define('vaulter.super', fn ($user) => $user->isAdmin());

Gate::define('vaulter.create', fn ($user, array $context) =>
    $user->mayCreateIn($context['drive_owner']->type, $context['drive_owner']->id));
```

## Spatie — roles, permissions, and v8 teams

`Vaulter\Access\Drivers\SpatiePermissionDriver` bridges a host running
`spatie/laravel-permission`, including the **v8 teams** feature. It encapsulates the
entire v8 team-switch dance so it never leaks into the resolver.

- **`subjectsFor`** = the actor + its role Subjects (+ its team Subject when teams are
  enabled), so a grant row addressed to a `role`/`team` matches.
- **`resolve`** checks each mapped permission via `$actor->hasPermissionTo($name)`,
  **team-scoped** to the workspace (`setPermissionsTeamId($scope->driveOwner->id)`), then
  restores the previous team id and the actor's loaded relations. The union of the
  allowed roles becomes the `Decision`.
- **`override`** asks a configurable super permission.
- **`canCreateIn`** asks the create permission in the workspace's team context.

::: warning Optional dependency
`spatie/laravel-permission` is an **optional** host dependency. Every runtime touch of
the Spatie API is guarded by `class_exists(PermissionRegistrar::class)`, so vaulter-core
carries no hard dependency. Selecting `spatie` without installing the package degrades
to a safe deny (`null`/`false`), never a fatal error.
:::

### Configuration

<!-- snippet: config -->

```php
// config/vaulter.php — used only when access.permission_driver = 'spatie'
'access' => ['spatie' => [
    'permissions' => [
        'previewer' => 'vaulter.preview',
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
]],
```

`teams` falls back to this key **only** when the host's own `config('permission.teams')`
is unset — the host's Spatie config is authoritative. `role_subject_type` /
`team_subject_type` are the morph aliases that role/team grant rows are addressed to.

### The team-switch and the cache seam

The full v8 dance — capture the current team id, `setPermissionsTeamId($scope->driveOwner->id)`,
check, restore in a `finally` — lives entirely inside the driver, so concurrent panels
never bleed into each other. After the host mutates an actor's roles/permissions
**outside** Spatie's own assign/remove helpers, it must reset the cache so a stale,
team-scoped permission can't grant a revoked role on the next resolve:

<!-- snippet: host -->

```php
use Vaulter\Access\Drivers\SpatiePermissionDriver;

SpatiePermissionDriver::forgetCachedPermissions($actor);
```

This is a no-op when Spatie is not installed.

## AzGuard — the per-resource seam

`Vaulter\Azgard\AzgardPermissionDriver` lives in the separate
`axioma-studio/vaulter-azgard` package; core does not depend on AzGuard. The bridge
requires AzGuard v1 (`axiomasoft/azguard`). AzGuard publishes no tagged release, so the
bridge pins one commit: `"axiomasoft/azguard": "dev-main#9a6dc0e…"` (the full sha is in
`packages/azgard/composer.json`).

<!-- snippet: shell -->

```bash
composer require axioma-studio/vaulter-azgard
```

Its Laravel-discovered provider registers `azgard` in `vaulter.access.permission_drivers`,
preserving an explicit host override, and binds
`Vaulter\Azgard\Contracts\AzgardGuard` to the real adapter. Then select
`VAULTER_PERMISSION_DRIVER=azgard`. Without the installed bridge, the key is unknown
and throws `MissingPermissionDriverException`.

The composite resolution order is node-ACL → workspace-scoped role → global. This driver
owns the **workspace-scoped role** lane:

- **`override`** = `null`. AzGuard's `isSuperAdmin()` is not a decision, so the bridge never
  turns it into an absolute Vaulter allow: an AzGuard super-admin reaches the **owner** role
  through the same workspace decisions below, which keep AzGuard's scope policy,
  restrictions, policies and tenant rules.
- **`resolve`** maps each role's defining ability to an AzGuard permission and
  asks `hasPermissionIn($workspaceType, $workspaceId, $permission)`; the union of the
  allowed roles becomes the `Decision`. (`AccessScope` is node-blind, so this is the
  workspace-scoped check.)
- **`subjectsFor`** = `[Subject(actor)]` only — AzGuard *answers* checks rather than
  enumerating role-subjects. A host that addresses grant rows to AzGuard roles should
  subclass and append those Subjects.
- **`canCreateIn`** asks `hasPermissionIn($type, $id, $createPermission)`.

The role → defining-ability probes are: `Previewer → View`, `Reader → Download`,
`Commenter → Comment`, `Writer → Edit`, `Organizer → Share`, `Owner → Manage`.

### The AzGuard panel (host)

The host declares the AzGuard v1 panel that owns Vaulter's permissions:

- the user model is a subject of the panel and implements
  `Vaulter\Azgard\Contracts\AzgardBackedUser` — AzGuard's own `AzGuardSubject`, satisfied
  by the `AzGuard\Concerns\HasAzGuard` trait with no extra methods;
- the mapped permissions (`documents.view`, …) are permissions of the panel;
- every drive-owner morph alias the driver checks in (`team`, `project`, …) is an
  **assignment scope type** of the panel. The workspace lane is
  `hasPermission($permission, on: AssignmentScopeRef::of($type, $id))`; a valid alias the
  panel does not declare is denied (fail-closed). With `AssignmentScopePolicy::inherit(...)`
  a tenant-wide grant (including the super-admin role) holds in every workspace; with
  `isolated(...)` only grants inside the workspace count;
- AzGuard identities are type aliases (`[a-z0-9][a-z0-9_.-]*`), never class names:
  register the user and workspace models in the morph map. A drive owner whose type is
  not an alias (e.g. a class-name morph) is a misconfiguration: AzGuard throws
  `InvalidAssignmentScopeException` instead of denying.

<!-- snippet: host -->

```php
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Roles\SuperAdminRole;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Sources\Database\DatabaseSource;

final class AppPanel extends PanelProvider
{
    public static function getId(): string
    {
        return 'app';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(User::class)
            ->permissions([DocumentsPermission::class, DatabaseSource::make()])
            ->roles([SuperAdminRole::class])
            ->scopes(AssignmentScopePolicy::inherit(new TeamScope)); // type 'team'
    }
}
```

### Configuration

Unlike Gate/Spatie (keyed by role key), the AzGuard map is **action-centric** —
keyed by `Ability`:

<!-- snippet: config -->

```php
// config/vaulter-azgard.php — used only when access.permission_driver = 'azgard'
return [
    'panel_id' => null, // AzGuard `guard:`; null lets AzGuard select the panel
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

`panel_id` is passed to AzGuard as `guard:`. With `null`, AzGuard selects the panel by a
full name (`app:documents.view`) or a panel prefix in the permission, else the panel of
the request, else the panel of the user model; the default map names local keys of that
panel, so that panel's resource prefix must not be `documents`: AzGuard refuses to boot a
panel whose prefix is the first segment of one of its permissions (`PrefixConflictException`).
Give the panel another `resourcePrefix()` (or `resourcePrefix(false)`), or map the abilities to
permissions that do not start with the prefix. Set `VAULTER_AZGARD_PANEL` when the user model belongs to
several panels or the checks must not depend on the request panel; AzGuard throws when no
panel can be selected. A panel with tenants needs its current tenant; without one AzGuard
denies the check.

### The AzgardGuard adapter

`Vaulter\Azgard\Contracts\AzgardGuard` is the narrow seam the driver talks to. It
exposes exactly the two AzGuard decisions the driver needs — and nothing of AzGuard's own
API:

<!-- snippet: signature Vaulter\Azgard\Contracts\AzgardGuard -->

```php
interface AzgardGuard
{
    // Per-resource permission (the per-node lane).
    public function hasScopedPermission(string $permission, object $entity): bool;

    // Workspace-scoped permission (the lane resolve()/create actually drive).
    public function hasPermissionIn(string $contextType, int|string $contextId, string $permission): bool;
}
```

The driver always answers for the decision's **actor**, never for whoever the request is:

1. If the actor itself implements `AzgardGuard`, the driver uses it directly.
2. Otherwise, a guard the **host** bound in the container is used (see the override below).
3. Otherwise, for an actor implementing `Vaulter\Azgard\Contracts\AzgardBackedUser`, the
   driver builds an `AzgardGuardAdapter` over that actor, in the `vaulter-azgard.panel_id` panel.
4. Any other actor gets no AzGuard lane (nothing is raised, `canCreateIn` is `false`).

The binding of `AzgardGuard` that the bridge provider registers (an adapter over the **request**
user, `NullAzgardGuard` for a guest) is for code that asks the seam about the request user. The
driver never lends it to another actor: an acting-as or preview check does not inherit the
request user's rights, and a call without a request user — the collaboration hub resolves the
token subject itself — is decided by the actor's own AzGuard rights.

::: details Optionally overriding the supplied adapter
The bridge already binds a real adapter. A host can override `AzgardGuard`; the driver then
uses the host's guard for every actor, so it must answer for the actor it is asked about, not
for the request user (the example below shows the shape; closing over the request user is only
correct when the decision is about that user). The supplied adapter is a thin pass-through onto
AzGuard v1:

<!-- snippet: host -->

```php
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Illuminate\Database\Eloquent\Model;

// In a host service provider
$this->app->bind(\Vaulter\Azgard\Contracts\AzgardGuard::class, function ($app) {
    $actor = $app['auth']->user(); // implements AzgardBackedUser

    return new class ($actor) implements \Vaulter\Azgard\Contracts\AzgardGuard {
        public function __construct(private \Vaulter\Azgard\Contracts\AzgardBackedUser $actor) {}

        public function hasScopedPermission(string $permission, object $entity): bool
        {
            return $entity instanceof Model && $this->actor->hasPermission($permission, on: $entity);
        }

        public function hasPermissionIn(string $contextType, int|string $contextId, string $permission): bool
        {
            return $this->actor->hasPermission($permission, on: AssignmentScopeRef::of($contextType, $contextId));
        }
    };
});
```

The driver passes the workspace type string through untouched — it never resolves the
alias itself.
:::

### Testing the AzGuard lane

`Vaulter\Azgard\Testing\FakeAzgardGuard` is an in-memory guard supplied by
`vaulter-azgard`. Install the bridge and its Composer dependencies even for fake-based
tests; the fake replaces live AzGuard checks, not the package's installation requirements.

<!-- snippet: host -->

```php
use Vaulter\Azgard\Testing\FakeAzgardGuard;

// grant one workspace permission
app()->bind(\Vaulter\Azgard\Contracts\AzgardGuard::class,
    fn () => FakeAzgardGuard::grantingWorkspace('team', $teamId, 'documents.update'));

// or a super-admin (every check allowed → owner role)
app()->bind(\Vaulter\Azgard\Contracts\AzgardGuard::class,
    fn () => FakeAzgardGuard::superAdmin());
```

For end-to-end tests against the real engine, use AzGuard's own helpers
(`AzGuard\Testing\InteractsWithAzGuard`): `actingAsWithPermissions($user, ['documents.update'],
on: AssignmentScopeRef::of('team', $teamId))` and `actingAsSuperAdmin($user)` store real
grants through AzGuard's change pipeline.

## Driver comparison

| | Database | Gate | Spatie | AzGuard |
|---|---|---|---|---|
| Extra dependency | none | none (built-in) | optional `spatie/laravel-permission` | `axioma-studio/vaulter-azgard` + its dependencies |
| Map keyed by | — | role key | role key | `Ability` |
| Enumerates role/team subjects | subclass | no | yes | no |
| Super-admin override | no | `vaulter.super` ability | super permission | no — AzGuard super-admin → owner role by decisions |
| Workspace-scoped | per-node grants | node-blind context | team-scoped | `hasPermissionIn` |
| Safe when not configured | n/a | yes | yes (no access) | only with the bridge installed |

## Generator

<!-- snippet: shell -->

```bash
php artisan vaulter:make:permission-driver Workspace
```

Writes a pass-through driver in `app/Vaulter/PermissionDrivers/Workspace.php` and a Pest test in `tests/Unit/Vaulter/` that runs `PermissionDriverContractTests`. It prints the line to `vaulter.access.permission_drivers`
and never edits your config. An existing file is kept unless you pass `--force`.

## Testing your extension

`Vaulter\Testing\Contracts\PermissionDriverContractTests` proves a driver the same way the
built-in ones are proved. The shared fixture (`PermissionFixture`) is built through
Operations: a writer subtree grant for an actor on a parent folder, and a child below it with
inheritance restricted and no grant of its own.

<!-- snippet: host -->

```php
use Vaulter\Testing\Contracts\PermissionDriverContractTests;

it('decides like the reference driver', function (): void {
    (new PermissionDriverContractTests)->assertSameDecisionsAsReference(new AcmeRoleDriver);
});
```

`assertSameDecisionsAsReference()` compares every `Ability` on the parent and the child with the
database driver and fails when yours decides differently. Use it for a driver that adds no
roles, subjects or override of its own; a driver that does add them is tested against the
decisions you expect instead, with `assertPermissionDriverContract()` on your own fixture.

Visibility assertions for the host's own tests live in the `InteractsWithVaulter` trait:
`assertNodeVisibleTo($user, $node)` and `assertNodeNotVisibleTo($user, $node)` ask through the
drive's Operations, the way a request would.

## See also

- [Authorization](/concepts/access-model) — how a driver's answers are folded into the final
  decision.
- [Grants](/guides/grants) — the `v_node_grants` rows the subject-set is matched against.
- [Multi-tenancy recipe](/guides/recipes) — end-to-end tenant + role wiring.
- ADR-0002 (authorization delegation), ADR-0007 (the AzGuard seam), ADR-0025 (access model 1.0: roles, no deny).
