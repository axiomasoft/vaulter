---
title: Writing a Handler/Engine
description: A from-scratch checklist for a third-party file handler — the contract, the capability-policy invariants, payload asymmetry, and where the seam stops.
outline: deep
---

# Writing a Handler/Engine

[Document Handlers](./document-handlers.md) shows the contract and a worked CSV example. This
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

Start from [Document Handlers → the worked CSV example](./document-handlers.md#adding-a-new-format-a-worked-example)
if your handler is preview/download-only. The rest of this guide assumes an **editable**
handler — the richer case.

```php
<?php

declare(strict_types=1);

namespace App\Vaulter\Handlers;

use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\HandlerCapabilities;
use Vaulter\ValueObjects\Handlers\HandlerUi;

final class MyEditorHandler implements FileHandler
{
    public const int PRIORITY = 90;

    public function id(): string
    {
        return 'my-editor';
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

    public function capabilities(FileDescriptor $file): HandlerCapabilities
    {
        return new HandlerCapabilities(
            editable: true,
            previewable: true,
            collaborative: false,
            // richText stays at its default (false, §3) — this handler's content
            // is its OWN shape, not a TipTap {"type":"doc"} projection.
        );
    }

    public function ui(FileDescriptor $file): HandlerUi
    {
        return new HandlerUi(
            component: 'AppMyEditor',
            route: 'documents.show',
        );
    }
}
```

Implementing the interface directly is always enough. `Vaulter\Engines\Handlers\
AbstractFileHandler` is an *optional* DX base that defaults `extensions()` to `[]` for a
mime-only handler — it saves one method, nothing more. The built-in handlers do not extend it;
they stay `final` (H.5).

## 2. The capability-policy invariants (read before you ship)

`HandlerCapabilities` is an **additive, open axis set** (P0.5). Three rules keep your handler
(and every handler that comes after it) safe to compose:

1. **A capability you don't declare is `false`, never inferred.** `richText` defaults to
   `false` — a kind-neutral handler makes no claim about content shape unless it opts in. If
   your handler's editable content IS a TipTap `{"type":"doc"}` document, set `richText: true`
   explicitly; otherwise leave it at the default and your content round-trips as an opaque
   snapshot (`OpaqueDocumentContent`) — never validated, never coerced into TipTap's shape.
2. **A future new capability flag must not break your handler.** You are not required to know
   about every flag `HandlerCapabilities` carries — the constructor gives every flag a safe
   default. The client mirrors this: `HandlerCapabilities` on the TypeScript side carries an
   open index signature (`[capability: string]: boolean | undefined`), so a client build that
   predates a new flag keeps compiling and ignores the key it doesn't recognise.
3. **Priority, not registration order, resolves same-extension conflicts.** Two handlers may
   legitimately both claim the same extension for different purposes (the ONLYOFFICE-class
   case: a bare `.xml` could be a word document or a spreadsheet depending on context) — the
   registry's [resolution algorithm](./document-handlers.md#resolution-algorithm) is
   mime-first → extension-fallback → capability-filter → **priority-sort**. Pick a priority
   above `DownloadOnlyHandler` (`-1000`) and below nothing in particular — there is no reserved
   ceiling; choose a value that reflects how specific your match is relative to neighbours you
   know about.

## 3. Payload asymmetry — don't force every handler into TipTap's shape

Not every editable handler looks like a rich-text editor. Two payload families exist side by
side and the contract does not privilege either:

- **Rich-text-class** (TipTap-style): the client edits structured JSON directly; the server
  validates it against an allow-list on write and decays it defensively on read. `ui.props`
  carries editor configuration (e.g. the extension list).
- **WOPI/office-class**: the actual editing happens in a *third-party* surface (an iframe
  pointed at an external service, or a native app via a protocol handler). `ui.props` carries
  whatever that surface needs to bootstrap — typically an action URL and a short-lived token —
  **not** a content payload at all. The server never parses or validates the document body; it
  only brokers the session.

```php
// WOPI-class handler — the ui() payload has a completely different shape.
public function ui(FileDescriptor $file): HandlerUi
{
    return new HandlerUi(
        component: 'AppOfficeFrame',
        route: null, // the client opens the action URL directly, not a Vaulter route
        props: [
            'actionUrl' => $this->wopi->actionUrlFor($file),
            'accessToken' => $this->wopi->issueToken($file),
            'tokenTtl' => 900,
        ],
    );
}
```

The contract does **not** force your handler to reproduce `TipTapHandler`'s `ui.props` shape
(`format`, `extensions`) — `props` is an arbitrary, handler-decided map. Document your own
handler's `props` shape for the client team that consumes it; there is no shared schema to
conform to beyond "serialisable."

## 4. Versioning your own content shape

If your handler's `DocumentContent` implementation evolves after nodes already exist with the
old shape, carry a `schemaVersion` (or similar) key inside your own payload — `DocumentContent`
is opaque to the core by design (`toArray()`/`contentHash()`/`sizeInBytes()` only), so schema
evolution is entirely your handler's responsibility. `TipTapContent` does not currently carry
one; if you fork or extend that shape, decide up front whether you need it — retrofitting a
version discriminator onto already-persisted rows is far more expensive than shipping one from
day one.

## 5. Licensing boundaries in third-party editor SDKs

If your handler wraps a dual-licensed SDK (the shape Univer ships in: `@univerjs/*` packages
are Apache-2.0, `@univerjs-pro/*` packages are commercial), document which import boundary your
handler's frontend code stays inside, and enforce it the same way you'd enforce any dependency
boundary (lint rule, import-restriction, or just a code comment at the import site) — a
transitive `-pro` import pulled in by a feature you didn't intend to gate is an easy license
mistake to make silently.

## 6. Registering it

Same three channels as any handler — see
[Document Handlers → Register it](./document-handlers.md#2-register-it): the config FQCN list
(cache-safe), `Vaulter::registerHandler()` (runtime), or a tagged `'vaulter.handlers'` binding
(what the package itself uses for the feature-flagged `UniverHandler`).

## 7. Mounting it on the client

See [Document Handlers → Mounting the resolved handler in Vue](./document-handlers.md#mounting-the-resolved-handler-in-vue)
for the two registration channels (`componentMap` prop vs. the global
`registerEngineComponent(id, component)`) — the server-side registry never assumes a specific
frontend stack. What follows is the client-side skeleton itself: the component
`VaulterDocumentNode` mounts receives one `handler` prop, typed `HandlerDescriptor` (exported by
`@vaulter/vue`), carrying your `ui.props` map verbatim.

```vue
<!-- AppMyEditor.vue — the component registered for handler id "my-editor" -->
<script setup lang="ts">
import { computed } from 'vue'
import type { DocumentNodeEditorProps } from '@vaulter/vue'

// `VaulterDocumentNode` binds this shape (`DocumentNodeEditorProps`) — `content`/`onChange`
// are typed for TipTap in the exported interface (the built-in editor), so a non-rich-text
// handler narrows `handler.ui.props` to its own shape instead of relying on `content`.
// `handler.ui.props` is whatever your PHP handler's ui() returned (step 1/3) — an
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

Register it once at startup, the same way as [any handler component](./document-handlers.md#mounting-the-resolved-handler-in-vue):

```ts
// main.ts
import { registerEngineComponent } from '@vaulter/vue'
import AppMyEditor from './AppMyEditor.vue'

registerEngineComponent('my-editor', AppMyEditor)
```

No separate frontend package is required (D8, VLT-CANON P1.1): a third-party engine ships as
plain host application code — a `.vue` file plus one `registerEngineComponent` call — the same
way `@vaulter/vue` itself bundles `TipTapHandler`'s editor rather than publishing it standalone.
A dedicated `@vaulter/vue-engine-<name>` package is introduced later, lazily, only once a real
external consumer of that specific engine exists.

## Checklist

- [ ] `id()` is a stable, unique string — never renamed once shipped (H.5: a handler is a
      SemVer-frozen public surface from its release).
- [ ] `richText` is set explicitly TRUE only if your content really is a TipTap
      `{"type":"doc"}` document; otherwise left at its default FALSE.
- [ ] `priority()` reflects how specific your match is relative to handlers you know conflict
      with you on the same extension/mime.
- [ ] `ui.props` shape is documented for your own client code — it is not assumed to match
      `TipTapHandler`'s.
- [ ] If your content shape may evolve, you decided on a `schemaVersion` (or explicitly decided
      you don't need one yet).
- [ ] If wrapping a dual-licensed SDK, the free/commercial import boundary is enforced, not just
      remembered.
