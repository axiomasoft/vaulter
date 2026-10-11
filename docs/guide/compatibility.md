---
title: API compatibility
description: What the Vaulter 1.0 compatibility promise covers — operations, snapshots, enums, exceptions, events, config keys and the upgrade path — and what it leaves out (models, Actions, tables).
outline: deep
---

# API compatibility

From 1.0 Vaulter follows [Semantic Versioning](https://semver.org). This page says what the promise
covers. Before 1.0 nothing is frozen: renames and removals are made outright and each has an entry in
[`UPGRADE.md`](https://github.com/axiomasoft/vaulter/blob/main/UPGRADE.md) (see
[ADR-0020](/adr/0020-authorized-public-api) and [ADR-0026](/adr/0026-public-api-snapshots)).

## What is the public contract

| Surface | Promise |
| --- | --- |
| Operations and the handle | The interfaces in `Vaulter\Contracts\**` (`DriveHandle`, `NodeOperations`, `FolderOperations`, `FileOperations`, `TrashOperations`, `AccessOperations`, `ShareOperations`, `ActivityOperations`), the `Vaulter` facade, and the documents operations. Method names, **parameter names** (callers use named arguments), parameter and return types. |
| Snapshots and pages | `NodeSnapshot`, `FileRevisionSnapshot`, `GrantSnapshot`, `ShareLinkSnapshot`, `ActivityEntrySnapshot`, `DriveSnapshot`, `QuotaSnapshot`, `MintedShareLink` and `Page<T>`: their properties, for **reading**. Constructors are `@internal`: Vaulter builds snapshots, a host never does. |
| Enums | Public enums are marked `@closed` (the case set is fixed: a new case is a MAJOR change) or `@extensible` (a client must handle an unknown value). Treat an unknown `NodeKind` or document type as "download only". |
| Exceptions | The classes and their machine codes (`node_not_found`, `quota_exceeded`, …) listed in [Errors](/guide/errors). A code never changes; a new code is MINOR. |
| Events | The Laravel event classes and their public fields. |
| Configuration | Keys of `config/vaulter.php` and the other packages. A deprecated key keeps working through the normalizer until the next MAJOR release. |
| Upgrade path | Upgrade migrations run in order from any 1.x schema; after 1.0 migrations are additive (expand, migrate, contract). |
| REST | The routes and JSON of the API version in the URL. v1 is frozen when it ships (see [REST reference](/reference/rest-api)). |

A change is **MAJOR** when it removes or renames something above, narrows a parameter type, widens a
return type a caller may rely on, changes a machine code, or removes an enum case. Adding an optional
parameter, a method to a result type, a new exception code, a new enum case of an `@extensible` enum, a
config key or an event is **MINOR**.

## Packages and versions

Every PHP package is released with one version and one tag, and each split repository
(`axioma-studio/vaulter-core`, `-media`, `-drive`, `-documents`, `-filament`, plus the
`axioma-studio/vaulter` metapackage) receives the same tag. Packages require each other as
`self.version`, so mixing versions is not possible. `axioma-studio/vaulter-azgard` is released
only once AzGuard itself has a tagged version; until then it is left out of the release.
`@vaulter/vue` has its own semver line and declares the REST API line it speaks in `package.json`
(`"vaulter": { "api": "1" }`). The release set is listed in `release/packages.json`.

## What is not

- **Eloquent models** (`Vaulter\Models\**`), their columns, relations and scopes. They are `@internal`:
  the operations never return one, and a model written outside an operation is refused (see below).
  `DriveHandle::model()` exists for Vaulter's own packages and tests.
- **Actions, Queries, repositories** and everything else under `src/` that is not listed above.
- **Tables and columns.** The schema is stable (only additive migrations after 1.0), but it is reached
  through the PHP API; see [Database compatibility](/guide/database-compatibility).
- Any class or method with `@internal` in its docblock, and anything in a `Testing` namespace.

## The write guard

Vaulter models refuse `save()`, `delete()` and mass `update`, `delete`, `insert`, `upsert` and
`increment` outside a Vaulter operation and throw `WriteOutsideOperationException`
(`write_outside_operation`, see [Errors](/guide/errors#write-outside-operation)). It is a second line of
defence behind the invariants in the Actions, not a sandbox: `DB::table()` and raw SQL are not
intercepted. Set `vaulter.database.write_guard` to `false` (`VAULTER_DB_WRITE_GUARD=false`) only if a
migration of your own code needs more time.

## Writing code that survives upgrades

```php
$drive = Vaulter::drive($project)->actingAs($user);

$report = $drive->files()->upload($source);                    // NodeSnapshot
$page = $drive->nodes()->children($report->parentId);          // Page<NodeSnapshot>
$drive->nodes()->rename($report->id, 'Q3.pdf', expectedSequence: $report->sequence);

foreach ($page->items as $node) {
    // read $node->id, ->name, ->kind … never new up or mutate a snapshot
}
$next = $drive->nodes()->children($report->parentId, new ListOptions(cursor: $page->nextCursor));
```

- Pass `$node->id` (a string) or the snapshot; compare ids as strings.
- Keep the cursor opaque: hand `nextCursor` back unchanged.
- Handle unknown enum values and a `null` `document` head of a node whose type is unavailable.
