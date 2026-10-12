---
title: Writing a Handler/Engine
description: A from-scratch checklist for a third-party file handler — the contract, the capability-policy invariants, payload asymmetry, and where the seam stops.
outline: deep
---

# Writing a Handler/Engine

[Document Handlers](/extending/file-handlers) shows the contract and a worked CSV example. This
guide is the checklist for building your **own** handler end to end — a rich-text-class editor
(TipTap-style), a WOPI-class office viewer (iframe + token), or a plain preview/download surface
— and the invariants your handler must hold so it composes safely with every other handler
already registered.

::: tip Terminology
"Engine" and "handler" name the same seam. The contract is `FileHandler` — kind-neutral by
design (§8.1): it describes *any* file, not only editable documents. A `DownloadOnlyHandler` or
`PreviewHandler` is as much a handler as a rich-text editor; "engine" is reserved informally for
an *editable* handler (`capabilities.editable === true`).
:::

## 1. Implement the contract

Start from [Document Handlers → the worked CSV example](/extending/file-handlers#adding-a-new-format-—-a-worked-example)
if your handler is preview/download-only. The rest of this guide assumes an **editable**
handler — the richer case.

```php
<?php

declare(strict_types=1);

namespace App\Vaulter\Handlers;

use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\ValueObjects\Handlers\ViewerDescriptor;

final class MyEditorHandler implements FileHandler
{
    public const int PRIORITY = 90;

    public function key(): string
    {
        return 'acme/my-editor';
    }

    /** @return list<string> */
    public function mimeTypes(): array
    {
        return ['application/vnd.my-editor+json'];
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return ['myd'];
    }

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function capabilities(FileDescriptor $file): ContentCapabilities
    {
        return new ContentCapabilities(
            editable: true,
            previewable: true,
            collaborative: false,
        );
    }

    public function viewer(FileDescriptor $file): ViewerDescriptor
    {
        return new ViewerDescriptor(
            key: 'acme/my-editor',
        );
    }
}
```

Implementing the interface directly is always enough. `Vaulter\Handlers\Handlers\
AbstractFileHandler` is an *optional* DX base that defaults `extensions()` to `[]` for a
mime-only handler — it saves one method, nothing more. The built-in handlers do not extend it;
they stay `final` (H.5).

## 2. The capability-policy invariants (read before you ship)

`ContentCapabilities` is an **additive, open axis set** (P0.5). Three rules keep your handler
(and every handler that comes after it) safe to compose:

1. **A capability you don't declare is `false`, never inferred** (except `downloadable`, which
   defaults to `true` for a file). Content shape is not a capability: a handler describes a
   file, and structured, editable content is a [document type](./document-types.md) with its
   own codec.
2. **A future new capability flag must not break your handler.** You are not required to know
   about every flag `ContentCapabilities` carries — the constructor gives every flag a safe
   default. The client mirrors this: `ContentCapabilities` on the TypeScript side carries an
   open index signature (`[capability: string]: boolean | undefined`), so a client build that
   predates a new flag keeps compiling and ignores the key it doesn't recognise.
3. **Priority, not registration order, resolves same-extension conflicts.** Two handlers may
   legitimately both claim the same extension for different purposes (the ONLYOFFICE-class
   case: a bare `.xml` could be a word document or a spreadsheet depending on context) — the
   registry's [resolution algorithm](/extending/file-handlers#resolution-algorithm) is
   mime-first → extension-fallback → capability-filter → **priority-sort**. Pick a priority
   above `DownloadOnlyHandler` (`-1000`) and below nothing in particular — there is no reserved
   ceiling; choose a value that reflects how specific your match is relative to neighbours you
   know about.

## 3. Payload asymmetry — don't force every handler into TipTap's shape

Not every editable handler looks like a rich-text editor. Two payload families exist side by
side and the contract does not privilege either:

- **Rich-text-class** (TipTap-style): the client edits structured JSON directly; the server
  validates it against an allow-list on write and decays it defensively on read. `viewer.props`
  carries editor configuration (e.g. the extension list).
- **WOPI/office-class**: the actual editing happens in a *third-party* surface (an iframe
  pointed at an external service, or a native app via a protocol handler). `viewer.props` carries
  whatever that surface needs to bootstrap — typically an action URL and a short-lived token —
  **not** a content payload at all. The server never parses or validates the document body; it
  only brokers the session.

