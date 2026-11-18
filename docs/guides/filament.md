---
title: Filament admin plugin
description: Manage Vaulter storages, nodes, ACL grants, share links and the activity feed from your existing Filament 5 panel — every read and write goes through the authorized drive handle.
outline: deep
---

# Filament admin plugin

`axiomasoft/vaulter-filament` is an optional **Filament 5** plugin that adds Vaulter management
to an existing Filament panel. It requires `axiomasoft/vaulter-drive` (and, through it, core and media) and
registers one family of resources, the **Drive / permissions** domain — `Drive`, `Node`, `NodeGrant`, `NodeShare`,
`Activity`.

Every resource reads and writes through the authorized [`DriveHandle`](/reference/php-api) —
`Vaulter::drive($drive)->actingAs(PanelActor::current())` — and its snapshot DTOs. A row of a table is a snapshot
the drive returned, not an Eloquent record: the panel queries no Vaulter model, never calls an Action or Repository
and never evaluates grants itself, so it cannot bypass the ACL.

::: tip Related reading
The Drive UI for end users lives in the [Vue package](/guides/vue). The authorization model these
resources respect is covered under [Authorization](/concepts/access-model).
:::

## Installation

<!-- snippet: shell -->

```bash
composer require axiomasoft/vaulter-filament
```

The plugin ships its provider via Laravel package discovery
(`Vaulter\Filament\VaulterFilamentServiceProvider`). Publish the config when you want to toggle
resources or rename the navigation group:

<!-- snippet: shell -->

```bash
php artisan vendor:publish --tag=vaulter-filament-config
```

This writes `config/vaulter-filament.php`.

## Registering the plugin

Add `VaulterPlugin::make()` to your panel provider's `plugins()` array:

<!-- snippet: host -->

```php
use Filament\Panel;
use Vaulter\Filament\VaulterPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugins([
            VaulterPlugin::make(),
        ]);
}
```

The plugin id is `vaulter`. If you need the concrete plugin instance back from a panel (for its
config / toggles), use the typed accessor instead of an inline cast:

<!-- snippet: host -->

```php
use Vaulter\Filament\VaulterPlugin;

$plugin = VaulterPlugin::current($panel); // returns the VaulterPlugin
```

