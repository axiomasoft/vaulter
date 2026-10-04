---
title: Document Handlers
description: The format-extensibility registry — capabilities, mime/extension routing, and adding a new file format with a single class and no migration.
outline: deep
---

# Document Handlers

Document handlers are Vaulter's **format-extensibility** layer. A handler claims a set of
mime types and/or extensions, declares what it can do with a file (edit / preview / download
/ thumbnail / collaborate), and returns the front-end mount descriptor — which Vue component
the client opens the node in. The `Vaulter\Handlers\FileHandlerRegistry` resolves the best
handler for a file, and the client routes the node to that surface **without hard-coding mime
logic**. The registry and its baseline handlers (`PreviewHandler`/`DownloadOnlyHandler`) are
core-owned — a drive-only host with no other package installed still resolves preview/download
for every file; a package like `vaulter-documents` only adds richer formats (TipTap, Univer, …).

The headline property: **adding a new format is a one-class + one-register operation — no
migration, no core change, not gated by any sealed enum.** The registry's string keys
(mime / extension) are the source of truth; the built-in `NodeKind` / `DocumentType` enums are
only convenience, never a gate.

::: tip Where this fits
[Document nodes](./documents.md) carry the *content*; handlers decide the *surface*. A
handler is also what a binary file node (image, PDF, archive) routes through — handlers cover
every leaf node, not just `kind=document`. For rendering content out to a file, see
[Export](./export.md).
:::

## The contract

A handler implements `Vaulter\Contracts\Handlers\FileHandler`:

```php
namespace Vaulter\Contracts\Handlers;

use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\ValueObjects\Handlers\ViewerDescriptor;

interface FileHandler
{
    /** A stable, unique id surfaced to the client (e.g. 'tiptap', 'preview', 'download'). */
    public function key(): string;

    /** Claimed mime types — exact ("application/pdf"), wildcard ("image/*") or "*". @return list<string> */
    public function mimeTypes(): array;

    /** Claimed lower-case extensions, without the dot (the mime fallback). @return list<string> */
    public function extensions(): array;

    /** Resolution priority — higher wins among handlers matching the same file + capability. */
    public function priority(): int;

    /** What this handler can do with THIS file (computed per file). */
    public function capabilities(FileDescriptor $file): ContentCapabilities;

    /** The front-end mount descriptor — viewer key + props the client mounts. */
    public function viewer(FileDescriptor $file): ViewerDescriptor;
}
```

### Capabilities

`Vaulter\ValueObjects\Handlers\ContentCapabilities` is what a handler advertises per file.
`downloadable` defaults to `true` (anything can be downloaded as bytes); the richer flags are
opt-in:

```php
new ContentCapabilities(
    editable:      false,
    previewable:   false,
    downloadable:  true,   // default
    thumbnailable: false,
    collaborative: false,
);
```

These map one-to-one to the `Vaulter\Enums\Handlers\Capability` cases the registry filters
by: `Edit`, `Preview`, `Download`, `Thumbnail`, `Collaborate`.

::: warning `previewable` ≠ safe-to-inline
`previewable` means a handler *can* render a preview — it does **not** assert the bytes are
safe to inline unsandboxed. HTML/SVG are previewable but are an XSS vector unless sandboxed;
the built-in `PreviewHandler` deliberately does **not** claim `text/html` or `image/svg+xml`,
and only marks pdf/image `inline_safe`. The client decides the sandboxing; the capability only
advertises that a preview exists.
:::

### The front-end mount descriptor

`Vaulter\ValueObjects\Handlers\ViewerDescriptor` tells the client which Vue component to mount, the
stable viewer key and any extra serialisable props:

```php
new ViewerDescriptor(
    key: 'vaulter/tiptap',     // the Vue component the client mounts
    props:     ['format' => 'richtext'], // arbitrary serialisable props
);
```

