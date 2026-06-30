---
title: Storage Panels
description: Configure isolated drives per host entity with zero extra migrations — the Panel builder, StoragePanelProvider hooks, feature flags, and the rare advanced own-tables mode.
outline: deep
---

# Storage Panels

A **Storage Panel** is a named configuration envelope you bind to any host
entity (a `User`, `Team`, `Project`, `Workspace` — whatever model you own).
It is the storage/isolation axis of Vaulter: it decides which tables a drive
lives in, which features it has, how it presents nodes, and how its primary
keys are shaped.

The headline property is **zero extra migrations**. By default every panel
shares the *one* `v_*` schema that ships with Vaulter. Provisioning a drive for
a brand-new host entity does not create a table, does not run a migration, and
does not require any scaffolding — you write one provider class, register its
FQCN, and call `Vaulter::for($entity)`.

::: tip Panel ≠ storage type
A panel is the **storage/isolation axis**. It is strictly separate from the
**storage type** (`private` | `team` | `public`), which is a policy preset a
panel *carries*. One panel always has exactly one storage type; many panels can
share the same storage type.
:::

## The mental model

```
Host entity  ──owns──▶  v_storages row  ──root──▶  v_nodes tree
 (User/Team/…)            (panel = 'project')        (folders, files, documents)
      ▲                         ▲
      │                         │ panel discriminator column
   Vaulter::for($entity, 'project')
      │
   PanelRegistry ──get('project')──▶ Panel value object
                                       (tables, models, features, id strategy)
```

- One **shared schema** (`v_storages`, `v_nodes`, `v_blobs`, …) holds every
  panel's data.
- The `panel` column on `v_storages` and `v_nodes` is the discriminator that
  keeps panels apart on the shared schema.
- The `(owner_type, owner_id, panel)` triple uniquely identifies a drive, so
  the same entity can own one drive per panel.

See [Architecture](/guide/architecture) for how panels sit beside the
node tree, CAS blobs and the access resolver, and [Node tree](/guide/drive)
for what lives inside a storage.

## Quick start: the default panel

You do not have to declare anything to use Vaulter. Every install ships with a
built-in `default` panel registered automatically, so the package works out of
the box on the shared `v_*` schema:

```php
use Vaulter\Facades\Vaulter;

// No panel argument → the built-in 'default' panel.
$drive = Vaulter::for($user);

$drive->createFolder('Invoices');
```

`Vaulter::for($entity)` **get-or-creates** the `v_storages` row for that entity
inside the panel (idempotent — a second call returns the same row), provisions
the root folder node, and returns a [`DriveContext`](/reference/facade). It is
the package's primary integration entry point.

```php
// VaulterManager::for — the signature
public function for(Model $entity, ?string $panel = null): DriveContext
```

When `$panel` is `null` it defaults to `'default'`. To target another panel,
pass its id:

```php
$drive = Vaulter::for($project, 'project');
```

## Declaring a panel

A panel is configured by a `StoragePanelProvider` subclass. You build the panel
fluently inside `panel()`:

::: code-group

```php [app/Vaulter/Panels/ProjectStoragePanel.php]
<?php

declare(strict_types=1);

namespace App\Vaulter\Panels;

use App\Models\Project;
use Vaulter\Panels\Panel;
use Vaulter\Panels\StoragePanelProvider;

final class ProjectStoragePanel extends StoragePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('project')              // the panel id used in Vaulter::for($e, 'project')
            ->host(Project::class)        // documentation/intent: the host model
            ->storageType('team')         // policy preset: private|team|public
            ->feature('share_links', true);
    }
}
```

```php [config/vaulter.php]
'panels' => [
    \App\Vaulter\Panels\ProjectStoragePanel::class,
],
```

:::

