---
title: Authorization
description: How Vaulter decides who can do what — the host-delegated AccessResolver, the deny-veto algorithm, grant inheritance and the inherit_grants cut-off, the ability flags the UI binds against, and the fail-loud null driver.
outline: deep
---

# Authorization

Vaulter answers exactly one authorization question: **may this actor perform this
ability on this node?** The answer is computed by a single, Vaulter-owned engine —
the `AccessResolver` — that folds together two lanes:

1. the **per-node ACL** (`v_node_grants` rows), Vaulter's own grant store; and
2. a **swappable host-role lane** (`HostRoleDriver`) that bridges your application's
   identity and role system in.

The resolver itself is **not** a contract and **not** swappable — only the host-role
lane is. This page documents the engine, its algorithm, and the rules that make a
decision deterministic. The role drivers are covered in
[Permission Drivers](./permission-drivers), and the grant rows themselves in
[Grants](./grants).

::: info Two lanes, one authority
The `AccessResolver` is the single authority every Policy, `DriveContext`, controller
and Resource asks. It lives at `Vaulter\Access\AccessResolver`. The host plugs into
it through the `HostRoleDriver` contract — never by replacing the resolver.
:::

## The public surface

`AccessResolver` exposes a small, stable API. Everything else is private.

| Method | Returns | Purpose |
|---|---|---|
| `can(Authorizable $actor, Ability $ability, Node $node)` | `bool` | The single yes/no entry point Policies and the `DriveContext` call. |
| `effectiveGrants(Authorizable $actor, Node $node)` | `GrantSet` | The folded result of the whole algorithm — allow-mask, deny-mask, level. |
| `abilitiesFor(Authorizable $actor, Node $node)` | `array<string, bool>` | Every `Ability` mapped to allowed/denied — fills the UI `abilities` object. |
| `canCreateInWorkspace(Authorizable $actor, Storage $storage)` | `bool` | The create gate for folders/uploads, where there is no existing node. |
| `sharedNodeIds(Authorizable $actor)` | `list<int\|string>` | The candidate node ids the actor has any live allow-grant on. |
| `filterSharedWith(Authorizable $actor, iterable $candidates)` | `list<Node>` | Re-resolves "shared with me" candidates through the full algorithm. |

```php
use Vaulter\Access\AccessResolver;
use Vaulter\Enums\Access\Ability;

$resolver = app(AccessResolver::class);

if ($resolver->can($user, Ability::Edit, $node)) {
    // the actor may edit this node
}
```

`can()` is sugar over `effectiveGrants($actor, $node)->allows($ability)` — there is one
place the algorithm lives, and every other method reads its result.

## Abilities, levels and the CRUDS bitmask

Vaulter reasons over a single integer. Every grant is reduced to a **CRUDS bitmask**
(`Vaulter\Enums\Access\Permission`), and every ability declares the bits it requires.

```
READ = 1   UPDATE = 2   CREATE = 4   DELETE = 8   SHARE = 16   ALL = 31
```

### Abilities → required bits

`Vaulter\Enums\Access\Ability` is the Drive action vocabulary. `requiredBits()` maps
each ability to exactly the bit(s) that must be in the effective allow-mask:

| Ability | Required bits |
|---|---|
| `View`, `Download`, `Comment` | `READ` |
| `Edit` | `UPDATE` |
| `Create`, `Copy` | `CREATE` |
| `Move` | `UPDATE \| CREATE` (re-parent = update here, create there) |
| `Trash`, `Restore`, `Delete` | `DELETE` |
| `Share` | `SHARE` |
| `Manage` | `ALL` (31) |

An ability is satisfied only when **all** its required bits are present:
`Ability::Edit->satisfiedBy($bits)` is `($bits & UPDATE) === UPDATE`.

### Grant levels → bitmask

A grant row may store a ranked **level** (`Vaulter\Enums\Access\GrantLevel`) instead of
raw bits. `GrantLevel::bits()` normalises a level to its bitmask so the engine always
reasons in bits:

| Level | Rank | Bits |
|---|---|---|
| `Reader` | 1 | `READ` |
| `Commenter` | 2 | `READ` (comment is a view-side capability) |
| `Writer` | 3 | `READ \| UPDATE \| CREATE` |
| `Organizer` | 4 | `READ \| UPDATE \| CREATE \| DELETE` |
| `Owner` | 5 | `ALL` (adds `SHARE` / management) |