This is embedded in the node payload (as a `NodePresentation` — `id` + `capabilities` + `ui`)
so the Vue client routes the node without re-running mime logic. The
[`VaulterDocumentNode`](#mounting-the-resolved-handler-in-vue) component maps the descriptor's
`ui.key` to a concrete editor.

### The file descriptor

`Vaulter\ValueObjects\Handlers\FileDescriptor` is the immutable description the registry
routes on — claimed mime, the server-**sniffed** detected mime, extension and size — without
touching the bytes or the database. Routing keys on `canonicalMime()`.

::: danger Security: routing never trusts the client mime
`canonicalMime()` prefers the server-sniffed `detectedMime` and only falls back to the
client-supplied `claimedMime` when no sniff is available. A spoofed `Content-Type` can never
force an editable/preview handler onto hostile bytes.
:::

## DocumentType

`Vaulter\Enums\Documents\DocumentType` is the built-in **sugar** over the registry — a small
set of rich-text formats with a label, icon and the TipTap extension list a `TipTapHandler`
surfaces as a `viewer.props.extensions` prop. It is **not** a gate: the registry routes by
mime/extension strings, and a host adds new formats with a [handler](#adding-a-new-format-a-worked-example),
not by extending this enum.

| Case | value | TipTap extensions |
|---|---|---|
| `RichText` | `richtext` | StarterKit · Image · Table · Link |
| `Table` | `table` | StarterKit · Table |
| `Markdown` | `markdown` | StarterKit · CodeBlock |
| `Template` | `template` | StarterKit · Placeholder |

These four `value`s are what the `format` field accepts when [creating a document
node](./documents.md#creating-a-document-node) (default `richtext`).

## Resolution algorithm

`Vaulter\Handlers\FileHandlerRegistry::resolve(FileDescriptor $file, Capability $cap)`
returns the best handler and **never returns null**. The order is:

1. **Mime-first** — collect handlers claiming the canonical (sniffed) mime: an exact match
   (`application/pdf`) beats a wildcard family (`image/*`). The bare `*` catch-all is excluded
   here so it never short-circuits the next step.
2. **Extension-fallback** — only when no specific mime handler matched, collect handlers
   claiming the file's extension.
3. **Capability-filter** — keep only handlers whose `capabilities(file)` satisfy the requested
   `Capability`.
4. **Priority-sort** — highest `priority()` wins.
5. **Null-object fallback** — if nothing capable matched, the `DownloadOnlyHandler`
   (priority `-1000`, claims `*`, download-only) is the guaranteed terminal resolution.

Resolutions are memoised by `(canonicalMime, extension, capability)`, so a folder of like
files sorts the handler set once.

## Built-in handlers

The default set is registered from config (`config('vaulter.handlers')`):

```php
// config/vaulter.php
'handlers' => [
    Vaulter\Documents\Engines\Handlers\TipTapHandler::class,
    Vaulter\Handlers\Builtin\PreviewHandler::class,
    Vaulter\Handlers\Builtin\DownloadOnlyHandler::class,
],
```

| Handler | `key` | Claims | Capabilities | Viewer key | Priority |
|---|---|---|---|---|---|
| `TipTapHandler` | `vaulter/tiptap` | `application/vnd.vaulter.document+json`, `text/markdown`, docx/msword; ext `md`, `markdown`, `doc`, `docx`, `vdoc` | edit · preview · **collaborate** | `vaulter/tiptap` | 100 |
| `PreviewHandler` | `vaulter/preview` | `application/pdf`, `image/*`; ext `pdf`, `png`, `jpg`, `jpeg`, `gif`, `webp`, `avif`, `bmp` | preview · thumbnail | `vaulter/pdf-preview` | 80 |
| `DownloadOnlyHandler` | `vaulter/download` | `*` (catch-all) | download | `vaulter/download` | −1000 |

A document node created via `CreateDocumentNodeAction` is stamped with the Vaulter document
mime (`application/vnd.vaulter.document+json`), so it always routes to `TipTapHandler`
regardless of any extension in its name.

::: details UniverHandler — the feature-flagged spreadsheet handler
`Vaulter\Documents\Engines\Handlers\UniverHandler` (`key: vaulter/univer`, claims xlsx/xls, priority 90,
editable + previewable but **single-user** — Univer collaboration is a separate OT engine, not
the Yjs lane) is **not** in the default config list. The service provider adds it to the tagged
`vaulter.file_handlers` set **only** when the `spreadsheets` feature is on:

```php
// config/vaulter.php
'features' => ['spreadsheets' => true],
```

With the flag off, xlsx falls through to `DownloadOnlyHandler`. It is the proof that the
one-class + register extensibility works for a non-rich-text editor.

The create path is **handler-aware**: pass a `mime` to `POST {prefix}/storages/{storage}/documents`
and the node is born on the matching handler — its content is stored the handler's way
(a validated TipTap doc for a rich-text handler, an opaque snapshot otherwise) and the node is
stamped with the handler's canonical mime so a later read re-resolves the same editor. With no
`mime`, the node is a rich-text TipTap document (the default). A `mime` that resolves to a
non-editable handler (e.g. xlsx while `spreadsheets` is off) is rejected `422`.

```ts
// @vaulter/vue — create a spreadsheet node natively (defaults to the xlsx mime)
await client.documentNodes.createWorkbookNode(storageId, { name: 'Budget.xlsx', workbook })
```
:::

## Resolving a node's handler

The handler descriptor is exposed per node, so the client knows which surface to mount:

::: code-group

```bash [HTTP]
# GET {prefix}/nodes/{node}/handler
curl https://app.test/api/vaulter/nodes/77/handler
# → { "data": { "id": "vaulter/tiptap",
#               "capabilities": { "editable": true, "previewable": true,
#                                 "downloadable": true, "thumbnailable": false,
#                                 "collaborative": true },
#               "ui": { "key": "vaulter/tiptap",
#                       "props": { "format": "richtext", "extensions": [...] } } } }
```

```php [PHP — the resolver]
use Vaulter\Contracts\Handlers\NodePresenter;

$descriptor = app(NodePresenter::class)->present($node);
// NodePresentation: ->id, ->capabilities, ->ui  (call ->toArray() to serialise)
```

```ts [Vue — composable]
import { useDocumentNode } from '@vaulter/vue'

const { handler, loadHandler } = useDocumentNode(nodeId)
await loadHandler()
// handler.value → { id, capabilities, ui }
```

:::

`NodePresenter` builds the `FileDescriptor` from the node (server-trusted mime) and asks
the registry at the **broadest** capability (`Download` — every handler satisfies it), so the
**most specific** matching handler wins; the descriptor then reports that handler's full
capability set for the client to branch on.

## Adding a new format — a worked example

This is the headline. Here is a complete handler for **CSV** files — preview + download, no
migration, no core change.

### 1. Implement the handler

```php
<?php

declare(strict_types=1);

namespace App\Vaulter\Handlers;

use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\ValueObjects\Handlers\ViewerDescriptor;

final class CsvHandler implements FileHandler
{
    public function key(): string
    {
        return 'acme/csv';
    }

    /** @return list<string> */
    public function mimeTypes(): array
    {
        return ['text/csv', 'application/csv'];
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return ['csv'];
    }

    public function priority(): int
    {
        // Above DownloadOnly (-1000), below TipTap (100) — a clean win for CSV files.
        return 70;
    }

    public function capabilities(FileDescriptor $file): ContentCapabilities
    {
        return new ContentCapabilities(
            editable:      false,
            previewable:   true,   // render a read-only table preview
            downloadable:  true,
            thumbnailable: false,
            collaborative: false,
        );
    }

    public function viewer(FileDescriptor $file): ViewerDescriptor
    {
        return new ViewerDescriptor(
            key: 'acme/csv-preview',   // your Vue component
            props:     ['delimiter' => ','],
        );
    }
}
```

That is the **entire** backend change. There is no migration: a CSV node is just a
`kind=file` node already in `v_nodes`; the handler only adds routing + capabilities. The
registry is the source of truth — no enum was touched.

### 2. Register it

Pick whichever fits your app. All three merge into the same registry (a class appears at most
once, so registering in two places is safe):

::: code-group

```php [config — cache-safe FQCN list]
// config/vaulter.php — append to the handlers list (FQCN only, config-cache safe)
'handlers' => [
    Vaulter\Documents\Engines\Handlers\TipTapHandler::class,
    Vaulter\Handlers\Builtin\PreviewHandler::class,
    App\Vaulter\Handlers\CsvHandler::class,        // [!code ++]
    Vaulter\Handlers\Builtin\DownloadOnlyHandler::class,
],
```

```php [runtime — Vaulter::handlers()->register]
// In a service provider's boot() — FQCN or an instance
use Vaulter\Facades\Vaulter;
use App\Vaulter\Handlers\CsvHandler;

Vaulter::handlers()->register(CsvHandler::class);
```

```php [tagged container binding]
// In a service provider's register() — the conditional/host extension channel
$this->app->tag([App\Vaulter\Handlers\CsvHandler::class], 'vaulter.file_handlers');
```

:::

::: tip Which channel?
- **config** is cache-safe (FQCN only, survives `config:cache`) — the default for a static
  handler.
- **`Vaulter::handlers()->register()`** is the runtime seam — register conditionally at boot;
  re-priming the merged set + clearing the cache keeps a later resolve correct.
- **tagged binding** is what the package itself uses for the conditional `UniverHandler`.
:::

### 3. It just works

Now any node whose sniffed mime is `text/csv` (or whose name ends `.csv`) resolves to your
handler. `GET /nodes/{node}/handler` returns `id: "csv"` with your capabilities and
`ui.key: "acme/csv-preview"`, and the client mounts your component. No migration ran, no
core file changed.

::: details Listing all registered handlers
`Vaulter::handlers()->all()` returns every handler in the merged set, priority desc (the
introspection surface a host/admin lists formats from). The `DownloadOnlyHandler` null-object
is included as the terminal entry.
:::

## Mounting the resolved handler in Vue

`VaulterDocumentNode` is the bridge from a handler descriptor to a concrete editor/preview. It
loads the handler + head content, then mounts the component the descriptor's `ui.key`
maps to. Two mapping sources exist, checked in order:

1. the `componentMap` prop — scoped to one `VaulterDocumentNode` mount point;
2. the global `registerEngineComponent` registry (`@vaulter/vue`) — an explicit function a host
   calls once at startup, for engines shared across every mount point (VLT-CANON P1.2).

`componentMap` always wins when both map the same key. Neither is required — use whichever fits
how many mount points your app has.

```vue
<script setup lang="ts">
import { VaulterDocumentNode } from '@vaulter/vue'
import AppTipTapEditor from './AppTipTapEditor.vue'   // a TipTap editor the host supplies
import AppCsvPreview from './AppCsvPreview.vue'

// Map a handler's `ui.key` (or its `id`) to the component to mount.
const componentMap = {
  'vaulter/tiptap': AppTipTapEditor,   // the TipTap handler's ui.key
  'acme/csv-preview': AppCsvPreview,                    // our CSV handler's ui.key
}
</script>

<template>
  <VaulterDocumentNode :node-id="nodeId" :component-map="componentMap">
    <!-- Fallback for any unmapped handler: a preview/download surface. -->
    <template #fallback="{ handler }">
      No editor registered for {{ handler.id }}.
    </template>
  </VaulterDocumentNode>
</template>
```

Or register once at app startup — an explicit ES-module import/call, not an ambient global (the
lesson behind Nextcloud Viewer's move away from `OCA.Viewer.registerHandler`):

```ts
// main.ts — once, before mounting the app
import { registerEngineComponent } from '@vaulter/vue'
import AppTipTapEditor from './AppTipTapEditor.vue'
import AppCsvPreview from './AppCsvPreview.vue'

registerEngineComponent('VaulterEditor', AppTipTapEditor)
registerEngineComponent('AppCsvPreview', AppCsvPreview)
```

Every `VaulterDocumentNode` on the page now resolves these without a local `componentMap`.
`unregisterEngineComponent(id)` removes an entry; `registeredEngineComponentIds()` lists what is
currently registered (mostly for diagnostics/tests). The registry lookup is **not reactive**:
register before mounting the app (or at least before mounting the `VaulterDocumentNode` that
needs the entry) — an already-mounted node does not re-resolve just because a later
`registerEngineComponent` call added its `ui.key` key.

In development, `VaulterDocumentNode` logs one `console.warn` per unmapped `ui.key`/`id`
(not repeated for the same key WITHIN ONE component instance) naming both registration
channels — the same gap Nova's string-key `Nova.booting` binding leaves silent.

If the resolved handler is **collaborative** (e.g. `TipTapHandler`) and collaboration is
enabled, `VaulterDocumentNode` opens a Yjs session and runs the editor in collaborative mode;
otherwise it uses the autosave path described in [Document nodes](./documents.md#autosave-vs-named-versions).

## Reference

- Contract — `Vaulter\Contracts\Handlers\FileHandler`
- Registry — `Vaulter\Handlers\FileHandlerRegistry` (`resolve`, `register`, `all`) — core-owned
- Resolver — `Vaulter\Handlers\NodePresenter` (per-node descriptor) — core-owned
- Manager seam — `Vaulter\VaulterManager::handlers()->register()` / `::handlers()`
- VOs — `ContentCapabilities`, `ViewerDescriptor`, `NodePresentation`, `FileDescriptor`
- Enum — `Vaulter\Enums\Handlers\Capability`
- Built-ins — `TipTapHandler`, `PreviewHandler`, `UniverHandler`, `DownloadOnlyHandler`
- Frontend registry (`@vaulter/vue`) — `registerEngineComponent`, `unregisterEngineComponent`,
  `resolveEngineComponent`, `registeredEngineComponentIds`, `clearEngineComponents`
