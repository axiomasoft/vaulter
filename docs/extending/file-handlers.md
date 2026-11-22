---
title: Document Handlers
description: The format-extensibility registry — capabilities, mime/extension routing, and adding a new file format with a single class and no migration.
outline: deep
---

# Document Handlers

Document handlers are Vaulter's **format-extensibility** layer. A handler claims a set of
mime types and/or extensions, declares what it can do with a file (edit / preview / download
/ thumbnail / collaborate), and returns the front-end mount descriptor — which Vue component
the client opens the node in. The `Vaulter\Drive\Handlers\FileHandlerRegistry` resolves the best
handler for a file, and the client routes the node to that surface **without hard-coding mime
logic**. The registry and its baseline handlers (`PreviewHandler`/`DownloadOnlyHandler`) are
drive-owned — a drive-only host with no other package installed still resolves preview/download
for every file. Handlers serve **files** only: a document node is presented by its
[document type](./document-types.md), not by a handler.

The headline property: **adding a new format is a one-class + one-register operation — no
migration, no core change, not gated by any sealed enum.** The registry's string keys
(mime / extension) are the source of truth; no enum gates them.

::: tip Where this fits
[Document nodes](/guides/documents) carry the *content*; handlers decide the *surface*. A
handler is also what a binary file node (image, PDF, archive) routes through — handlers cover
every leaf node, not just `kind=document`. For rendering content out to a file, see
[Export](/guides/export).
:::

## The contract

A handler implements `Vaulter\Drive\Contracts\Handlers\FileHandler`:

<!-- snippet: signature Vaulter\Drive\Contracts\Handlers\FileHandler -->

