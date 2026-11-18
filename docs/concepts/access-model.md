---
title: Authorization
description: How Vaulter decides who can do what — the host-delegated AccessResolver, the role-union algorithm, inheritance boundaries, the ability flags the UI binds against, and the fail-loud null driver.
outline: deep
---

# Authorization

Vaulter answers exactly one authorization question: **may this actor perform this
ability on this node?** The answer is computed by a single, Vaulter-owned engine —
the `AccessResolver` — that folds together two lanes:

1. the **per-node ACL** (`v_node_grants` rows), Vaulter's own grant store (versioned roles); and
2. a **swappable host-role lane** (`PermissionDriver`) that bridges your application's
   identity and role system in.

The resolver itself is **not** a contract and **not** swappable — only the host-role
lane is. This page documents the engine, its algorithm, and the rules that make a
decision deterministic. The role drivers are covered in
[Permission Drivers](/extending/permission-drivers), and the grant rows themselves in
[Grants](/guides/grants).

::: info Two lanes, one authority
The `AccessResolver` is the single authority every Policy, `DriveHandle`, controller
and Resource asks. It lives at `Vaulter\Drive\Access\AccessResolver`. The host plugs into
it through the `PermissionDriver` contract — never by replacing the resolver.
:::

## The public surface

`AccessResolver` exposes a small, stable API. Everything else is private.

| Method | Returns | Purpose |
|---|---|---|
| `can(Authorizable $actor, Ability $ability, Node $node)` | `bool` | The single yes/no entry point Policies and the `DriveHandle` call. |
| `effectiveGrants(Authorizable $actor, Node $node)` | `GrantSet` | The folded result of the whole algorithm — allowed bits and the host override flags. |
| `abilitiesFor(Authorizable $actor, Node $node)` | `array<string, bool>` | Every `Ability` mapped to allowed/denied — fills the UI `abilities` object. |
| `canCreateInWorkspace(Authorizable $actor, Storage $storage)` | `bool` | The create gate for folders/uploads, where there is no existing node. |
| `sharedNodeIds(Authorizable $actor)` | `list<int\|string>` | The candidate node ids the actor has any live allow-grant on. |
| `filterSharedWith(Authorizable $actor, iterable $candidates)` | `list<Node>` | Re-resolves "shared with me" candidates through the full algorithm. |

```php
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Enums\Access\Ability;

$resolver = app(AccessResolver::class);

if ($resolver->can($user, Ability::Edit, $node)) {
    // the actor may edit this node
}
```

`can()` is sugar over `effectiveGrants($actor, $node)->allows($ability)` — there is one
place the algorithm lives, and every other method reads its result.

## Abilities and roles

Vaulter reasons over a single integer. Every ability owns **one bit**
(`Vaulter\Drive\Enums\Access\Permission`), and `Ability::bits()` returns it:

| Ability | Bit | Ability | Bit |
|---|---|---|---|
| `View` | 1 | `Download` | 64 |
| `Edit` | 2 | `Move` | 128 |
| `Create` | 4 | `Copy` | 256 |
| `Delete` | 8 | `Trash` | 512 |
| `Share` | 16 | `Restore` | 1024 |
| `Comment` | 32 | `Manage` | 2048 |

`Permission::all()` is 4095. View and Download are separate, so a user can preview a node
without being able to download or copy it. `Ability::satisfiedBy($bits)` is
`($bits & $ability->bits()) === $ability->bits()`.

### Roles v1

A grant stores a **role** (`Vaulter\Drive\Access\Roles\Role`) and its version, resolved through
`RoleRegistry`. A role is a closed set of abilities; each built-in role contains the one
before it:

| Role | Abilities |
|---|---|
| `previewer` | View |
| `reader` | previewer + Download |
| `commenter` | reader + Comment |
| `writer` | commenter + Edit, Create, Move, Copy, Trash, Restore |
| `organizer` | writer + Share |
| `owner` | organizer + Manage, Delete |

A role never changes once released: a revised role ships as a new version, and a stored grant
keeps resolving to what it was given. An unknown key or version throws
`UnknownRoleException` (`unknown_role`, 422). There is no implicit owner: the creator of a node
gets no rights from having created it (`v_nodes.owner_id` is gone) — grant a role explicitly.

## The algorithm

`effectiveGrants()` runs the algorithm in three steps. There is no deny and no ranking.

```
1. Host override (PermissionDriver::override) — absolute, both directions.
2. Collect the roles that apply: direct grants on the node, subtree grants on
   ancestors up to and including the first node with inherit_grants = false,
   and the roles the permission driver returns.
3. allowed = union of the abilities of those roles.
```

### Step 1 — host override

The resolver first asks the active `PermissionDriver::override($actor, $scope)`. A
super-admin / `Gate::before`-style override short-circuits the whole walk:

- `Effect::Allow` → `GrantSet::superAllowed()` (full access);
- `Effect::Deny` → `GrantSet::superDenied()` (no access) — the only block in the model, and it
  belongs to the host, not to a grant;
