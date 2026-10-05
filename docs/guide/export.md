---
title: Export
description: Render a document's TipTap content to HTML or Markdown, and register your own exporter (PDF, DOCX, …) with no core change.
outline: deep
---

# Export

Render a document node's TipTap content to **HTML** or **Markdown** for download, email, or
display outside the editor — and plug in your own exporter (PDF, DOCX, …) without touching core.
Export runs over the node-attached [document path](./documents.md).

## The exporter contract

An exporter implements `Vaulter\Contracts\Handlers\DocumentExporter`:

```php
namespace Vaulter\Contracts\Handlers;

interface DocumentExporter
{
    /** The format key (e.g. 'html', 'pdf') — also echoed in the API response. */
    public function format(): string;

    public function mimeType(): string;

    /** File extension, without the dot. */
    public function extension(): string;

    public function export(DocumentContent $content): string;
}
```

`DocumentContent` is the same opaque payload interface the write path stores — the
contract does not assume TipTap. An exporter narrows to the concrete shape it
understands (e.g. `TipTapContent`) inside `export()`, the same way a `FileHandler`
narrows a descriptor.

Binary formats return an encoded string (e.g. base64) and advertise the matching mime type.

## Built-in exporters

Two ship with the package, registered in config:

```php
// config/vaulter.php
'exporters' => [
    'html'     => Vaulter\Export\HtmlExporter::class,
    'markdown' => Vaulter\Export\MarkdownExporter::class,
],
```

| Format | Class | mime | extension |
|---|---|---|---|
| `html` | `HtmlExporter` | `text/html` | `html` |
| `markdown` | `MarkdownExporter` | `text/markdown` | `md` |

Both delegate to `Vaulter\Documents\Support\TipTap\TipTapRenderer`, which handles the common node and mark set —
headings, paragraphs, bullet/ordered lists, list items, blockquotes, code blocks, horizontal
rules and hard breaks, plus the **bold / italic / strike / code / link** marks — with HTML
output escaped (`htmlspecialchars`). Unknown nodes fall back to rendering their children, so an
unrecognised node never breaks an export.

::: tip Default format
When no `format` query is given, both export endpoints default to `html`.
:::

## Exporting a document node