`register()` calls `$panel->resources([...])` with only the resources whose config toggle is on
(see [Configuration](#configuration)).

## Configuration

`config/vaulter-filament.php` has two sections:

<!-- snippet: config -->

```php
return [
    // The Filament navigation group every Vaulter resource is listed under.
    'navigation_group' => 'Vaulter',

    // Toggle which resources the plugin registers. Each defaults to true.
    'resources' => [
        // Drive / permissions domain.
        'drive'    => true,
        'node'     => true,
        'grant'    => true,
        'share'    => true,
        'activity' => true,
    ],
];
```

Turn a key to `false` to drop the resource from the panel. The plugin reads each toggle via
`config('vaulter-filament.resources.{key}', true)` — an omitted or non-boolean value is treated as
`true`.

## How authorization works

Every Drive/permissions resource resolves its acting admin through `Vaulter\Filament\Support\PanelActor`
and works through the handle that admin gets from the drive. The handle decides what exists for them and what
they may do, so a change in the drive access rules reaches the panel without a panel change:

<!-- snippet: host -->

```php
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Filament\Support\PanelActor;

$handle = Vaulter::drive($drive)->actingAs(PanelActor::current());

$handle->nodes()->rename(node: $id, name: $name); // 404 / 403 exactly as over HTTP
```

`PanelActor` provides two things:

| Method | Returns | Used for |
|--------|---------|----------|
| `PanelActor::current()` | `?Authorizable` | the actor passed to `actingAs(...)`. `null` → no drives and no rows (a read-only shell) |
| `PanelActor::id()` | `int\|string\|null` | the acting admin's id for attribution. `null` → attributed as "no actor", never a fabricated id |

A row action is only **visible** when the handle's abilities for that node (`nodes()->abilitiesMany()`)
allow it, and the operation re-checks on execution, so a forged call is refused with the same error as the
HTTP API. A drive appears in the panel when the admin can view its root node; an admin who only holds a
grant on a nested folder reaches it through the API or the Vue client, not through the panel tables.

## Resources

### Drive / permissions domain

#### DriveResource

A **read-mostly** view of the ownable, polymorphic disk roots. Drives are provisioned by
`Vaulter\Drive\DriveProvisioner` (get-or-create on a host entity), never minted from the panel — so this resource
**cannot create or edit** a row (`canCreate()` returns `false`); it lists and inspects.

- **Rows**: the drive snapshots of `Vaulter::drives($actor)` — a drive is listed when the admin can view its
  root.
- **Table**: name, owner (`owner_type #owner_id`), profile badge, used bytes, quota (`∞` when unlimited),
  status, created-at. Default sort `created_at desc`.
- **Details**: the `view` row action opens a read-only modal (Drive / Owner / Usage), since a snapshot has no
  model page to open.
- **Pages**: `index` only (`ListDrives` under `Vaulter\Filament\Resources\DriveResource\Pages`).

#### NodeResource

The heart of the panel: the unified `folder | file | document | shortcut` node tree. Mutations are
**never** flat-form edits — each row action calls an operation on the authorized
[`DriveHandle`](/reference/php-api):

| Row action | Handle call | Gating ability |
|------------|-------------|----------------|
| Rename | `nodes()->rename(...)` | `Ability::Edit` |
| Move | `nodes()->move(...)` (destinations are the folders the admin may create into; blank = root) | `Ability::Move` on the node, `Create` on the destination |
| Trash | `nodes()->trash(...)` | `Ability::Trash` |
| Restore | `trash()->restore(...)` (visible only on a trashed node) | `Ability::Restore` |
| Delete permanently | `trash()->purge(...)` (visible only on a trashed node) | `Ability::Delete` |

<!-- snippet: host -->

```php
// Inside NodeResource: the rename row action's handler.
PanelDrives::handle($driveId)->nodes()->rename(node: $id, name: $name);
```

- **Rows**: Filament custom records built from the handle's listings (`children()` walked per drive,
  plus `trash()->list()`), bounded to the first 2000 nodes. There is no Eloquent query behind the table. Only
  the drives whose root the admin can view contribute rows.
- **Table**: a kind icon (`folder` → folder, `file` → document, `document` → document-text,
  `shortcut` → arrow-top-right), name (root rows show `(root)`), kind badge, storage, parent, size,
  trashed-at, created-at.
- **Filters**: kind (`SelectFilter` over `NodeKind` cases), storage, and a `Trashed` ternary
  (`Live only` / `Trashed only`).
- **Trashed**: trashed rows come from the trash listing, so the Trashed filter flips between the two sets;
  purged nodes no longer exist. Sorting, search (by name) and filters run over the loaded rows.
- **Create**: disabled — folders/files are created through the drive operations, not a resource form.
- **Pages**: `index` only.

#### NodeGrantResource

List, create and revoke the per-node ACL grants the resolver folds into effective permissions. A
grant addresses a subject (`subject_type` + `subject_id`) on a node with a **role** (`role_key`), an
**item|subtree** scope and an optional expiry. Grants are positive only.

Both mutations go through the handle's `access()` operations and require `Ability::Manage` on the target node:

- **Create** (the list page's `Create grant` header action) →
  `access()->grant(...)` (idempotent upsert on the grant key).
- **Revoke** (row action) → `access()->revoke(...)`.

<!-- snippet: host -->

```php
// NodeGrantResource::grantFromData — minting through the handle.
$handle->access()->grant($nodeId, new GrantInput(
    subject: new Subject(type: $subjectType, id: $subjectId),
    role: $role,                         // a registered role key, e.g. reader | writer
    scope: GrantScope::from($scope),     // item | subtree
    expiresAt: $expiresAt,
));
```

- **Rows**: for every live node the admin may view and manage, the grants the handle's `access()` operations list
  (a node the handle refuses contributes no row), bounded like the node table.
- **Table**: node, subject (`type #id`), role badge, scope, expires-at (`Never` when null), created-at.
  Filter: role.
- **Form fields**: node, subject type (a registered morph alias such as `user` / `role` / `team`),
  subject id, role, scope, expires-at.
- **Create**: via the header action only (`canCreate()` is `false`).
- **Pages**: `index` only.

::: warning Subject type is a morph alias
The grant form's `subject_type` expects a **registered morph alias** (`user`, `role`, `team`), not a
fully-qualified class. Register your aliases via `Relation::enforceMorphMap([...])`.
:::

#### NodeShareResource

List, create and revoke **bearer share-links** on a node. The raw token is **never stored and never
shown in the table** — only the non-secret `token_prefix` plus the live/expired/revoked/exhausted
status. The raw token is surfaced **exactly once**, at mint time, via a persistent notification.

- **Create** (the list page's `Create share link` header action) → `shares()->create(...)`
  (mints + hashes the token; the raw value is shown once).
- **Rotate token** (row action) → `shares()->rotate(...)` (new token shown once; the old one stops working).
- **Revoke** (row action) → `shares()->revoke(...)`.

Both require `Ability::Share` on the node.

<!-- snippet: host -->

```php
$minted = Vaulter::drive($owner)->actingAs($user)->shares()->create($node, new ShareLinkInput(
    role: $role,                       // previewer | reader | commenter | writer
    audience: ShareAudience::from($audience), // anyone | authenticated | tenant
    scope: GrantScope::from($scope),   // item | subtree
    expiresAt: $expiresAt,
    password: $password,
    maxUses: $maxUses,
));

// Shown exactly once — never recoverable from the row afterward:
//   app(\Vaulter\Drive\Configuration\SharingOptions::class)->urlBase() . '/' . $minted->token
```

- **Rows**: the share links the handle's `shares()` operations list for every node the admin may manage.
- **Table**: node, `token_prefix…` badge, role, scope, a computed status badge
  (`Live` / `Revoked` / `Expired` / `Exhausted`), use count (`n / max` when
  capped), expires-at, created-at. Filter: a `Revoked` ternary.
- **Form fields**: node, role, audience, scope, optional password, max-uses, expires-at.
- **Create**: via the header action only.
- **Pages**: `index` only.

#### ActivityResource

A **read-only** view of the append-only `v_activity` audit log — verb, actor, target and time. The
model is append-only by construction, so the resource is non-creatable, non-editable and
non-deletable (`canCreate`/`canEdit`/`canDelete` all return `false`).

- **Rows**: each listed drive's `activity()->forDrive(...)` entries — the drive's own projection decides which
  entries and fields the admin sees — bounded to the first 2000.
- **Table**: verb badge, actor (`type #id`), target (`type #id`), drive, when. Filter: verb
  (`SelectFilter` over the event catalogue).
- **Details**: a read-only modal with Event / Actor / Target / Request sections (the Request section includes
  `context` plus ip / user-agent / request-id).
- **Pages**: `index` only (`ListActivities` under `Vaulter\Filament\Resources\ActivityResource\Pages`).

## Models are not queried

The panel lists snapshots, so it does not matter whether your host swapped a Vaulter model for a subclass
(`vaulter-drive.models.*`): the drive handle resolves the swapped model, and the panel keeps governing it without
any change. The `drive`, `node`, `grant`, `share` and `activity` toggles in `config/vaulter-filament.php` are the only
configuration.

## Why a separate package

Filament is a heavy, optional dependency. Keeping the admin in its own package means apps that don't
use Filament never pull it in, and its CI runs in isolation against a Filament-compatible Laravel —
so it never constrains the core package's Laravel test matrix.

## See also

- [Vue package](/guides/vue) — the Drive UI for end users.
- [Authorization](/concepts/access-model) — the `AccessResolver`, `Ability`, grants and share-links these
  resources respect.
- [Sharing](/guides/sharing) — the share-link channel `NodeShareResource` administers.