A higher rank implies every lower one. A grant row may carry **both** a level and
extra `permissions` bits — they are OR-ed, so an `Organizer` grant plus an extra
`SHARE` bit confers both.

::: tip No orphan delete action
There is no standalone stringly-typed "delete" level or action. Permanent delete folds
into the `DELETE` bit (held by `Organizer`/`Owner`), so `Trash`, `Restore` and `Delete`
all require the same `DELETE` bit.
:::

## The algorithm

`effectiveGrants()` runs the canonical algorithm in five steps. It is implemented
exactly as described here — there is no hidden behaviour.

```
1. Host override (super-admin) — absolute, both directions.
2. Walk ancestors nearest-first, BOUNDED to the storage root, collecting
   direct + scope=subtree grants for the actor's subject-set;
   STOP at the first ancestor with inherit_grants = false.
3. aclAllow = max(allow bits/levels); deniedBits = OR of deny bits.
   A NEARER explicit allow pierces a FARTHER inherited deny.
4. granted = max(aclAllow, roleAllow), where roleAllow comes from the driver.
5. FINAL VETO: if deniedBits covers the requested ability → DENY,
   regardless of any role grant.
```

### Step 1 — host override

The resolver first asks the active `HostRoleDriver::override($actor, $scope)`. A
super-admin / `Gate::before`-style override short-circuits the whole walk:

- `Effect::Allow` → `GrantSet::superAllowed()` (full access, both directions);
- `Effect::Deny` → `GrantSet::superDenied()` (no access, both directions);
- `null` → no override; continue to the ACL walk.

The override is **absolute** — it is not affected by any ACL row.

### Step 2 — the bounded ancestor walk

The driver expands the actor into a **subject-set** via `subjectsFor($actor)` (the user
itself plus any host roles/teams). The resolver then walks the node's ancestor chain
**nearest-first** — the node, its parent, … up to the storage root — collecting the
live grants addressed to that subject-set.

Two rules decide whether a grant *applies* at a given node:

- a grant **on the node itself** applies directly, at any scope;
- a grant on an **ancestor** applies only when its scope is `subtree`
  (`GrantScope::inheritsDownward()`).

The walk is **bounded to the storage**: it never crosses `storage_id`, so a decision
can never escape its workspace. Expiry is enforced read-time in the grant query — an
expired row never reaches the resolver, and there is no expiry cron.

#### The inherit_grants cut-off

Each node carries a boolean `inherit_grants`. When the walk consumes an ancestor whose
`inherit_grants = false`, that ancestor's **own** grants still count, but **nothing
above it does** — the walk stops there. This is the inheritance cut-off: a node can be
sealed off so it ignores grants set higher in the tree.

::: warning A deny on a sealed node is an unbreakable wall
Because the walk stops at an `inherit_grants = false` node, a `deny` placed on that
node sits at the farthest reachable depth — no farther allow exists to pierce it. It is
a permanent veto for the subtree below the cut-off.
:::

### Step 3 — the deny-veto fold (explicit beats inherited)

The walk buckets allow-bits and deny-bits **per depth** (nearest = depth 0). The fold
then applies one rule:

> A `deny` at depth *d* is final **except** for the bits allowed at a *strictly nearer*
> depth (`< d`). A same-depth allow does **not** pierce a same-depth deny.

Consequences:

- a **nearer explicit allow** pierces a **farther inherited deny** (you can re-open
  access closer to the node);
- an `allow` and a `deny` on the **same node** ⇒ the **deny wins** (a same-node deny is
  an absolute veto — explicit-beats-inherited applies across depths, never within one).

This is why the resolver cannot fold an unordered grant set in a single pass: a
same-depth allow seen before a same-depth deny must not swallow it. Allow/deny bits are
bucketed per depth, then folded nearest-to-farthest, masking a deny only with bits
allowed strictly nearer.

### Step 4 — fold in the role lane

For each subject the driver's `resolve($subject, $actor, $scope)` may return a
`Decision` (a level + allow + bits). An allow `Decision` is OR-ed into the allow-mask
and its level folded with `max()`:

```
allowedBits = aclAllowBits | roleAllowBits
level       = max(aclLevel, roleLevel)
```