```php
namespace Vaulter\Drive\Contracts\Handlers;

use Vaulter\Drive\ValueObjects\Handlers\FileDescriptor;
use Vaulter\Drive\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\Drive\ValueObjects\Handlers\ViewerDescriptor;

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

`Vaulter\Drive\ValueObjects\Handlers\ContentCapabilities` is what a handler advertises per file.
`downloadable` defaults to `true` (anything can be downloaded as bytes); the richer flags are
opt-in:

<!-- snippet: host -->

```php
new ContentCapabilities(
    editable:      false,
    previewable:   false,
    downloadable:  true,   // default
    thumbnailable: false,
    collaborative: false,
);
```

These map one-to-one to the `Vaulter\Drive\Enums\Handlers\Capability` cases the registry filters
by: `Edit`, `Preview`, `Download`, `Thumbnail`, `Collaborate`.

::: warning `previewable` ≠ safe-to-inline
`previewable` means a handler *can* render a preview — it does **not** assert the bytes are
safe to inline unsandboxed. HTML/SVG are previewable but are an XSS vector unless sandboxed;
the built-in `PreviewHandler` deliberately does **not** claim `text/html` or `image/svg+xml`,
and only marks pdf/image `inline_safe`. The client decides the sandboxing; the capability only
advertises that a preview exists.
:::

### The front-end mount descriptor

`Vaulter\Drive\ValueObjects\Handlers\ViewerDescriptor` tells the client which Vue component to mount, the
stable viewer key and any extra serialisable props:

<!-- snippet: host -->

```php
new ViewerDescriptor(
    key: 'vaulter/pdf-preview',          // the viewer the client mounts
    props: ['page' => 1],                // arbitrary serialisable props
);
```

This is embedded in the node payload (as a `NodePresentation` — `id` + `document_type` +
`type_available` + `capabilities` + `ui`; for a file `document_type` is `null`)
so the Vue client routes the node without re-running mime logic. The
[`VaulterDocumentNode`](#mounting-the-resolved-handler-in-vue) component maps the descriptor's
`ui.key` to a concrete editor.

### The file descriptor

`Vaulter\Drive\ValueObjects\Handlers\FileDescriptor` is the immutable description the registry
routes on — claimed mime, the server-**sniffed** detected mime, extension and size — without
touching the bytes or the database. Routing keys on `canonicalMime()`.

::: danger Security: routing never trusts the client mime
`canonicalMime()` prefers the server-sniffed `detectedMime` and only falls back to the
client-supplied `claimedMime` when no sniff is available. A spoofed `Content-Type` can never
force an editable/preview handler onto hostile bytes.
:::

## Files are not documents

An uploaded `.docx`, `.doc`, `.md` or `.xlsx` file is a **file**: it resolves to
`PreviewHandler` or `DownloadOnlyHandler` and is never declared editable or collaborative,
until an import "file → document" exists. Editable content lives in document nodes, whose
presentation comes from their [document type](./document-types.md). The base package provides
`vaulter/rich-text` and `vaulter/markdown`. The Sheets add-on provides `vaulter/spreadsheet`,
and hosts can register their own types.

## Resolution algorithm

`Vaulter\Drive\Handlers\FileHandlerRegistry::resolve(FileDescriptor $file, Capability $cap)`
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

The default set is registered from config (`config('vaulter-drive.handlers')`):

<!-- snippet: config -->

```php
// config/vaulter-drive.php
'handlers' => [
    Vaulter\Drive\Handlers\Builtin\PreviewHandler::class,
    Vaulter\Drive\Handlers\Builtin\DownloadOnlyHandler::class,
],
```

| Handler | `key` | Claims | Capabilities | Viewer key | Priority |
|---|---|---|---|---|---|
| `PreviewHandler` | `vaulter/preview` | `application/pdf`, `image/*`; ext `pdf`, `png`, `jpg`, `jpeg`, `gif`, `webp`, `avif`, `bmp` | preview · thumbnail | `vaulter/pdf-preview` | 80 |
| `DownloadOnlyHandler` | `vaulter/download` | `*` (catch-all) | download | `vaulter/download` | −1000 |

A document node carries the Vaulter document mime (`application/vnd.vaulter.document+json`)
but is never routed through this registry: core's `NodePresenter` hands every `document` node
to the kind presenter the documents module contributes to the drive's `KindPresenter` slot (the key is the node kind, `document`).

## Resolving a node's handler

The presentation is part of every node, so the client knows which surface to mount:

::: code-group

<!-- snippet: shell -->

```bash [HTTP]
# GET {prefix}/v1/nodes/{node}  → data.presentation
curl https://app.test/api/vaulter/v1/nodes/77
# a PDF file:
# → { "data": { …, "presentation": { "handler": "vaulter/preview", "document_type": null, "type_available": true,
#               "capabilities": { "editable": false, "previewable": true,
#                                 "downloadable": true, "thumbnailable": true,
#                                 "collaborative": false },
#               "viewer": { "key": "vaulter/pdf-preview", "props": {} } } } }
# a rich-text document (handler is null: a document is routed by its type):
# → { "data": { …, "presentation": { "handler": null, "document_type": "vaulter/rich-text", "type_available": true,
#               "capabilities": { "editable": true, "previewable": true,
#                                 "downloadable": false, "thumbnailable": false,
#                                 "collaborative": true },
#               "viewer": { "key": "vaulter/tiptap", "props": { "preset": "rich-text" } } } } }
```

<!-- snippet: host -->

```php [PHP — the resolver]
use Vaulter\Drive\Contracts\Handlers\NodePresenter;

$descriptor = app(NodePresenter::class)->present($node);
// NodePresentation: ->id, ->documentType, ->typeAvailable, ->capabilities, ->ui (->toArray() to serialise)
```

<!-- snippet: host -->

```ts [Vue — composable]
import { useVaulterClient } from '@vaulter/vue'

const node = await useVaulterClient().nodes.get(nodeId)
// node.presentation → { handler, document_type, type_available, capabilities, viewer }
```

:::

For a file, `NodePresenter` builds the `FileDescriptor` from the node (server-trusted mime) and asks
the registry at the **broadest** capability (`Download` — every handler satisfies it), so the
**most specific** matching handler wins; the descriptor then reports that handler's full
capability set for the client to branch on.

## Adding a new format — a worked example

This is the headline. Here is a complete handler for **CSV** files — preview + download, no
migration, no core change.

### 1. Implement the handler

<!-- snippet: host -->

```php
<?php

declare(strict_types=1);

namespace App\Vaulter\Handlers;

use Vaulter\Drive\Contracts\Handlers\FileHandler;
use Vaulter\Drive\ValueObjects\Handlers\FileDescriptor;
use Vaulter\Drive\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\Drive\ValueObjects\Handlers\ViewerDescriptor;

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

<!-- snippet: config -->

```php [config — cache-safe FQCN list]
// config/vaulter-drive.php — append to the handlers list (FQCN only, config-cache safe)
'handlers' => [
    Vaulter\Drive\Handlers\Builtin\PreviewHandler::class,
    App\Vaulter\Handlers\CsvHandler::class,        // [!code ++]
    Vaulter\Drive\Handlers\Builtin\DownloadOnlyHandler::class,
],
```

<!-- snippet: host -->

```php [runtime — Vaulter::handlers()->register]
// In a service provider's boot() — FQCN or an instance
use Vaulter\Drive\Facades\Vaulter;
use App\Vaulter\Handlers\CsvHandler;

Vaulter::handlers()->register(CsvHandler::class);
```

<!-- snippet: host -->

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
- **tagged binding** is the channel core uses for its own baseline handlers; use it for a
  handler a package contributes.
:::

### 3. It just works

Now any node whose sniffed mime is `text/csv` (or whose name ends `.csv`) resolves to your
handler. The node's `presentation` has `handler: "csv"` with your capabilities and
`viewer.key: "acme/csv-preview"`, and the client mounts your component. No migration ran, no
core file changed.

::: details Listing all registered handlers
`Vaulter::handlers()->all()` returns every handler in the merged set, priority desc (the
introspection surface a host/admin lists formats from). The `DownloadOnlyHandler` null-object
is included as the terminal entry.
:::

## Mounting the resolved handler in Vue

The node's `presentation.viewer.key` names the **viewer** the client mounts. `VaulterViewer`
(`@vaulter/vue`) looks the key up in the app's viewer registry and mounts the component with
the `node`, a `readonly` flag and the handler's `viewer.props` as props. A node without a key,
with a key the app never registered, or whose document type this application cannot handle
(`type_available = false`) falls back to the `vaulter/unsupported` viewer — the item is still
shown, named and downloadable.

The built-in keys (`vaulter/tiptap`, `vaulter/pdf-preview`,
`vaulter/image-preview`, `vaulter/download`, `vaulter/unsupported`) are installed by the plugin.
Add your own, or replace a built-in one, through the plugin's `viewers` option — an explicit
ES-module import, not an ambient global:

<!-- snippet: host -->

```ts
// main.ts — once, before mounting the app
import { createApp } from 'vue'
import { VaulterPlugin } from '@vaulter/vue'
import App from './App.vue'
import AppTipTapEditor from './AppTipTapEditor.vue'   // a TipTap editor the host supplies

createApp(App)
  .use(VaulterPlugin, {
    baseUrl: '/api/vaulter',
    viewers: {
      'vaulter/tiptap': AppTipTapEditor,                        // replace a built-in key
      'acme/csv-preview': () => import('./AppCsvPreview.vue'),  // our CSV handler's viewer key
    },
  })
  .mount('#app')
```

A function entry is a loader (lazily imported); a component is mounted directly. Render a node
with the viewer the server chose:

<!-- snippet: host -->

```vue
<script setup lang="ts">
import { VaulterViewer } from '@vaulter/vue'
import type { Node } from '@vaulter/vue'

defineProps<{ node: Node }>()
</script>

<template>
  <VaulterViewer :node="node" />
</template>
```

`useViewerRegistry()` returns the registry for runtime changes (`register`, `unregister`,
`has`, `keys`). Registrations belong to one app: two apps on a page never share viewers.

The `vaulter/tiptap` viewer is a headless editing shell
(`VaulterDocumentNode`): it loads the content, autosaves and, when the
presentation is **collaborative**, a matching protocol adapter is registered, request a neutral collaboration session. The editor
engine itself is yours — replace the key as above, or fill the shell's default slot. See
[Document nodes](/guides/documents#working-state-and-checkpoints) for the autosave path.

## Generator

<!-- snippet: shell -->

```bash
php artisan vaulter:make:file-handler Csv
```

Writes a handler in `app/Vaulter/FileHandlers/Csv.php` and a Pest test in `tests/Unit/Vaulter/` that runs `FileHandlerContractTests`. It prints the line to `vaulter-drive.handlers`
and never edits your config. An existing file is kept unless you pass `--force`.

## Reference

- Contract — `Vaulter\Drive\Contracts\Handlers\FileHandler`
- Registry — `Vaulter\Drive\Handlers\FileHandlerRegistry` (`resolve`, `register`, `all`) — drive-owned
- Resolver — `Vaulter\Drive\Handlers\NodePresenter` (per-node descriptor) — drive-owned
- Manager seam — `Vaulter\Drive\Vaulter::handlers()->register()` / `::handlers()`
- VOs — `ContentCapabilities`, `ViewerDescriptor`, `NodePresentation`, `FileDescriptor`
- Enum — `Vaulter\Drive\Enums\Handlers\Capability`
- Built-ins — `PreviewHandler`, `DownloadOnlyHandler` (documents: see [Document Types](./document-types.md))
- Frontend viewers (`@vaulter/vue`) — the `viewers` plugin option, `VaulterViewer`, `createViewerRegistry`, `useViewerRegistry`
