---
title: Grants
description: Per-node ACL grants — subject, level, effect, scope and expiry — the GrantNodeAccess / RevokeNodeAccess actions, the REST surface, events, and the Vue useNodeGrants composable.
outline: deep
---

# Grants

A **grant** is a single row in `v_node_grants` that says: *this subject has this access
on this node*. Grants are Vaulter's own ACL store — the raw material the
[`AccessResolver`](./authorization) walks and folds. This page covers the grant model,
how to write and revoke grants, the REST surface, and the events emitted.

::: info Where grants fit
Grants are the **node-ACL lane**. The [role lane](./permission-drivers) (your host's
roles) is folded in separately. A grant can only confer the access it states — but a
`deny` grant is the final veto that no role can override.
:::

## Anatomy of a grant

`Vaulter\Models\Access\NodeGrant` (table `v_node_grants`):

| Column | Type | Meaning |
|---|---|---|
| `node_id` | id | the node the grant sits on |
| `subject_type` | string | a registered morph alias — `user` / `role` / `team` / a host alias |
| `subject_id` | id | the subject key the grant is addressed to |
| `level` | `GrantLevel` | `reader` / `commenter` / `writer` / `organizer` / `owner` |
| `permissions` | int | optional extra CRUDS bits, OR-ed with the level's bits |
| `effect` | `Effect` | `allow` (widen) or `deny` (narrow — the final veto) |
| `scope` | `GrantScope` | `item` (this node only) or `subtree` (flows down) — defaults to `subtree` |
| `expires_at` | datetime? | read-time expiry; `null` = never expires |
| `granted_by` | id? | the actor who created the grant |
| `tenant_id` | id? | present when tenancy is enabled |

The model is swappable — point `config('vaulter.models.grant')` at a subclass (for
example to add tenant scoping). `inherited` / `inherited_from_node_id` are reserved for
an optional materialised read-model and stay `false`/`null`: the source of truth is the
direct rows plus the tree.

### Subject