```php
// WOPI-class handler — the viewer() payload has a completely different shape.
public function viewer(FileDescriptor $file): ViewerDescriptor
{
    return new ViewerDescriptor(
        key: 'acme/office-frame',
        props: [
            'actionUrl' => $this->wopi->actionUrlFor($file),
            'accessToken' => $this->wopi->issueToken($file),
            'tokenTtl' => 900,
        ],
    );
}
```

The contract does **not** force your handler to reproduce another viewer's `props` shape — `props`
is an arbitrary, handler-decided map. Document your own
handler's `props` shape for the client team that consumes it; there is no shared schema to
conform to beyond "serialisable."

## 4. Versioning your own content shape

A handler serves file bytes and has no content schema of its own. If your editor stores
structured content in Vaulter, write a [document type](./document-types.md) instead: its
`schemaVersion()` is stored with every document, and `UpgradesSchema::upgrade()` raises older
content on read without rewriting history.

## 5. Licensing boundaries in third-party editor SDKs

If your handler wraps a dual-licensed SDK (the shape Univer ships in: `@univerjs/*` packages
are Apache-2.0, `@univerjs-pro/*` packages are commercial), document which import boundary your
handler's frontend code stays inside, and enforce it the same way you'd enforce any dependency
boundary (lint rule, import-restriction, or just a code comment at the import site) — a
transitive `-pro` import pulled in by a feature you didn't intend to gate is an easy license
mistake to make silently.

## 6. Registering it

Same three channels as any handler — see
[Document Handlers → Register it](/extending/file-handlers#_2-register-it): the config FQCN list
(cache-safe), `Vaulter::handlers()->register()` (runtime), or a tagged `'vaulter.file_handlers'` binding
(the channel core uses for its baseline handlers).

## 7. Mounting it on the client

See [Document Handlers → Mounting the resolved handler in Vue](/extending/file-handlers#mounting-the-resolved-handler-in-vue)
for the two registration channels (`componentMap` prop vs. the global
`registerEngineComponent(id, component)`) — the server-side registry never assumes a specific
frontend stack. What follows is the client-side skeleton itself: the component
`VaulterDocumentNode` mounts receives one `handler` prop, typed `NodePresentation` (exported by
`@vaulter/vue`), carrying your `ui.props` map verbatim.

```vue
<!-- AppMyEditor.vue — the component registered for handler id "my-editor" -->
<script setup lang="ts">
import { computed } from 'vue'
import type { DocumentNodeEditorProps } from '@vaulter/vue'

// `VaulterDocumentNode` binds this shape (`DocumentNodeEditorProps`) — `content`/`onChange`
// are typed for TipTap in the exported interface (the built-in editor), so a non-rich-text
// handler narrows `handler.ui.props` to its own shape instead of relying on `content`.
// `handler.ui.props` is whatever your PHP handler's viewer() returned (step 1/3) — an
// arbitrary, handler-decided map; the contract does not validate it for you.
interface MyEditorProps {
  format: string
  extensions: string[]
}

const props = defineProps<DocumentNodeEditorProps>()

const editorProps = computed(() => props.handler.ui.props as unknown as MyEditorProps)
</script>

<template>
  <div class="app-my-editor">
    <!-- Read editorProps.format / editorProps.extensions here; call onChange(next)
         on every local edit unless readonly is true. -->
  </div>
</template>
```

Register it once at startup, the same way as [any handler component](/extending/file-handlers#mounting-the-resolved-handler-in-vue):

```ts
// main.ts
import { registerEngineComponent } from '@vaulter/vue'
import AppMyEditor from './AppMyEditor.vue'

registerEngineComponent('my-editor', AppMyEditor)
```

No separate frontend package is required (D8, VLT-CANON P1.1): a third-party engine ships as
plain host application code — a `.vue` file plus one `registerEngineComponent` call — the same
way `@vaulter/vue` itself bundles its TipTap editor rather than publishing it standalone.
A dedicated `@vaulter/vue-engine-<name>` package is introduced later, lazily, only once a real
external consumer of that specific engine exists.

## Checklist

- [ ] `key()` is a stable, unique string — never renamed once shipped (H.5: a handler is a
      SemVer-frozen public surface from its release).
- [ ] The handler serves files; editable structured content is a document type, not a handler.
- [ ] `priority()` reflects how specific your match is relative to handlers you know conflict
      with you on the same extension/mime.
- [ ] `viewer.props` shape is documented for your own client code — it is not assumed to match
      any other viewer's.
- [ ] If wrapping a dual-licensed SDK, the free/commercial import boundary is enforced, not just
      remembered.
