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
$panel = Vaulter::profiles()->get('project_drive'); // resolve a storage panel
```

::: info Resolve it however you like
The facade, `app(VaulterManager::class)`, and the `'vaulter'` container alias all
return the same singleton. Inject `VaulterManager` in a constructor if you prefer DI
over the facade.
:::

## Method summary

| Method | Returns | Purpose |
|---|---|---|
| `for(Model $entity, ?string $panel = null)` | `DriveHandle` | Open a Drive handle bound to a host entity's storage in a panel |
| `forStorage(Drive $storage)` | `DriveHandle` | Open a Drive handle bound to an already-resolved storage row |
| `registerDocumentType(string $type, array $definition)` | `void` | Register a custom document type |
| `documentTypes()` | `array` | The registered custom document types |
| `profiles()` | `ProfileRegistry` | Named drive profiles (`get`, `register`, `registerProvider`) |
| `handlers()` | `FileHandlerRegistry` | The document-handler registry |
| `registerExporter(DocumentExporter $exporter)` | `void` | Register a custom export format |
| `exporter(string $format)` | `DocumentExporter` | Resolve an exporter by format key |
| `exporterFormats()` | `list<string>` | All registered export format keys |

The facade annotates all public manager methods. Use `profiles()` and `handlers()` for registrations.

## Drive entry points

### `for(Model $entity, ?string $panel = null): DriveHandle`

The primary integration entry point. Get-or-creates the `v_storages` row for the
host entity within a panel (idempotent — the root folder is auto-provisioned) and
returns an ergonomic `DriveHandle` whose methods delegate to the Drive Actions and
repositories. The panel defaults to `'default'`.

```php
$drive = Vaulter::for($project);                 // default panel
$drive = Vaulter::for($project, 'project_drive'); // a named panel
```

### `forStorage(Drive $storage): DriveHandle`

Open a Drive handle bound to an already-resolved `Drive` row. This is the seam the
HTTP layer uses: a route-model-bound `Drive` (resolved through the swappable
`config('vaulter.models.drive')`) maps straight to a `DriveHandle` without
re-deriving the owner. The handle's panel mirrors `$storage->profile`, so its Actions
run inside the right panel scope.

```php
$drive = Vaulter::forStorage($storage);
```

See [`DriveHandle`](/guide/drive) for the handle's full method surface.

## Profiles

### `profiles(): ProfileRegistry`

Use `Vaulter::profiles()->get('project')` to resolve a profile and
`Vaulter::profiles()->registerProvider(new ProjectProfile)` to register a provider.
See [Drive profiles](/guide/storage-panels).

## Document types and handlers

### `registerDocumentType(string $type, array $definition): void` / `documentTypes(): array`

Register and read back custom document-type definitions (kept in-memory on the
manager). The built-in document types are configured separately via
`config('vaulter.enabled_document_types')`.

### `handlers(): FileHandlerRegistry`

The format-extensibility seam. Adding a new format is one class + one call —
`handlers()->register()` accepts an FQCN or an instance and delegates to the
container-bound `Vaulter\Handlers\FileHandlerRegistry` (core-owned since P0.4/D10 —
the single source of truth for mime → extension → capability → priority resolution,
always resolvable even without `vaulter-documents` installed). No migration, no core change,
no package required.

```php
Vaulter::handlers()->register(\App\Vaulter\CadHandler::class);
```

You can also list handler FQCNs in `config('vaulter.handlers')` or bind them to the
tagged container binding `'vaulter.file_handlers'`. See
[Recipes → Custom handler](/guide/recipes).

## Exporters

### `registerExporter(DocumentExporter $exporter): void`

Register a custom export format. The exporter is keyed by its own `format()`.

### `exporter(string $format): DocumentExporter`

Resolve an exporter by format key. Looks first at runtime-registered exporters, then
at `config('vaulter-documents.exporters')` (resolving the FQCN through the container).
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