Providers are registered **by FQCN only** in `config('vaulter.panels')` — never
as closures — so the config stays cache-safe (`config:cache` works). At boot the
[`PanelRegistry`](#the-panel-registry) resolves each provider into a `Panel`
value object and validates it.

::: info The default panel is registered first
`PanelRegistry::fromConfig()` registers the `config('vaulter.default_panel')`
provider **first**, then your panels in order. Because registration is keyed by
id, a panel that declares `id('default')` *overrides* the built-in default.
:::

### Scaffold one with `vaulter:make-panel`

You rarely write the provider by hand. The generator writes a thin, editable
provider into `app/Vaulter/Panels`:

```bash
php artisan vaulter:make-panel project_drive --host="App\Models\Project"
```

Signature and options:

| Option | Effect |
|---|---|
| `name` (argument) | The panel id (snake/kebab), e.g. `project_drive`. |
| `--host=` | The host model FQCN this storage embeds into (emits `->host(...)`). |
| `--prefix=` | Table name prefix — **opts into the advanced own-tables mode**. |
| `--no-grants` | Generate a panel without per-node grants (emits `->withoutGrants()`). |
| `--id=` | Primary key strategy (`ulid` \| `uuid7` \| `bigint`) — **own-tables only**, emitted only alongside `--prefix`. |
| `--force` | Overwrite the provider if it already exists. |

The command **does not** touch your published config and **does not** generate
migrations — a panel is a zero-migration config envelope by default. It prints
the next steps explicitly:

```
Storage panel [project_drive] scaffolded.
Next steps:
  1. Register the provider in config/vaulter.php under 'panels':
         \App\Vaulter\Panels\ProjectDriveStoragePanel::class,
  2. Edit the provider: feature flags, fill/present hooks, pipes.
  3. ONLY if you set a tablePrefix: run php artisan vaulter:panel:migrations project_drive
  4. Add any custom columns in your own follow-up migration.
```

::: warning You still register the FQCN yourself
`make-panel` writes the class but leaves `config('vaulter.panels')` untouched
(it never edits published config). Add the printed FQCN to the `panels` array
yourself — the panel is invisible until you do.
:::

## The `Panel` builder

`Vaulter\Panels\Panel` is a fluent, Filament-style mutable builder. A panel only
declares what it *overrides*; every unset value falls back to the global Vaulter
config. Resolution order is always **override → prefix → global**.

| Method | Purpose | Falls back to |
|---|---|---|
| `id(string)` | The panel id (the key in the registry). | — (required) |
| `host(string)` | The host model FQCN (intent/documentation). | `null` |
| `storageType(string)` | Storage-type policy preset: `private`/`team`/`public`. | `'private'` |
| `grants()` / `withoutGrants()` | Enable/disable per-node grants. | enabled |
| `feature(string, bool = true)` | Toggle a single feature for this panel. | global `features` |
| `features(array)` | Merge a map of feature overrides. | global `features` |
| `models(array)` | Map entity key → model class (own per-panel models). | `config('vaulter.models.*')` |
| `pipes(array)` | Append extra pipeline pipe classes. | `[]` |
| `resource(string)` | A Filament resource FQCN for this panel. | `null` |
| `tablePrefix(string)` | **Own-tables mode** — per-panel table prefix. | shared schema |
| `tables(array)` | **Own-tables mode** — explicit base → physical table map. | shared schema |
| `idStrategy(string)` | **Own-tables only** — per-panel key strategy. | `config('vaulter.ids.default')` |

::: details How `model()` and `table()` resolve
`Panel::model($entity)` returns the panel's `models()` override if present,
otherwise `config("vaulter.models.{$entity}")` (so a host can swap any model
globally, then a panel can override it locally).

`Panel::table($base)` returns the explicit `tables()` entry if present, else
`"{prefix}_{base}"` when a `tablePrefix()` is set, else the shared default table
via `Config::table($base)`. With no prefix and no map a panel resolves the
shared `v_*` tables — that is the zero-migration path.
:::

## Feature flags

A panel can turn Vaulter features on or off independently of the global
`config('vaulter.features')` defaults. Panel-level overrides win; otherwise the
panel uses Vaulter's built-in per-feature defaults.

```php
return $panel
    ->id('public_docs')
    ->storageType('public')
    ->withoutGrants()                 // grants → off for this panel
    ->feature('share_links', true)    // share links → on
    ->features([
        'document_versions' => true,
        'activity' => false,
    ]);
```

The built-in per-feature defaults (used when neither the panel nor a `feature()`
override sets a value) are:

| Feature | Default | Notes |
|---|---|---|
| `grants` | follows `hasGrants` | `withoutGrants()` flips it off. |
| `versions` | on | File version history (`v_files`/`v_file_versions`). |
| `blobs` | on | Content-addressed blob store. |
| `tags` | on | |
| `activity` | on | Audit/activity feed. |
| `uploads` | on | Resumable uploads. |
| `extra_data` | on | The `extra_data` JSON escape-hatch column. |
| `reliability` | on | Transactional outbox + webhooks. |
| `share_links` | off | Bearer share links. |
| `public_listing` | off | |
| `document_versions` | off | Node-attached document versions. |

::: tip Features drive the schema, not just behaviour
In own-tables mode (below) feature flags also decide **which tables are created**
for the panel. `blobs` gates `v_blobs`; `versions` gates the file/version tables;
`grants` gates `v_node_grants`; `share_links` gates `v_node_shares`;
`document_versions` gates `v_node_document_versions`; `uploads` gates the upload
tables; `activity` gates `v_activity`; `reliability` gates the outbox/webhook
tables. `v_storages` and `v_nodes` are always created.
:::

See [Configuration](/guide/configuration#features) for the global feature map and
[Authorization](/guide/authorization) for what `grants` enables.

## Provider hooks

`StoragePanelProvider` exposes four optional hooks so a panel can enrich the node
pipeline and the node representation **without forking the package**. All four
are no-ops by default — override only what you need.

| Hook | When it runs | Default |
|---|---|---|
| `fillNode(array $data): array` | Extra attributes to persist on a node being stored. | `[]` |
| `presentNode(array $node): array` | Extra attributes to expose when presenting a node. | `[]` |
| `pipes(): array` | Extra pipeline pipe classes (ingest/move/create). | `[]` |
| `booted(Panel $panel): void` | Runs once, after the panel is registered. | no-op |

```php
final class ProjectStoragePanel extends StoragePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('project')->storageType('team');
    }

    /** Stamp every node created in this panel with the project's tenant. */
    public function fillNode(array $data): array
    {
        return ['extra_data' => ['source' => 'project-drive']];
    }

    /** Add a computed attribute when a node is serialised back to the client. */
    public function presentNode(array $node): array
    {
        return ['badge' => 'Project'];
    }

    public function booted(Panel $panel): void
    {
        // e.g. warm a cache, log, or register a per-panel listener.
    }
}
```

::: info `pipes()` on the provider vs `pipes()` on the builder
You can append pipeline pipes two ways: the provider's `pipes()` hook, or the
builder's `->pipes([...])` call inside `panel()`. Both append to the panel's
pipe list; the builder form keeps the FQCNs validated at boot (see below).
:::

## The panel registry

All resolved panels live in a single container singleton,
`Vaulter\Panels\PanelRegistry`. It is the single source of truth — every `Panel`
value object keyed by its id.

```php
use Vaulter\Facades\Vaulter;

$registry = Vaulter::panels();      // the PanelRegistry
$panel    = Vaulter::panel('project'); // a Panel by id (default: 'default')

$registry->has('project');          // bool
$registry->all();                   // array<string, Panel>
$registry->default();               // the 'default' panel
```

You can also register a panel at runtime (e.g. in a test or a host-specific
service provider):

```php
Vaulter::registerPanel(new App\Vaulter\Panels\ProjectStoragePanel);
// or an already-built Panel instance:
Vaulter::registerPanel($panel);
```

### Boot-time validation (fail loud)

The registry validates every panel as it is registered, so a broken panel fails
loudly **at boot** rather than corrupting a request later:

- Every referenced model FQCN must exist **and** be an Eloquent `Model`.
- Every referenced pipe and the `resource` FQCN must exist.
- A per-panel `idStrategy()` is **rejected on the shared schema** — it is only
  coherent when the panel has its own tables.

A violation throws `Vaulter\Exceptions\Panels\InvalidPanelException`; looking up
an unknown id throws `Vaulter\Exceptions\Panels\UnknownPanelException`. See the
[error reference](/guide/errors) for both.

```php
// This panel is rejected at boot — idStrategy without own tables:
$panel->id('bad')->idStrategy('uuid7'); // ← InvalidPanelException::idStrategyWithoutOwnTables
```

## Advanced: own-tables mode

::: warning This is the rare case
**Most panels never need their own tables.** The shared-schema default (zero
migrations) is the recommended path. Reach for own-tables only when you have a
concrete reason to physically isolate a panel's data — a separate retention/GC
policy, a hard tenancy boundary at the table level, or a different primary-key
strategy for one drive.
:::

A panel opts into per-panel tables by declaring **either** a `tablePrefix()`
**or** an explicit `tables()` map. Either one flips `Panel::hasOwnTables()` to
`true`:

```php
return $panel
    ->id('project')
    ->host(Project::class)
    ->storageType('team')
    ->tablePrefix('project_drive')   // → project_drive_storages, project_drive_nodes, …
    ->idStrategy('uuid7')            // now coherent: this panel has its own tables
    ->models([
        'nodes' => \App\Models\ProjectNode::class,
    ]);
```

With `tablePrefix('project_drive')`, `Panel::table('nodes')` resolves to
`project_drive_nodes` instead of the shared `v_nodes`.

### Generate the migration

Only own-tables panels need a migration. Generate it with:

```bash
php artisan vaulter:panel:migrations project
```

The generated file is intentionally **thin and git-visible**: it resolves the
panel from the registry at migration time and delegates the column definitions to
`PanelTablesBuilder`, so it can never drift from the shared schema. Schema
*evolution* is a separate concern — you add custom columns in your own follow-up
migrations, never by regenerating this file.

```php
// The generated migration, in full:
return new class extends Migration
{
    public function up(): void
    {
        (new PanelTablesBuilder)->createAll(
            app(PanelRegistry::class)->get('project'),
        );
    }

    public function down(): void
    {
        (new PanelTablesBuilder)->dropAll(
            app(PanelRegistry::class)->get('project'),
        );
    }
};
```

`PanelTablesBuilder` creates only the tables the panel's [features](#feature-flags)
enable, in dependency order, with all foreign keys pointing at the panel's *own*
resolved tables — so a prefixed panel never references the shared schema by
mistake.

::: tip Output path
By default the migration is written to your app's `database/migrations`. Override
the destination with `config('vaulter.migrations.path')`.
:::

::: danger A shared-schema panel cannot generate a migration
Running `vaulter:panel:migrations` on a panel with no prefix and no table map is
rejected — the default panel ships with the base migrations and needs no
generated file:

```
Vaulter panel [team] writes to the shared default-panel schema and needs no
generated migration. Give it a tablePrefix() or a tables() map to generate
per-panel tables.
```
:::

### Per-panel models

A shared-schema model (`Vaulter\Models\Drive\Node`, etc.) is correct for the
default panel — it resolves the shared `v_*` table and the global key strategy.
But a **prefixed** panel needs *concrete* per-panel model classes so a queued
job, broadcast or Scout rehydrate target the right table (not a runtime `$table`
footgun).

Opt a model in with the `HasVaulterPanel` trait, declaring the panel and entity
it belongs to:

```php
use Vaulter\Models\Concerns\HasVaulterPanel;
use Vaulter\Models\Drive\Node;

final class ProjectNode extends Node
{
    use HasVaulterPanel;

    protected string $vaulterPanel = 'project';
    protected string $vaulterEntity = 'nodes';
}
```

Now `getTable()` resolves to `app(PanelRegistry::class)->get('project')->table('nodes')`
(e.g. `project_drive_nodes`), and in own-tables mode the key strategy comes from
the same panel. Map the model into the panel so Vaulter resolves it:

```php
->models(['nodes' => \App\Models\ProjectNode::class])
```

::: info The id strategy is per-panel only in own-tables mode
`idStrategy()` is rejected at boot on the shared schema (a per-panel key shape on
shared tables is incoherent). On the shared schema every panel uses the global
`config('vaulter.ids.default')`. In own-tables mode the panel's `idStrategy()`
override wins, falling back to the global default when unset.
:::

## Worked example: shared vs own-tables

::: code-group

```php [Shared schema (zero migrations)]
// A public, share-link drive on the SAME v_* tables — no migration needed.
final class TeamStoragePanel extends StoragePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('team')
            ->storageType('public')
            ->withoutGrants()
            ->feature('share_links', true);
    }
}
```

```php [Own tables (advanced)]
// A physically isolated drive on project_drive_* tables with uuid7 keys.
final class ProjectStoragePanel extends StoragePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('project')
            ->host(Project::class)
            ->storageType('team')
            ->tablePrefix('project_drive')
            ->idStrategy('uuid7')
            ->models(['nodes' => ProjectNode::class]);
    }
}
// Then: php artisan vaulter:panel:migrations project
```

:::

## See also

- [Configuration](/guide/configuration) — the global `models`, `features`,
  `ids`, `table_names` and `panels` keys a panel falls back to.
- [Architecture](/guide/architecture) — where panels sit in the layering.
- [Node tree](/guide/drive) — what a storage contains.
- [Authorization](/guide/authorization) — what the `grants` feature enables.
- [Error reference](/guide/errors) — `InvalidPanelException` / `UnknownPanelException`.
- ADR-0009 — the storage-panels decision record.