`GET {prefix}/nodes/{node}/export` renders a [document node's](./documents.md) head content.
It requires the `View` ability on the node (and the node must be `kind=document`). This route
is part of the Documents surface, gated on the **`document_versions`** feature.

::: code-group

```ts [Vue — composable]
import { useDocumentNode } from '@vaulter/vue'

const { exportAs } = useDocumentNode(nodeId)

const result = await exportAs('markdown')
// → { format: 'markdown', content: '# …', mime_type: 'text/markdown', filename: 'notes.md' }
```

```bash [HTTP]
# GET {prefix}/nodes/{node}/export?format=html|markdown
curl 'https://app.test/api/vaulter/nodes/77/export?format=markdown'
# → { "data": { "format": "markdown", "content": "# …",
#               "mime_type": "text/markdown", "filename": "notes.md" } }
```

```ts [Vue — client directly]
import { useVaulterClient } from '@vaulter/vue'

const client = useVaulterClient()
const result = await client.documentNodes.export(nodeId, 'html')
```

:::

The node export response is a `NodeDocumentExport` (`Vaulter\Documents\ValueObjects\DocumentExport`),
which **includes `mime_type`**:

```ts
interface NodeDocumentExport {
  format: string        // the exporter's format key
  content: string       // the rendered output
  mime_type: string     // the exporter's mime type
  filename: string      // derived from the node name + the exporter extension
}
```

::: info Export gated by handler capability
A node only exposes export formats when its resolved handler is **editable or previewable**
(`NodeExportService::availableFormats`). A download-only node (an archive, an unknown binary)
exposes no export formats. See [Document Handlers](./document-handlers.md).
:::

## Registering your own exporter (PDF / DOCX)

Heavy converters (PDF, DOCX) are intentionally **not** bundled — they need large renderers.
They are a clean registerable seam: implement `DocumentExporter` and register it. No core
change.

### 1. Implement the exporter

```php
<?php

declare(strict_types=1);

namespace App\Vaulter\Exporters;

use Vaulter\Contracts\Handlers\DocumentContent;
use Vaulter\Contracts\Handlers\DocumentExporter;
use Vaulter\Documents\Support\TipTap\TipTapRenderer;
use Vaulter\Exceptions\Documents\UnsupportedDocumentContentException;
use Vaulter\Documents\ValueObjects\TipTapContent;

final class PdfExporter implements DocumentExporter
{
    public function __construct(
        private readonly TipTapRenderer $renderer,   // reuse the built-in HTML renderer
    ) {}

    public function format(): string
    {
        return 'pdf';
    }

    public function mimeType(): string
    {
        return 'application/pdf';
    }

    public function extension(): string
    {
        return 'pdf';
    }

    public function export(DocumentContent $content): string
    {
        // The contract is opaque — this exporter only understands TipTap docs, so
        // narrow before rendering. Throw the package's own exception so a host
        // catching VaulterException still catches this.
        if (!$content instanceof TipTapContent) {
            throw UnsupportedDocumentContentException::forExporter(
                $this->format(), TipTapContent::class, $content::class,
            );
        }

        // Render the TipTap doc to HTML, then to a PDF with your library of choice
        // (e.g. dompdf / wkhtmltopdf / a service). A BINARY format returns an
        // ENCODED string (base64 here) and advertises its mime above.
        $html = $this->renderer->toHtml($content);

        $pdfBytes = MyPdfLibrary::fromHtml($html);   // your renderer

        return base64_encode($pdfBytes);
    }
}
```

::: tip Binary formats
For binary output (PDF, DOCX), return an **encoded** string (base64 above) and advertise the
real mime type. The client decodes it back to bytes for download.
:::

### 2. Register it

::: code-group

```php [config — cache-safe FQCN map]
// config/vaulter.php — add to the exporters map (format => FQCN)
'exporters' => [
    'html'     => Vaulter\Export\HtmlExporter::class,
    'markdown' => Vaulter\Export\MarkdownExporter::class,
    'pdf'      => App\Vaulter\Exporters\PdfExporter::class,   // [!code ++]
],
```

```php [runtime — Vaulter::registerExporter]
// In a service provider's boot() — pass an INSTANCE
use Vaulter\Facades\Vaulter;
use App\Vaulter\Exporters\PdfExporter;

Vaulter::registerExporter(app(PdfExporter::class));
```

:::

::: tip Config vs runtime
`config('vaulter.exporters')` is an FQCN map (config-cache safe, resolved through the
container on first use). `Vaulter::registerExporter()` takes an already-built instance — the
runtime seam. A runtime-registered exporter wins over a config one with the same key.
:::

### 3. Use it

`?format=pdf` now resolves to your exporter on both export endpoints, and it shows up in the
node's `availableFormats` (when the handler is exportable):

```bash
curl 'https://app.test/api/vaulter/nodes/77/export?format=pdf'
# → { "data": { "format": "pdf", "content": "<base64…>",
#               "mime_type": "application/pdf", "filename": "notes.pdf" } }
```

`Vaulter::exporterFormats()` returns every registered format (config + runtime, de-duplicated)
— the list the request validation and `availableFormats` draw from.

## Errors

| Exception / status | When |
|---|---|
| `Exceptions\Documents\UnknownExportFormatException` | An export requests a format with no registered exporter (the message lists the available ones). |
| `422 Unprocessable` | The legacy `documents/{id}/export` `format` query is not a registered exporter format (validation rule). |
| `Exceptions\NotADocumentException` | The node export targets a node that is not `kind=document`. |

## Reference

- Contract — `Vaulter\Contracts\Handlers\DocumentExporter`
- Built-ins — `Vaulter\Export\HtmlExporter`, `Vaulter\Export\MarkdownExporter`
- Renderer — `Vaulter\Documents\Support\TipTap\TipTapRenderer` (`toHtml`, `toMarkdown`)
- Node export — `Vaulter\Documents\NodeExportService` (`export`, `availableFormats`)
- Manager seam — `Vaulter\VaulterManager::registerExporter()` / `::exporter()` / `::exporterFormats()`
- Enum — `Vaulter\Enums\Documents\ExportFormat`

## Next

- [Document Nodes](./documents.md) — create, edit and version the content you export.
- [Document Handlers](./document-handlers.md) — which surface a node opens in, and adding a new
  format with no migration.
