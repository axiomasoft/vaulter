---
title: Filament admin plugin
description: Manage Vaulter storages, nodes, ACL grants, share links and the activity feed from your existing Filament 5 panel — every mutation routed through the core Actions and gated by the AccessResolver.
outline: deep
---

# Filament admin plugin

`axioma-studio/vaulter-filament` is an optional **Filament 5** plugin that adds Vaulter management
to an existing Filament panel. It registers two families of resources:

- the **Drive / permissions** domain — `Drive`, `Node`, `NodeGrant`, `NodeShare`, `Activity`;
- the **legacy document-centric** surface — `Document`, `Folder`, `Share`.

Every write the panel makes flows through a **core Action** (never a raw Eloquent write), and every
row action / visibility check is gated by the core
[`AccessResolver`](/guide/authorization) — so the panel can never bypass the ACL.

::: tip Related reading
The Drive UI for end users lives in the [Vue package](/guide/vue). The authorization model these
resources respect is covered under [Authorization](/guide/authorization).
:::

## Installation

```bash
composer require axioma-studio/vaulter-filament
```

The plugin ships its provider via Laravel package discovery
(`Vaulter\Filament\VaulterFilamentServiceProvider`). Publish the config when you want to toggle
resources or rename the navigation group:

```bash
php artisan vendor:publish --tag=vaulter-filament-config
```

This writes `config/vaulter-filament.php`.

## Registering the plugin

Add `VaulterPlugin::make()` to your panel provider's `plugins()` array:

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

```php
use Vaulter\Filament\VaulterPlugin;

$plugin = VaulterPlugin::current($panel); // returns the VaulterPlugin
```

