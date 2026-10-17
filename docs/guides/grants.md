---
title: Grants
description: Per-node ACL grants — subject, role, scope and expiry — the GrantNodeAccess / RevokeNodeAccess actions, the REST surface, events, and the Vue useGrants composable.
outline: deep
---

# Grants

A **grant** is a single row in `v_node_grants` that says: *this subject has this access
on this node*. Grants are Vaulter's own ACL store — the raw material the
[`AccessResolver`](/concepts/access-model) walks and folds. This page covers the grant model,
how to write and revoke grants, the REST surface, and the events emitted.

::: info Where grants fit
Grants are the **node-ACL lane**. The [role lane](/extending/permission-drivers) (your host's
roles) is folded in separately. A grant only adds access — there is no deny grant; to
cut a subtree off from grants above, use an [inheritance boundary](/concepts/access-model#inheritance-boundaries).
:::

## Anatomy of a grant

`Vaulter\Drive\Models\Access\NodeGrant` (table `v_node_grants`, unique per node, subject and scope):

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

The model is swappable — point `config('vaulter-drive.models.grant')` at a subclass (for
example to add tenant scoping). `inherited` / `inherited_from_node_id` are reserved for
an optional materialised read-model and stay `false`/`null`: the source of truth is the
direct rows plus the tree.

### Subject

A grant is addressed to a `Vaulter\Drive\Access\ValueObjects\Subject` — a `(type, id)` pair.
The `type` is always a registered morph alias, never a raw FQCN. The role driver expands
an actor into the list of subjects whose grants apply (see
[`subjectsFor`](/extending/permission-drivers#the-permissiondriver-contract)); Vaulter builds
`WHERE (subject_type, subject_id) IN (...)` from that list.

<!-- snippet: host -->

```php
use Vaulter\Drive\Access\ValueObjects\Subject;

$user = Subject::ofModel($actor);        // morph alias + key
$role = new Subject(type: 'role', id: 'editor');
$team = Subject::make('team', $teamId);
```

### Role

A grant stores a role key and the role version it was given, so a later change to a role
ships as a new version and never alters stored grants. An unknown key or version throws
`UnknownRoleException` (`unknown_role`, 422). See
[Abilities & roles](/concepts/access-model#abilities-and-roles) for the role → abilities table.

### No deny

Grants only add access. Effective access is the **union** of the roles of every applicable
grant up to an inheritance boundary; an administrative block belongs to the host's
permission driver (`override()`), not to a grant. Restrict a subtree with
`access()->restrictInheritance($node)` instead.

### Scope + inheritance

`Vaulter\Drive\Enums\Access\GrantScope` — `item` applies only to the node it sits on;
`subtree` (the default) additionally flows down to descendants during the ancestor walk,
**up to and including** the first `inherit_grants = false` node — grants above that node
never reach below it. Only `subtree` grants inherit
downward (`GrantScope::inheritsDownward()`).

### Expiry

`expires_at` is enforced **read-time**, never by a cron. The grant query filters
`expires_at IS NULL OR expires_at > now`, so an expired row never reaches the resolver.

## Writing a grant

`access()->grant()` (backed by `Vaulter\Drive\Actions\Access\GrantNodeAccessAction`) persists a
grant. It is **idempotent** on the grant-unique key: re-granting the same subject the same
scope updates the role and expiry. The write runs in a transaction and emits
`NodeGrantCreated`.

<!-- snippet: host -->

```php
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Enums\Access\GrantScope;

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
surface below check the actor may `Manage` the node first; call the Action directly
only after doing the same.
:::

### Walling a subtree off

To keep a subfolder away from a team that has access higher up, stop inheritance on it and
grant the people who need it directly:

<!-- snippet: host -->

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

`Vaulter\Drive\Actions\Access\RevokeNodeAccessAction` removes **every** grant a subject holds
on a node — both scopes — and returns the count removed. It emits
`NodeGrantRevoked` only when something was actually revoked (an empty revoke is a silent
no-op). Transactional.

<!-- snippet: host -->

```php
use Vaulter\Drive\Actions\Access\RevokeNodeAccessAction;
use Vaulter\Drive\Access\ValueObjects\Subject;

$removed = app(RevokeNodeAccessAction::class)->execute(
    node: $node,
    subject: Subject::ofModel($collaborator),
    actorId: $actor->getKey(),
);
```

## REST surface

The per-node ACL is part of the [REST API v1](/reference/rest-api#access-grants-and-inheritance)
(default prefix `api/vaulter/v1`). **Every** action requires the `Manage` ability on the node.
An organizer may create bearer links with `Share`, but managing grants requires owner access:
a caller who can see the node gets `403 permission_denied`, one who cannot `404 node_not_found`.

| Method | Path | Name |
|---|---|---|
| `GET` | `v1/nodes/{node}/grants` | `grants.index` — the node's grants as `{ "data": [ … ] }` |
| `POST` | `v1/nodes/{node}/grants` | `grants.store` — grant a subject a role (201) |
| `DELETE` | `v1/nodes/{node}/grants/{grant}` | `grants.destroy` — revoke one grant (204) |
| `POST` | `v1/nodes/{node}/inheritance/restrict` | `access.inheritance.restrict` — `{"mode": "empty" \| "copy_current"}` |
| `POST` | `v1/nodes/{node}/inheritance/restore` | `access.inheritance.restore` |

The inheritance routes answer the node (with its new `ETag`). Restricting can remove your own
inherited access; the change stands and the node comes back with every ability `false`.

::: tip Where the grants surface, listing-side
When the `grants` feature is on and the `database` driver is active, Drive **listings**
narrow to the actor's grant-reachable candidate set, and `GET {prefix}/shared` returns the
cross-storage "shared with me" set. Both are documented under
[Authorization › Access-aware listings](/concepts/access-model#access-aware-listings) — including
the candidate-superset-vs-exact semantics and the open-driver behaviour.
:::

::: code-group
<!-- snippet: shell -->

```bash [grant]
curl -X POST https://app.test/api/vaulter/v1/nodes/01J.../grants \
  -H 'Authorization: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -d '{
    "subject": { "type": "user", "id": "42" },
    "role": "writer",
    "scope": "subtree",
    "expires_at": "2026-12-31T23:59:59Z"
  }'
```

<!-- snippet: shell -->

```bash [revoke]
curl -X DELETE https://app.test/api/vaulter/v1/nodes/01J.../grants/01J... \
  -H 'Authorization: Bearer <token>'
```

```json [grant response 201]
{
  "data": {
    "id": "01J...",
    "node_id": "01J...",
    "subject": { "type": "user", "id": "42" },
    "role": "writer",
    "role_version": 1,
    "scope": "subtree",
    "expires_at": "2026-12-31T23:59:59.000000Z",
    "created_by": "1",
    "created_at": "2026-06-30T10:00:00.000000Z"
  }
}
```
:::

### Request validation

`CreateGrantRequest` validates the body:

| Field | Rules |
|---|---|
| `subject.type` | required, string, max 255, a registered subject type |
| `subject.id` | required |
| `role` | required, one of the registered role keys |
| `scope` | nullable enum (`item`/`subtree`), defaults `subtree` |
| `expires_at` | nullable date, in the future |

## Events

Both events implement `ShouldDispatchAfterCommit` — they fire only after the grant
transaction commits, so a listener never sees an uncommitted grant.

| Event | Payload | When |
|---|---|---|
| `Vaulter\Drive\Events\Access\NodeGrantCreated` | `NodeGrant $grant`, `int\|string\|null $actorId` | a grant was created or updated |
| `Vaulter\Drive\Events\Access\NodeGrantRevoked` | `Node $node`, `Subject $subject`, `int $revoked`, `int\|string\|null $actorId` | a subject's grants were revoked |

A downstream listener can bump an `acl_version` cache or record activity:

<!-- snippet: host -->

```php
use Vaulter\Drive\Events\Access\NodeGrantCreated;

Event::listen(function (NodeGrantCreated $event): void {
    Cache::increment("acl_version:{$event->grant->node_id}");
});
```

## On the client — useGrants

`@vaulter/vue` ships `useGrants(nodeId)` for reactive management of a node's grants,
backed by the injected client's `grants` namespace
(`client.grants.list` / `grant` / `revoke`).

<!-- snippet: host -->

```vue
<script setup lang="ts">
import { useGrants } from '@vaulter/vue'

const { grants, loading, list, grant, revoke } = useGrants(nodeId)

await list()

const created = await grant({
  subject: { type: 'user', id: 42 },
  role: 'writer',
  scope: 'subtree',
})

await revoke(created.id)
</script>
```

A `grant()` prepends to the reactive list; `revoke(grantId)` removes that one grant. The `VaulterGrantsPanel` component wraps this for a drop-in grants UI — see
[Vue components](/guides/vue).

## See also

- [Authorization](/concepts/access-model) — how grants are walked and unioned up to a boundary.
- [Permission Drivers](/extending/permission-drivers) — the role lane folded alongside grants.
- Panel-scoped grants and subtree inheritance details: `node_grants`, `inherit_grants`.