- `null` → no override; continue.

The override is **absolute** — it is not affected by any ACL row.

### Step 2 — roles up to an inheritance boundary

The driver expands the actor into a **subject-set** via `subjectsFor($actor)` (the user
itself plus any host roles/teams). The resolver then walks the node's ancestor chain
**nearest-first** and collects the live grants addressed to that subject-set.

Two rules decide whether a grant *applies* at a given node:

- a grant **on the node itself** applies directly, at any scope;
- a grant on an **ancestor** applies only when its scope is `subtree`
  (`GrantScope::inheritsDownward()`).

The walk is **bounded to the storage**: it never crosses `storage_id`, so a decision
can never escape its workspace. Expiry is enforced read-time in the grant query — an
expired row never reaches the resolver, and there is no expiry cron.

#### Inheritance boundaries

Each node carries a boolean `inherit_grants`. When the walk reaches a node with
`inherit_grants = false`, that node's **own** grants still count, but **nothing above it
does** — the walk stops there. A boundary hides the subtree from everyone who had access only
through grants higher up; people who need it get a direct grant on the boundary node.

Manage it with the access operations (both require `Manage` on the node):

```php
$access = $drive->actingAs($owner)->access();

$access->restrictInheritance($node);                                  // only direct grants remain
$access->restrictInheritance($node, InheritanceRestriction::CopyCurrent);
$access->restoreInheritance($node);                                   // direct grants are kept
```

`copy_current` first gives each subject that currently inherits access a direct subtree grant
on the node — the role covering the inherited roles, never broader and never longer-lived than
what it inherits — so nobody loses or gains access by the restriction itself.

### Step 3 — the union

Every applying grant adds its role's abilities; there is no `max(rank)` and nothing subtracts.
A role from the driver's `resolve($subject, $actor, $scope)` (`Decision{Role}`) is unioned
with the grants the same way, so the host role lane can raise access but never lowers it. The
`AccessScope` handed to the driver is node-blind: it carries the profile, the drive owner and
the tenant, never a `Node`.

## Operations over several nodes

| Operation | Required |
|---|---|
| copy | `View` + `Download` + `Copy` on **every** copied node, `Create` on the destination |
| move | `Move` on the node, `Create` on the destination |
| restore | `Restore` on the node, `Create` on the destination unless it returns to its original parent |
| purge | `Delete` |

A previewer can therefore see a folder but a copy that would reach a descendant it cannot
download is refused.

## Explaining a decision

`access()->explain($node, $ability)` returns an `AccessExplanation`:

| Field | Meaning |
|---|---|
| `allowed`, `ability`, `reason` | the answer and why |
| `sources` | the `AccessSource` rows that granted it: grant id, subject, role and version, origin node, whether it was inherited, expiry |
| `restrictions` | what limited the answer (an override, an inheritance boundary) |
| `boundaryNodeId` | the node where inheritance stopped, if any |

## The GrantSet

`effectiveGrants()` returns a `Vaulter\Drive\Access\ValueObjects\GrantSet` — a final readonly
value object carrying the folded decision:

| Field | Meaning |
|---|---|
| `allowedBits` | the union of the bits of every applying role (grants + driver) |
| `superAllow` / `superDeny` | a host override short-circuit |

It answers `allows(Ability)`. Static constructors: `GrantSet::none()` (no access),
`GrantSet::superAllowed()`, `GrantSet::superDenied()`.

## Ability flags for the UI

The resolver computes a flat ability map so the front-end renders only the actions an
actor may perform — no permission logic duplicated in JavaScript, no extra round-trips.

`NodeResource` fills its `abilities` object via
`Vaulter\Drive\Access\ValueObjects\NodeAbilities::for($actor, $node, $resolver)`, which calls
`abilitiesFor()`. When there is no acting user, **every flag is false** (the client
shows a read-only shell rather than mis-advertising actions).

```json
{
  "data": {
    "id": "01J...",
    "name": "Q3 Proposal",
    "abilities": {
      "view": true, "download": true, "comment": true, "edit": true,
      "create": true, "move": false, "copy": true, "trash": false,
      "restore": false, "share": false, "manage": false, "delete": false
    }
  }
}
```

On the client, every node resource ships its resolved `abilities` map — bind your UI directly
against those flags (a missing ability is falsy):

```vue
<script setup lang="ts">
import { computed } from 'vue'
import type { Node } from '@vaulter/vue'

const props = defineProps<{ node: Node }>()
const canEdit = computed(() => props.node.abilities?.edit ?? false)
const canManage = computed(() => props.node.abilities?.manage ?? false)
</script>
```

## Create gate (no node yet)

Folder creation and uploads have no existing node to resolve against, so they route
through `canCreateInWorkspace($actor, $storage)`, which delegates straight to the
driver's `canCreateInWorkspace($actor, $workspaceType, $workspaceId)` lane. See
[Permission Drivers](/extending/permission-drivers) for how each driver answers it.

## Access-aware listings