`register()` calls `$panel->resources([...])` with only the resources whose config toggle is on
(see [Configuration](#configuration)); the Drive/permissions domain is registered first, then the
legacy document surface.

## Configuration

`config/vaulter-filament.php` has two sections:

```php
return [
    // The Filament navigation group every Vaulter resource is listed under.
    'navigation_group' => 'Vaulter',

    // Toggle which resources the plugin registers. Each defaults to true.
    'resources' => [
        // Drive / permissions domain (M1–M4 core).
        'drive'    => true,
        'node'     => true,
        'grant'    => true,
        'share'    => true,
        'activity' => true,

        // Legacy document-centric surface.
        'document'     => true,
        'folder'       => true,
        'legacy_share' => true,
    ],
];
```

Turn a key to `false` to drop the resource from the panel. The plugin reads each toggle via
`config('vaulter-filament.resources.{key}', true)` — an omitted or non-boolean value is treated as
`true`.

::: tip Drive-only panel
If your host app does not use the legacy document tables, set `document`, `folder` and
`legacy_share` to `false` to show only the Drive/permissions resources.
:::

## How authorization works

Every Drive/permissions resource resolves its acting admin through `Vaulter\Filament\Support\PanelActor`
and asks the core resolver whether that admin holds the required
[`Ability`](/guide/abilities) on the target node:

```php
use Vaulter\Access\AccessResolver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Filament\Support\PanelActor;

$actor = PanelActor::current(); // the authenticated panel user as an Authorizable, or null

if ($actor !== null && app(AccessResolver::class)->can($actor, Ability::Edit, $node)) {
    // the action is offered / allowed
}
```

`PanelActor` provides two things:

| Method | Returns | Used for |
|--------|---------|----------|
| `PanelActor::current()` | `?Authorizable` | the actor passed to `AccessResolver::can(...)`. `null` → an all-false ability map (a read-only shell) |
| `PanelActor::id()` | `int\|string\|null` | the `actorId` stamped on the core Action (`granted_by` / `trashed_by` …). `null` → attributed as "no actor", never a fabricated id |

A row action is only **visible** when the actor holds its ability, so the panel never advertises an
operation the admin cannot perform.

## Resources

### Drive / permissions domain

#### DriveResource

A **read-mostly** view of the ownable, polymorphic disk roots. Drives are provisioned by
`Vaulter\Drive\DriveProvisioner` (get-or-create on a host entity), never minted from the panel — so this resource
**cannot create or edit** a row (`canCreate()` returns `false`); it lists and inspects.

- **Model**: resolved via `app(\Vaulter\Configuration\VaulterConfig::class)->model('drive')` (`Vaulter\Models\Drive\Drive` by default).
- **Table**: name, owner (`owner_type #owner_id`), disk, used bytes, quota (`∞` when unlimited),
  status, created-at. Default sort `created_at desc`.
- **Infolist** (view page): Drive / Owner / Usage sections, with human-formatted byte sizes.
- **Pages**: `index` (list) and `view` (`/{record}`). Classes: `ListDrives` and `ViewDrive`
  under `Vaulter\Filament\Resources\DriveResource\Pages`.

#### NodeResource

The heart of the panel: the unified `folder | file | document | shortcut` node tree. Mutations are
**never** flat-form edits — each row action calls a core Drive Action through the
[`DriveHandle`](/reference/facade):

| Row action | Core path | Gating ability |
|------------|-----------|----------------|
| Rename | `Vaulter::forStorage($node->storage)->rename(...)` | `Ability::Edit` |
| Move | `…->move(...)` (destination picked from the storage's folders; blank = root) | `Ability::Move` |
| Trash | `…->trash(...)` | `Ability::Trash` |
| Restore | `…->restore(...)` (visible only on a trashed node) | `Ability::Restore` |
| Delete permanently | `…->forceDelete(...)` (visible only on a trashed node) | `Ability::Delete` |

```php
// Inside NodeResource: the rename row action's handler.
Vaulter::forStorage($record->storage)->rename(
    node: $record,
    name: $name,
    actorId: PanelActor::id(),
);
```

- **Model**: `app(\Vaulter\Configuration\VaulterConfig::class)->model('node')` (`Vaulter\Models\Drive\Node`).
- **Table**: a kind icon (`folder` → folder, `file` → document, `document` → document-text,
  `shortcut` → arrow-top-right), name (root rows show `(root)`), kind badge, storage, parent, size,
  trashed-at, created-at.
- **Filters**: kind (`SelectFilter` over `NodeKind` cases), storage, and a `Trashed` ternary
  (`Live only` / `Trashed only`).
- **Query**: drops the `SoftDeletingScope` so soft-**trashed** rows (`trashed_at`) are visible to the
  Trashed filter; purged nodes no longer exist.
- **Create**: disabled — folders/files are created through the Drive Actions, not a resource form.
- **Pages**: `index` only.

#### NodeGrantResource

List, create and revoke the per-node ACL grants the resolver folds into effective permissions. A
grant addresses a subject (`subject_type` + `subject_id`) on a node with a **level** + extra **CRUDS
bits**, an **allow|deny** effect, an **item|subtree** scope and an optional expiry.

Both mutations route through core Actions and require `Ability::Share` on the target node:

- **Create** (the list page's `Create grant` header action) →
  `GrantNodeAccessAction` (idempotent upsert on the grant key).
- **Revoke** (row action) → `RevokeNodeAccessAction` (drops every grant a subject holds).

```php
// NodeGrantResource::grantFromData — minting via the core Action.
app(GrantNodeAccessAction::class)->execute(
    node: $node,
    data: new GrantNodeAccessInput(
        subject: new Subject(type: $subjectType, id: $subjectId),
        level: GrantLevel::from($level),     // reader | commenter | editor | manager
        effect: Effect::from($effect),       // allow | deny
        scope: GrantScope::from($scope),     // item | subtree
        permissions: $extraBits,             // 0–31, OR-ed onto the level bits
        expiresAt: $expiresAt,
        grantedBy: PanelActor::id(),
    ),
);
```

- **Model**: `app(\Vaulter\Configuration\VaulterConfig::class)->model('grant')` (`Vaulter\Models\Access\NodeGrant`).
- **Table**: node, subject (`type #id`), level badge, effect badge (deny → danger), scope, raw bits
  (hidden by default), expires-at (`Never` when null), created-at. Filters: effect, level.
- **Form fields**: node, subject type (a registered morph alias such as `user` / `role` / `team`),
  subject id, level, effect, scope, extra CRUDS bits (0–31), expires-at.
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

- **Create** (the list page's `Create share link` header action) → `CreateShareLinkAction`
  (mints + hashes the token; the raw value is shown once).
- **Rotate token** (row action) → `ShareOperations::rotate()` (new token shown once; the old one stops working).
- **Revoke** (row action) → `RevokeShareLinkAction` (stamps `revoked_at`; idempotent).

Both require `Ability::Share` on the node.

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
//   app(\Vaulter\Configuration\SharingConfig::class)->urlBase() . '/' . $minted->token
```

- **Model**: `app(\Vaulter\Configuration\VaulterConfig::class)->model('node_share')` (`Vaulter\Models\Sharing\NodeShare`).
- **Table**: node, `token_prefix…` badge, level, scope, a computed status badge
  (`Live` / `Revoked` / `Expired` / `Exhausted`), require-login icon, use count (`n / max` when
  capped), expires-at, created-at. Filter: a `Revoked` ternary.
- **Form fields**: node, level, scope, permissions (0–31, defaults to READ), optional password,
  require-login, allow-discovery, max-uses, expires-at.
- **Create**: via the header action only.
- **Pages**: `index` only.

#### ActivityResource

A **read-only** view of the append-only `v_activity` audit log — verb, actor, target and time. The
model is append-only by construction, so the resource is non-creatable, non-editable and
non-deletable (`canCreate`/`canEdit`/`canDelete` all return `false`).

- **Model**: `app(\Vaulter\Configuration\VaulterConfig::class)->model('activity')` (`Vaulter\Models\Activity\Activity`).
- **Table**: verb badge, actor (`type #id`), target (`type #id`), storage, when. Filter: verb
  (`SelectFilter` over `EventType` cases).
- **Infolist** (view page): Event / Actor / Target / Request sections (the Request section includes a
  `KeyValueEntry` for `context` plus ip / user-agent / request-id).
- **Pages**: `index` (list) and `view` (`/{record}`). Classes: `ListDrives` and `ViewDrive`
  under `Vaulter\Filament\Resources\DriveResource\Pages`.

### Legacy document-centric surface

These remain for hosts still on the document-centric tables. Each resolves its model through a
`VaulterConfig::model(string)` helper so a swapped model is still governed.

| Resource | Default model | What it manages |
|----------|---------------|-----------------|
| `DocumentResource` | `Vaulter\Models\Documents\Document` | searchable/sortable documents (title, type, workspace), a trashed filter, an edit form and delete / restore / force-delete actions |
| `FolderResource` | `Vaulter\Models\Documents\DocumentFolder` | the workspace folder tree (list, create, edit, delete) |
| `ShareResource` | `Vaulter\Models\Documents\DocumentShare` | tokenized public document shares — list and **revoke** (read-only; links are minted via the API) |

Toggle them off with the `document`, `folder` and `legacy_share` config keys.

## Model swappability

Every resource resolves its model through a `Vaulter\Configuration\VaulterConfig::model(string)` helper
(`storageModel()`, `nodeModel()`, `grantModel()`, `nodeShareModel()`, `activityModel()`, …). If your
host app swaps a Vaulter model for a subclass, the Filament resources keep governing it without any
change.

## Why a separate package

Filament is a heavy, optional dependency. Keeping the admin in its own package means apps that don't
use Filament never pull it in, and its CI runs in isolation against a Filament-compatible Laravel —
so it never constrains the core package's Laravel test matrix.

## See also

- [Vue package](/guide/vue) — the Drive UI for end users.
- [Authorization](/guide/authorization) — the `AccessResolver`, `Ability`, grants and share-links these
  resources respect.
- [Sharing](/guide/sharing) — the share-link channel `NodeShareResource` administers.
