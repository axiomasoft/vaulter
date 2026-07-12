---
title: Facade & Manager
description: The Vaulter facade and VaulterManager — the public entry point for host-app integration.
outline: deep
---

# Facade &amp; Manager

`Vaulter\Facades\Vaulter` is the package's public entry point. It is a thin Laravel
facade over `Vaulter\VaulterManager`, which is registered as a container singleton
(aliased `'vaulter'`) by `VaulterServiceProvider`. Every method below is a real
method on `VaulterManager`.

```php
use Vaulter\Facades\Vaulter;

$drive = Vaulter::for($project);          // open a Drive handle
$panel = Vaulter::panel('project_drive'); // resolve a storage panel
```

::: info Resolve it however you like
The facade, `app(VaulterManager::class)`, and the `'vaulter'` container alias all
return the same singleton. Inject `VaulterManager` in a constructor if you prefer DI
over the facade.
:::

## Method summary

| Method | Returns | Purpose |
|---|---|---|
| `for(Model $entity, ?string $panel = null)` | `DriveContext` | Open a Drive handle bound to a host entity's storage in a panel |
| `forStorage(Storage $storage)` | `DriveContext` | Open a Drive handle bound to an already-resolved storage row |
| `panel(string $name = 'default')` | `Panel` | Resolve a storage panel by id |
| `panels()` | `PanelRegistry` | The singleton panel registry |
| `registerPanel(StoragePanelProvider\|Panel $panel)` | `void` | Register a storage panel at runtime |
| `registerDocumentType(string $type, array $definition)` | `void` | Register a custom document type |
| `documentTypes()` | `array` | The registered custom document types |
| `registerHandler(FileHandler\|string $handler)` | `void` | Register a document handler (format extensibility) |
| `handlers()` | `HandlerRegistry` | The document-handler registry |
| `registerExporter(DocumentExporter $exporter)` | `void` | Register a custom export format |
| `exporter(string $format)` | `DocumentExporter` | Resolve an exporter by format key |
| `exporterFormats()` | `list<string>` | All registered export format keys |

::: warning Facade docblock vs. manager
The `@method` docblock on the `Vaulter` facade only annotates a subset of these
(`for`, `forStorage`, `panel`, `panels`, `registerPanel`, `registerDocumentType`,
`documentTypes`). The remaining methods — `registerHandler`, `handlers`,
`registerExporter`, `exporter`, `exporterFormats` — are real `VaulterManager`
methods callable through the facade; they are simply not (yet) in the `@method`
block, so your IDE may not autocomplete them. Call `app(VaulterManager::class)` if
you want full static typing.
:::

## Drive entry points

### `for(Model $entity, ?string $panel = null): DriveContext`

The primary integration entry point. Get-or-creates the `v_storages` row for the
host entity within a panel (idempotent — the root folder is auto-provisioned) and
returns an ergonomic `DriveContext` whose methods delegate to the Drive Actions and
repositories. The panel defaults to `'default'`.

```php
$drive = Vaulter::for($project);                 // default panel
$drive = Vaulter::for($project, 'project_drive'); // a named panel
```

### `forStorage(Storage $storage): DriveContext`

Open a Drive handle bound to an already-resolved `Storage` row. This is the seam the
HTTP layer uses: a route-model-bound `Storage` (resolved through the swappable
`config('vaulter.models.storage')`) maps straight to a `DriveContext` without
re-deriving the owner. The handle's panel mirrors `$storage->panel`, so its Actions
run inside the right panel scope.

```php
$drive = Vaulter::forStorage($storage);
```

See [`DriveContext`](/guide/drive) for the handle's full method surface.

## Panels

### `panel(string $name = 'default'): Panel`

Resolve a storage panel by id (default `'default'`). Delegates to the
`PanelRegistry`.

### `panels(): PanelRegistry`

The container-singleton panel registry — one `Panel` per id. Built from
`config('vaulter.panels')` (provider FQCNs) plus `config('vaulter.default_panel')`.

### `registerPanel(StoragePanelProvider|Panel $panel): void`

Register a storage panel at runtime, either by provider or by an already-built
`Panel`. The registry validates it and keys it by its id.

```php
Vaulter::registerPanel(new ProjectStoragePanel());          // a provider
Vaulter::registerPanel(Panel::make('reports', /* … */));    // a built Panel
```

## Authorization

Authorization runs through the swappable `HostRoleDriver` lane
(`config('vaulter.drivers.permission')`) combined with the Vaulter-owned
`AccessResolver`. See [Contracts → Access](/reference/contracts#access).

## Document types &amp; handlers

### `registerDocumentType(string $type, array $definition): void` / `documentTypes(): array`

Register and read back custom document-type definitions (kept in-memory on the
manager). The built-in document types are configured separately via
`config('vaulter.enabled_document_types')`.

### `registerHandler(FileHandler|string $handler): void` / `handlers(): HandlerRegistry`

The format-extensibility seam. Adding a new format is one class + one call —
`registerHandler()` accepts an FQCN or an instance and delegates to the
container-bound `Vaulter\Engines\DocumentHandlerRegistry` (core-owned since P0.4/D10 —
the single source of truth for mime → extension → capability → priority resolution,
always resolvable even without `vaulter-documents` installed). No migration, no core change,
no package required.

```php
Vaulter::registerHandler(\App\Vaulter\CadHandler::class);
```

You can also list handler FQCNs in `config('vaulter.handlers')` or bind them to the
tagged container binding `'vaulter.handlers'`. See
[Recipes → Custom handler](/guide/recipes).

## Exporters

### `registerExporter(DocumentExporter $exporter): void`

Register a custom export format. The exporter is keyed by its own `format()`.

### `exporter(string $format): DocumentExporter`

Resolve an exporter by format key. Looks first at runtime-registered exporters, then
at `config('vaulter.exporters')` (resolving the FQCN through the container).
Throws `Vaulter\Exceptions\Documents\UnknownExportFormatException` for an unknown
format.

### `exporterFormats(): list<string>`

All known export format keys — the union of configured and runtime-registered
exporters. Built-ins are `html` and `markdown`.

```php
Vaulter::registerExporter(new \App\Vaulter\PdfExporter());
Vaulter::exporterFormats(); // ['html', 'markdown', 'pdf']
```

See [Recipes → Custom export](/guide/recipes).

## See also

- [Configuration reference](/reference/configuration) — every config key
- [Contracts reference](/reference/contracts) — the interfaces these methods accept and return
- [Events reference](/reference/events) — what gets dispatched as you drive
- [Commands reference](/reference/commands) — the Artisan surface