A grant is addressed to a `Vaulter\Access\ValueObjects\Subject` — a `(type, id)` pair.
The `type` is always a registered morph alias, never a raw FQCN. The role driver expands
an actor into the list of subjects whose grants apply (see
[`subjectsFor`](./permission-drivers#the-hostroledriver-contract)); Vaulter builds
`WHERE (subject_type, subject_id) IN (...)` from that list.

```php
use Vaulter\Access\ValueObjects\Subject;

$user = Subject::ofModel($actor);        // morph alias + key
$role = new Subject(type: 'role', id: 'editor');
$team = Subject::make('team', $teamId);
```

### Level + permissions

A grant stores a ranked `level`, an optional extra `permissions` bitmask, or both. The
resolver normalises a grant to bits via `level->bits() | permissions`, so a level grant
plus an extra `SHARE` bit confers both. See
[Abilities & levels](./authorization#abilities-levels-and-the-cruds-bitmask) for the
level → bits table.

### Effect

`Vaulter\Enums\Access\Effect` — `allow` widens access, `deny` narrows it. A `deny` is
the **final veto** after the max/OR pass: a host role can never resurrect a level a deny
has forbidden, and a `deny` on the same node as an `allow` always wins. See the
[deny-veto fold](./authorization#step-3-the-deny-veto-fold-explicit-beats-inherited).

### Scope + inheritance

`Vaulter\Enums\Access\GrantScope` — `item` applies only to the node it sits on;
`subtree` (the default) additionally flows down to descendants during the ancestor walk,
**until** an `inherit_grants = false` cut-off node. Only `subtree` grants inherit
downward (`GrantScope::inheritsDownward()`).

### Expiry

`expires_at` is enforced **read-time**, never by a cron. The grant query filters
`expires_at IS NULL OR expires_at > now`, so an expired row never reaches the resolver.

## Writing a grant

`Vaulter\Actions\Access\GrantNodeAccessAction` persists a grant (or deny). It is
**idempotent** on the grant-unique key: re-granting the same subject the same
scope/effect updates the level/bits/expiry. The write runs in a transaction and emits
`NodeGrantCreated`.

```php
use Vaulter\Actions\Access\GrantNodeAccessAction;
use Vaulter\Data\Access\GrantNodeAccessInput;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantScope;

$grant = app(GrantNodeAccessAction::class)->execute(
    node: $node,
    data: new GrantNodeAccessInput(
        subject: Subject::ofModel($collaborator),
        level: GrantLevel::Writer,
        effect: Effect::Allow,            // default
        scope: GrantScope::Subtree,       // default — flows to descendants
        permissions: 0,                   // optional extra CRUDS bits
        expiresAt: now()->addDays(7),     // optional
        grantedBy: $actor->getKey(),
    ),
);
```

::: warning The Action does not authorize
`GrantNodeAccessAction` persists; it does not re-authorize. The caller (controller /
policy) must check the actor may `Share`/`Manage` the node **before** reaching the
Action. The HTTP surface below does exactly that.
:::

### A deny grant

To carve out an exception inside an otherwise-shared subtree, write a `deny`:

```php
new GrantNodeAccessInput(
    subject: Subject::ofModel($contractor),
    level: GrantLevel::Reader,
    effect: Effect::Deny,           // veto — overrides farther/role allows
    scope: GrantScope::Subtree,
);
```

Because of the deny-veto, this hides the node (and its subtree, given `subtree` scope)
from the contractor even if a higher-up `allow` or a host role would otherwise grant it.

## Revoking grants

`Vaulter\Actions\Access\RevokeNodeAccessAction` removes **every** grant a subject holds
on a node — both effects, both scopes — and returns the count removed. It emits
`NodeGrantRevoked` only when something was actually revoked (an empty revoke is a silent
no-op). Transactional.

```php
use Vaulter\Actions\Access\RevokeNodeAccessAction;
use Vaulter\Access\ValueObjects\Subject;

$removed = app(RevokeNodeAccessAction::class)->execute(
    node: $node,
    subject: Subject::ofModel($collaborator),
    actorId: $actor->getKey(),
);
```

## REST surface

The per-node ACL is exposed under the Drive prefix (default `api/vaulter`). **Every**
action requires the `Share` ability on the node — only someone who may share a node may
change its grants. A denial 403s through `PermissionDeniedException`.

| Method | Path | Action |
|---|---|---|
| `GET` | `nodes/{node}/grants` | list grants on the node (cursor-paginated) |
| `POST` | `nodes/{node}/grants` | grant/deny a subject (201) |
| `DELETE` | `nodes/{node}/grants` | revoke a subject's grants (204) |

::: tip Where the grants surface, listing-side
When the `grants` feature is on and the `database` driver is active, Drive **listings**
narrow to the actor's grant-reachable candidate set, and `GET {prefix}/shared` returns the
cross-storage "shared with me" set. Both are documented under
[Authorization › Access-aware listings](./authorization#access-aware-listings) — including
the candidate-superset-vs-exact semantics and the open-driver behaviour.
:::

::: code-group
```bash [grant]
curl -X POST https://app.test/api/vaulter/nodes/01J.../grants \
  -H 'Authorization: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -d '{
    "subject_type": "user",
    "subject_id": "42",
    "level": "writer",
    "effect": "allow",
    "scope": "subtree",
    "permissions": 0,
    "expires_at": "2026-12-31T23:59:59Z"
  }'
```

```bash [revoke]
curl -X DELETE https://app.test/api/vaulter/nodes/01J.../grants \
  -H 'Authorization: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -d '{ "subject_type": "user", "subject_id": "42" }'
```

```json [grant response 201]
{
  "data": {
    "id": "01J...",
    "node_id": "01J...",
    "subject_type": "user",
    "subject_id": "42",
    "level": "writer",
    "permissions": 0,
    "effect": "allow",
    "scope": "subtree",
    "expires_at": "2026-12-31T23:59:59Z",
    "created_at": "2026-06-30T10:00:00Z"
  }
}
```
:::

### Request validation

`StoreNodeGrantRequest` validates the body:

| Field | Rules |
|---|---|
| `subject_type` | required, string, max 255, a registered subject type |
| `subject_id` | required |
| `level` | required, one of the `GrantLevel` values |
| `effect` | nullable enum (`allow`/`deny`), defaults `allow` |
| `scope` | nullable enum (`item`/`subtree`), defaults `subtree` |
| `permissions` | nullable integer, `0..31` |
| `expires_at` | nullable date |

## Events

Both events implement `ShouldDispatchAfterCommit` — they fire only after the grant
transaction commits, so a listener never sees an uncommitted grant.

| Event | Payload | When |
|---|---|---|
| `Vaulter\Events\Access\NodeGrantCreated` | `NodeGrant $grant`, `int\|string\|null $actorId` | a grant was created or updated |
| `Vaulter\Events\Access\NodeGrantRevoked` | `Node $node`, `Subject $subject`, `int $revoked`, `int\|string\|null $actorId` | a subject's grants were revoked |

A downstream listener can bump an `acl_version` cache or record activity:

```php
use Vaulter\Events\Access\NodeGrantCreated;

Event::listen(function (NodeGrantCreated $event): void {
    Cache::increment("acl_version:{$event->grant->node_id}");
});
```

## On the client — useNodeGrants

`@vaulter/vue` ships `useNodeGrants(nodeId)` for reactive management of a node's grants,
backed by the injected client's `grants` namespace
(`client.grants.list` / `grant` / `revoke`).

```vue
<script setup lang="ts">
import { useNodeGrants } from '@vaulter/vue'

const { grants, loading, list, grant, revoke } = useNodeGrants(nodeId)

await list()

await grant({
  subjectType: 'user',
  subjectId: 42,
  level: 'writer',
  effect: 'allow',
  scope: 'subtree',
})

await revoke({ subjectType: 'user', subjectId: 42 })
</script>
```

A `grant()` prepends to the reactive list; a `revoke()` drops every grant for that
subject. The `VaulterNodeGrantsPanel` component wraps this for a drop-in grants UI — see
[Vue components](../guide/vue).

## See also

- [Authorization](./authorization) — how grants are walked, folded and vetoed.
- [Permission Drivers](./permission-drivers) — the role lane folded alongside grants.
- Panel-scoped grants and subtree inheritance details: `node_grants`, `inherit_grants`.