The role lane is folded **before** the deny veto, so it can **raise** access but can
**never resurrect a denied bit**. The `AclScope` handed to the driver is node-blind: it
carries the panel + workspace coordinates and the node owner, never a `Node`.

### Step 5 — the final veto

The veto lives in `GrantSet::allows(Ability $ability)`:

```php
// Vaulter\Access\ValueObjects\GrantSet
public function allows(Ability $ability): bool
{
    if ($this->superDeny) return false;   // override deny wins over everything
    if ($this->superAllow) return true;   // override allow wins over everything

    $required = $ability->requiredBits();

    // Any required bit landing in the deny-mask kills the ability.
    if (($this->deniedBits & $required) !== 0) {
        return false;
    }

    return ($this->allowedBits & $required) === $required;
}
```

A required bit in the deny-mask kills the ability **regardless of the allow-mask** — so
a host-role grant from step 4 can never override an ACL deny.

## The GrantSet

`effectiveGrants()` returns a `Vaulter\Access\ValueObjects\GrantSet` — a final readonly
value object carrying the folded decision:

| Field | Meaning |
|---|---|
| `allowedBits` | the union of every applying allow (ACL + role) |
| `deniedBits` | the OR of every applying deny |
| `level` | the effective `GrantLevel` (max of ACL + role), or `null` |
| `superAllow` / `superDeny` | a host override short-circuit |

It answers `allows(Ability)` with the deny-veto baked in (above). Static constructors:
`GrantSet::none()` (no access), `GrantSet::superAllowed()`, `GrantSet::superDenied()`.

## Ability flags for the UI

The resolver computes a flat ability map so the front-end renders only the actions an
actor may perform — no permission logic duplicated in JavaScript, no extra round-trips.

`NodeResource` fills its `abilities` object via
`Vaulter\Access\Abilities\NodeAbilities::for($actor, $node, $resolver)`, which calls
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

On the client, bind against the resolved flags — see the Vue
[`usePermissions`](../guide/vue) helper, which exposes `canView`/`canEdit`/
`canManage`/`canDelete` computed refs over an `abilities` object.

```vue
<script setup lang="ts">
import { usePermissions } from '@vaulter/vue'

const props = defineProps<{ node: { abilities: Record<string, boolean> } }>()
const { canEdit, canManage } = usePermissions(() => props.node.abilities)
</script>
```

## Create gate (no node yet)

Folder creation and uploads have no existing node to resolve against, so they route
through `canCreateInWorkspace($actor, $storage)`, which delegates straight to the
driver's `canCreateInWorkspace($actor, $workspaceType, $workspaceId)` lane. See
[Permission Drivers](./permission-drivers) for how each driver answers it.

## Access-aware listings

The per-action gate above answers *"may this actor do X to this node?"* exactly. Drive
**listings** (the folder index, search, trash, and the Filament table) ask a different,
cheaper question — *"which nodes should this actor even see?"* — and answer it with a
**candidate set**, not the exact algorithm. The distinction matters, so be precise about
what each guarantees.

### When grant-narrowing applies

A listing narrows to the actor's grant-reachable candidate set **only** when **both**:

1. the **`grants`** feature is on; **and**
2. the active permission driver is **grant-authoritative** — it declares `v_node_grants`
   the *sole* authority for read-visibility (`GrantAuthoritativeDriver::isGrantAuthoritative()`).

Only the default **`database`** driver is grant-authoritative out of the box. For the
**open** drivers (`gate` / `spatie` / `azgard` / `null`) the host's roles govern and
`v_node_grants` is *not* the source of truth, so narrowing would wrongly hide nodes the
host role lane allows — **listings stay open and unchanged** there (the safe default).

::: warning A grant-authoritative subclass can opt out
A host that *subclasses* the `database` resolver to add a role/team lane or a super-admin
`override()` can now confer access on nodes with **no** grant anchor. Such a subclass MUST
override `isGrantAuthoritative()` to return `false` — otherwise the listing would hide
nodes its own role lane allows. The gate keys on this *capability*, not on the driver key
string. See [Permission Drivers](./permission-drivers).
:::

### Candidate superset, not exact suppression

