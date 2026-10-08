---
title: Grants
description: Per-node ACL grants — subject, role, scope and expiry — the GrantNodeAccess / RevokeNodeAccess actions, the REST surface, events, and the Vue useNodeGrants composable.
outline: deep
---

# Grants

A **grant** is a single row in `v_node_grants` that says: *this subject has this access
on this node*. Grants are Vaulter's own ACL store — the raw material the
[`AccessResolver`](./authorization) walks and folds. This page covers the grant model,
how to write and revoke grants, the REST surface, and the events emitted.

::: info Where grants fit
Grants are the **node-ACL lane**. The [role lane](./permission-drivers) (your host's
roles) is folded in separately. A grant only adds access — there is no deny grant; to
cut a subtree off from grants above, use an [inheritance boundary](./authorization#inheritance-boundaries).
:::

## Anatomy of a grant

`Vaulter\Models\Access\NodeGrant` (table `v_node_grants`, unique per node, subject and scope):

| Column | Type | Meaning |
|---|---|---|
| `node_id` | id | the node the grant sits on |
| `subject_type` | string | a registered morph alias — `user` / `role` / `team` / a host alias |
| `subject_id` | id | the subject key the grant is addressed to |
| `role_key` | string | `previewer` / `reader` / `commenter` / `writer` / `organizer` / `owner` |
| `role_version` | int | the version of the role the grant was given (currently `1`) |
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

### Role

A grant stores a role key and the role version it was given, so a later change to a role
ships as a new version and never alters stored grants. An unknown key or version throws
`UnknownRoleException` (`unknown_role`, 422). See
[Abilities & roles](./authorization#abilities-and-roles) for the role → abilities table.

### No deny

Grants only add access. Effective access is the **union** of the roles of every applicable
grant up to an inheritance boundary; an administrative block belongs to the host's
permission driver (`override()`), not to a grant. Restrict a subtree with
`access()->restrictInheritance($node)` instead.

### Scope + inheritance

`Vaulter\Enums\Access\GrantScope` — `item` applies only to the node it sits on;
`subtree` (the default) additionally flows down to descendants during the ancestor walk,
**up to and including** the first `inherit_grants = false` node — grants above that node
never reach below it. Only `subtree` grants inherit
downward (`GrantScope::inheritsDownward()`).

### Expiry

`expires_at` is enforced **read-time**, never by a cron. The grant query filters
`expires_at IS NULL OR expires_at > now`, so an expired row never reaches the resolver.

## Writing a grant

`access()->grant()` (backed by `Vaulter\Actions\Access\GrantNodeAccessAction`) persists a
grant. It is **idempotent** on the grant-unique key: re-granting the same subject the same
scope updates the role and expiry. The write runs in a transaction and emits
`NodeGrantCreated`.

```php
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Enums\Access\GrantScope;

$grant = Vaulter::drive($drive)->actingAs($actor)->access()->grant(
    $node,
    new GrantInput(
        subject: Subject::ofModel($collaborator),
        role: RoleRegistry::WRITER,
        scope: GrantScope::Subtree,       // default — flows to descendants
        expiresAt: now()->addDays(7),     // optional
    ),
);
```

::: warning The Action does not authorize
`GrantNodeAccessAction` persists; it does not re-authorize. `access()->grant()` and the HTTP
surface below check the actor may `Share`/`Manage` the node first; call the Action directly
only after doing the same.
:::

### Walling a subtree off

To keep a subfolder away from a team that has access higher up, stop inheritance on it and
grant the people who need it directly:

```php
$access = Vaulter::drive($drive)->actingAs($owner)->access();

$access->grant($hr, new GrantInput(subject: Subject::ofModel($hrUser), role: RoleRegistry::READER));
$access->restrictInheritance($hr);                                  // empty: only direct grants remain
// $access->restrictInheritance($hr, InheritanceRestriction::CopyCurrent); // keep today's access
$access->restoreInheritance($hr);                                   // open to grants from above again
```

Both calls require `Manage` on the node. `copy_current` first gives each subject that
currently inherits access a direct subtree grant — the role covering the inherited roles,
never broader and never longer-lived than what it inherits.

## Revoking grants

`Vaulter\Actions\Access\RevokeNodeAccessAction` removes **every** grant a subject holds
on a node — both scopes — and returns the count removed. It emits
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
| `POST` | `nodes/{node}/grants` | grant a subject a role (201) |
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
    "role": "writer",
    "scope": "subtree",
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
    "role": "writer",
    "role_version": 1,
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
| `role` | required, one of the registered role keys |
| `scope` | nullable enum (`item`/`subtree`), defaults `subtree` |
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
  role: 'writer',
  scope: 'subtree',
})

await revoke({ subjectType: 'user', subjectId: 42 })
</script>
```

A `grant()` prepends to the reactive list; a `revoke()` drops every grant for that
subject. The `VaulterNodeGrantsPanel` component wraps this for a drop-in grants UI — see
[Vue components](../guide/vue).

## See also

- [Authorization](./authorization) — how grants are walked and unioned up to a boundary.
- [Permission Drivers](./permission-drivers) — the role lane folded alongside grants.
- Panel-scoped grants and subtree inheritance details: `node_grants`, `inherit_grants`.