The per-action gate above answers *"may this actor do X to this node?"* exactly. Drive
**listings** (the folder index, search, trash, and the Filament table) ask a different,
cheaper question — *"which nodes should this actor even see?"* — and answer it with a
**candidate set**, not the exact algorithm. The distinction matters, so be precise about
what each guarantees.

### Exact visibility

Children, search, trash, shared nodes, folder `show` children and Filament table rows
are filtered through `AccessResolver::evaluateMany()` for every permission driver.
Only nodes with effective `View` are returned. Inheritance boundaries and host
overrides apply before names, sizes or MIME types are exposed. The same batch result
fills each listing item's `abilities`, avoiding a separate ancestor walk per resource.

`evaluateMany()` preserves the rules of `effectiveGrants()`: roles are unioned up to the
first inheritance boundary, and host overrides stay absolute. Parent `item` grants never
inherit.

SQL grant narrowing in `constrainToAccessible()` remains a candidate prefilter for
grant-authoritative drivers. Its result needs exact ACL evaluation before display.
The REST listings scan candidates directly; Filament applies the exact batch filter
to the fetched page and uses simple pagination without totals.

### Cursor and scan budget

REST pages carry `meta.next_cursor`, `prev_cursor` (null), `per_page`, `path` and
`scanned`; they do not carry `total` or numbered page links. A cursor records the
last **inspected** candidate, including denied candidates, and is signed with the
application key. It is bound to the storage, listing kind, parent or search term and
ordering. An invalid signature or mismatched query returns 422 `validation_failed`
with `errors.cursor`; restart that listing without a cursor.

`vaulter-drive.listing.scan_budget` defaults to 1000 candidates per request. A page can be
short or empty while `next_cursor` remains non-null. Follow that cursor until null
to reach later visible nodes. Changing `per_page` does not change the cursor's scope.

## "Shared with me"

`sharedNodeIds()` collects candidates with a live direct allow-grant across storages.
The endpoint scans those candidates in `name, id` order and uses `evaluateMany()` to
apply the same exact visibility rules as other listings.

### The `GET /shared` endpoint

The cross-storage "shared with me" listing is exposed at the top level of the Drive prefix
(default `api/vaulter`) — **not** under a `{storage}`, because the subject index is not
storage-bound:

```
GET {prefix}/shared        # e.g. GET /api/vaulter/shared
```

| Property | Behaviour |
|---|---|
| **Gating** | Registered only when the **`grants`** feature is on. |
| **Auth** | An unauthenticated request gets an **empty** page (never an error). |
| **Exactness** | Each candidate is evaluated through the batch ACL algorithm; an unreadable node is excluded. |
| **Pagination** | A signed cursor envelope (`meta.next_cursor`, `per_page`, `scanned`) without `total`; follow the cursor even after an empty page. |
| **Payload** | `NodeResource` items with the owning `storage` eager-loaded (the only cross-storage context the payload carries; the breadcrumb is attached only on `show`). |

On the client, `@vaulter/vue` ships [`useSharedNodes`](/guides/vue) and `VaulterSharedWithMe`
over this endpoint.

## Fail-loud: the null driver

The default driver is `database` — a fully standalone lane with zero host dependency.
For local boot-up there is also an **open** driver, `Vaulter\Drive\Testing\NullPermissionDriver`,
which grants every actor full access via an absolute `Allow` override.

The `PermissionDriverManager` rejects the null driver **in production**:

```php
// Vaulter\Drive\Access\PermissionDriverManager::driver()
if (is_a($class, NullPermissionDriver::class, allow_string: true) && $this->isProduction) {
    throw MissingPermissionDriverException::nullDriverInProduction($key);
}
```

`isProduction` is wired from `$app->environment('production')`. The exception message
is explicit:

> The open null host-role driver is forbidden in production. Why: it grants every actor
> full access; running it in prod would expose every node. Fix: set
> `config('vaulter-drive.access.permission_driver')` to a real driver (`database`/`gate`/`spatie`/
> `azguard`) or bind your own `PermissionDriver`.

::: danger Never ship the null driver
`null` is selectable only outside production and exists purely so a consumer can boot
and exercise Vaulter without wiring a real role system. A missing or `null` driver can
**never** silently open a production install — it fails loud at resolve time.
:::

The `azguard` key requires `composer require axiomasoft/vaulter-azguard`.
Its provider registers the driver and real guard adapter; bridge settings live under
`vaulter-azguard.*`, and the user model must implement `AzGuardBackedUser`.

An **unknown** driver key fails the same way, via
`MissingPermissionDriverException::unknownDriver($key, $known)`, listing the registered
keys.

## See also

- [Permission Drivers](/extending/permission-drivers) — the four `PermissionDriver` lanes and how
  to select/configure each.
- [Grants](/guides/grants) — the per-node `v_node_grants` rows, subjects, effects, scopes,
  expiry, and `GrantNodeAccess`.
- ADR-0002 (authorization delegation) and ADR-0007 (the AzGuard seam).