When narrowing applies, the listing query is constrained **in SQL** (before pagination)
to the actor's **candidate** node ids — the grant anchors for the actor's subject-set
**union** the descendants of every `subtree`-scoped anchor (an `item`-scoped anchor
contributes only its own node). Nodes with **no grant-reachability** — the common
"inaccessible" / "not shared with me" case — are hidden.

::: danger The candidate set is a deliberate SUPERSET
The listing SQL narrows to grant-*reachability*; it does **not** apply the exact
**deny-veto** or the **`inherit_grants` cut-off** in the query. So a node that a *nearer*
`deny` revokes (or one past an inherit-cutoff wall) **can still appear in a listing**.
Pushing that exact suppression into the listing SQL is explicitly **deferred** to a future
materialised effective-grants read-model. Treat a listing as "candidates the actor
*probably* can see", never as an authorization decision.
:::

Two things stay **exact** regardless:

- **Per-action authorization.** Every `show` / mutation still runs the full
  `AccessResolver` (deny-veto + inherit-cutoff), so a candidate a deny revokes **403s** the
  moment the actor acts on it. The listing is a visibility hint; the gate is the authority.
- **The "shared with me" view** below, which re-resolves every candidate exactly.

The narrowing lives in the **query**, never a post-fetch page filter — a post-filter that
empties a cursor page would null the next-cursor and silently strand later authorized rows.
The Filament admin table (the `axioma-studio/vaulter-filament` package) applies the **same**
candidate-SQL narrowing through the public `constrainToAccessible()` seam, so the API and the
admin UI stay consistent.

## "Shared with me"

`sharedNodeIds()` runs a fast subject-index pass over `v_node_grants` to collect the
candidate node ids an actor has any **live allow** grant on. Those candidates are then
**re-resolved** through the full algorithm by `filterSharedWith()` — because a nearer
`deny` may have revoked the visibility a raw grant implied, the raw index never decides
visibility on its own. Unlike the candidate-SQL listings above, this view is **exact**:
the re-resolution runs over a *bounded* candidate set, so the cost of the full algorithm
per node is acceptable.

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
| **Exactness** | Each candidate is **re-resolved** through the full algorithm — a nearer `deny` excludes it. This is the EXACT gate, unlike the candidate-SQL listings. |
| **Pagination** | A **length-aware** page envelope (`page` / `total`), *not* a cursor — the cross-storage set is post-filtered into a Collection that can't cursor-paginate cleanly. Page size matches the sibling listings' `per_page`. |
| **Payload** | `NodeResource` items with the owning `storage` eager-loaded (the only cross-storage context the payload carries; the breadcrumb is attached only on `show`). |

On the client, `@vaulter/vue` ships [`useSharedNodes`](./vue) and `VaulterSharedWithMe`
over this endpoint.

## Fail-loud: the null driver

The default driver is `database` — a fully standalone lane with zero host dependency.
For local boot-up there is also an **open** driver, `Vaulter\Testing\NullRoleDriver`,
which grants every actor full access via an absolute `Allow` override.

The `HostRoleDriverManager` rejects the null driver **in production**:

```php
// Vaulter\Access\HostRoleDriverManager::driver()
if (is_a($class, NullRoleDriver::class, allow_string: true) && $this->isProduction) {
    throw MissingHostRoleDriverException::nullDriverInProduction($key);
}
```

`isProduction` is wired from `$app->environment('production')`. The exception message
is explicit:

> The open null host-role driver is forbidden in production. Why: it grants every actor
> full access; running it in prod would expose every node. Fix: set
> `config('vaulter.drivers.permission')` to a real driver (`database`/`gate`/`spatie`/
> `azgard`) or bind your own `HostRoleDriver`.

::: danger Never ship the null driver
`null` is selectable only outside production and exists purely so a consumer can boot
and exercise Vaulter without wiring a real role system. A missing or `null` driver can
**never** silently open a production install — it fails loud at resolve time.
:::

An **unknown** driver key fails the same way, via
`MissingHostRoleDriverException::unknownDriver($key, $known)`, listing the registered
keys.

## See also

- [Permission Drivers](./permission-drivers) — the four `HostRoleDriver` lanes and how
  to select/configure each.
- [Grants](./grants) — the per-node `v_node_grants` rows, subjects, effects, scopes,
  expiry, and `GrantNodeAccess`.
- ADR-0002 (authorization delegation) and ADR-0007 (the AzGuard seam).
