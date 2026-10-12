---
title: What is Vaulter?
description: Vaulter is an embeddable Laravel drive — Storage Panels, a unified node tree, content-addressed blobs, pluggable authorization and realtime collaboration, wired into your app's own roles.
outline: deep
---

# What is Vaulter?

**Vaulter** is an embeddable document- and file-management system for Laravel. You drop it
into an existing application — it brings a Google-Drive-style file manager, a TipTap document
editor with version history, and realtime collaboration — and it delegates every
authorization decision back to your app's own role system.

Vaulter ships as a set of packages — install the ones your app needs:

- **`axioma-studio/vaulter-core`** — the shared kernel (authorization, CAS contracts, sharing,
  panels, Artisan commands). Required by every other backend package.
- **`axioma-studio/vaulter-drive`** — folders, files, uploads, `Vaulter::drive()` / `DriveHandle`.
  Pulls in `axioma-studio/vaulter-media` (the CAS engine) automatically.
- **`axioma-studio/vaulter-documents`** — TipTap/Yjs document nodes, versions, comments, export.
- **`@vaulter/vue`** — the Vue 3 + TypeScript components, composables and API client.

A Filament 5 admin plugin (`axioma-studio/vaulter-filament`) is available separately. See
[Installation](/getting-started/installation) for the exact `composer require` per feature set.

## Is it for me?

Vaulter is a good fit when you want to **embed** a drive into your own product rather than
bolt on a standalone SaaS:

- You already have users, tenants and roles — Vaulter authorizes through *your* system, it
  never ships an auth/login of its own.
- You want files **and** rich documents in one tree (not two separate features).
- You need per-entity isolation: a drive per project, per organization, per user — without
  writing a migration for each.
- You care about storage cost and integrity: content-addressed deduplication and a safe
  garbage collector are built in.

If you only need to attach a single PDF to a model, Vaulter is more than you need — reach for
a media-library package instead. (Why Vaulter has its *own* content store rather than reusing
`spatie/laravel-medialibrary` is recorded in [ADR-0008](/adr/0008-media-library-decision).)

## The core concepts

These are the nouns you will meet throughout the docs and the code.

| Concept | What it is |
|---|---|
| **Drive Profile** | A named drive policy for a host entity: feature flags and grants over one shared schema. |
| **Storage** | The concrete drive row (`v_storages`) for one host entity within a panel. `Vaulter::drive($entity)` get-or-creates it and provisions its root folder. |
| **Node** | An entry in the unified tree (`v_nodes`): a **folder**, **file**, **document** or **shortcut**. One tree per storage, rooted at the storage's root folder. |
| **Blob** | The content-addressed bytes behind a file (`v_blobs`). Keyed by `sha256`, deduplicated within a configurable scope, and reclaimed by a two-phase garbage collector. |
| **ACL / AccessResolver** | The Vaulter-owned effective-permission engine. It folds per-node grants (`v_node_grants`) with a swappable **host-role driver** to answer "may this actor do this?". |
| **Document handler** | The format-extensibility seam. A registry routes a node to the right handler (TipTap rich text, preview, download-only…) by mime → extension → capability. Adding a new format is one class + one config line. |

### Storage Panel → Storage → Node → Blob

The data model nests cleanly:

```
Host entity  (App\Models\Project, Organization, User …)
   └── Storage Panel        config envelope, chosen by name
        └── Storage          v_storages — one drive for this entity
             └── Node tree   v_nodes — folders / files / documents / shortcuts
                  └── File   v_files → File version → Blob (v_blobs, sha256 bytes)
```

A host entity can own several storages — one per panel. Within a storage, everything lives in
**one** node tree, so a folder can hold both binary files and editable documents.

### Authorization: two lanes

Vaulter splits authorization into a part it **owns** and a part you **plug in**:

1. The **`AccessResolver`** is Vaulter-owned and *not* swappable. It runs the canonical
   algorithm over `v_node_grants` (per-node ACL grants, with subtree inheritance and a
   deny-veto).
2. The **host-role driver** *is* swappable. It is the lane that answers "what role does this
   actor have in this workspace?". Ship with the standalone `database` driver, or swap to
   `gate`, `spatie`, `azgard`, or your own.

This is why Vaulter never needs its own login: roles come from your app through the driver,
and the resolver combines them with node grants. The design is recorded in
[ADR-0002](/adr/0002-authorization-delegation) and [ADR-0007](/adr/0007-pluggable-authorization).

## The `Vaulter` facade at a glance

Everything host-facing goes through the `Vaulter` facade (`Vaulter\Facades\Vaulter`,
auto-aliased as `Vaulter`). The methods you will use most:

<!-- snippet: host -->

```php
use Vaulter\Documents\Facades\Documents;
use Vaulter\Facades\Vaulter;

// Open a Drive handle for a host entity (get-or-creates its drive + root folder).
$drive = Vaulter::drive($project);                  // DriveHandle
$drive = Vaulter::drive($project, 'project_drive'); // …within a named profile
$drive = Vaulter::findDrive($project);              // null instead of creating one

// Resolve profiles.
Vaulter::profiles()->get('project_drive');          // Profile
Vaulter::profiles();                                // ProfileRegistry

// Extend the package at runtime.
Vaulter::handlers()->register(MyPdfHandler::class);   // a new file format
Documents::exporters()->register(PdfExporter::class); // a new export format

// The permission driver lane.
Vaulter::permissions();                             // PermissionDriverManager
```

The full surface is in the [API reference](/reference/php-api).

## What Vaulter does not do

- It does **not** implement authentication or login — it integrates through the host-role
  driver and your existing guard.
- It does **not** invent its own user/role tables — those stay in your application.

## Next steps

- [**Requirements**](/getting-started/installation) — PHP, Laravel and Node versions.
- [**Installation**](/getting-started/installation) — install the package and the Vue UI.
- [**Quick Start**](/getting-started/quick-start) — a minimal working drive in ≤60 seconds.
- [**Configuration**](/guides/configuration) — every `config/vaulter.php` section.
- [**Core Concepts**](/concepts/architecture) — the architecture in depth.
