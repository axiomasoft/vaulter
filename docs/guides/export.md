---
title: Export
description: Export a document as a file, and register your own exporter (PDF, DOCX, …) by document type and format.
outline: deep
---

# Export

Export a document to a **file** — HTML or Markdown out of the box — and plug in your own
exporter (PDF, DOCX, …) without touching core. Export belongs to `vaulter-documents` and runs
over the [document path](./documents.md).

## The exporter contract

An exporter renders one document type into one format. The registry resolves it by the pair
**(document type, format)**:

<!-- snippet: signature Vaulter\Documents\Contracts\DocumentExporter -->

```php
namespace Vaulter\Documents\Contracts;

interface DocumentExporter
{
    public function key(): string;          // 'vaulter/html'
    public function format(): string;       // 'html' — the {format} path segment
    public function mimeType(): string;
    public function extension(): string;    // without the dot
    /** @return list<string> keys of the document types it can render */
    public function documentTypes(): array;
    public function export(DocumentContent $content, DocumentType $type): string;
}
```

An exporter receives **decoded inline content only**. It must not read storage, blobs or URLs
(an architecture test enforces this for the package's **built-in** exporters only; for your own
classes it is a requirement you keep, not one the test checks), so an export can never reach
bytes the egress gate did not release. Images and attachments stay links in the output. Narrow to
the content class you understand inside `export()` and throw
`UnsupportedDocumentContentException` otherwise.

Binary formats return the encoded bytes of the file and advertise the matching mime type.

## Built-in exporters

| Key | Format | mime | extension | Document types |
|---|---|---|---|---|
| `vaulter/html` | `html` | `text/html` | `html` | `vaulter/rich-text`, `vaulter/markdown` |
| `vaulter/markdown` | `markdown` | `text/markdown` | `md` | `vaulter/rich-text`, `vaulter/markdown` |

<!-- snippet: config -->

```php
// config/vaulter-documents.php
'exporters' => [HtmlExporter::class, MarkdownExporter::class],
```

Both delegate to `TipTapRenderer`: HTML goes through `symfony/html-sanitizer`, Markdown is escaped
as Markdown. A document type that no exporter lists (a spreadsheet, say) has no export formats.

## Exporting a document

`GET {prefix}/v1/documents/{node}/export/{format}` (`html`, `markdown`) answers the **file**;
`?revision={id}` renders one checkpoint instead of the working state:

```http
HTTP/1.1 200 OK
Content-Type: text/markdown
Content-Disposition: attachment; filename="notes.md"; filename*=utf-8''notes.md
Cache-Control: private, no-store
X-Content-Type-Options: nosniff
Content-Security-Policy: sandbox; default-src 'none'
Accept-Ranges: none
```

The body is the exporter's bytes. The name is the node name plus the exporter's extension; the
scheme is the `ExportFilenameResolver` binding. It requires the **`Download`** ability and passes
the content-egress gate — a previewer gets `403 permission_denied`. A format the document's type has
no exporter for is `422 unknown_export_format` (the allowed ones are in `meta.allowed`) and
`X-Vaulter-Export-Lossy: 1` marks content its type could only read in part.

In PHP:

<!-- snippet: host -->

```php
$export = Documents::in($drive)->export($node, 'html');           // working state
$export = Documents::in($drive)->export($node, 'html', $revision); // one checkpoint

$export->bytes; $export->filename; $export->mimeType; $export->format;
$export->sourceEpoch; $export->sourceStateVersion; $export->sourceRevisionId;
```

Without a revision the export renders the **working state** at `(sourceEpoch,
sourceStateVersion)` and `sourceRevisionId` is `null`; with one it renders that immutable
checkpoint. An export never creates a revision and never changes the state version.

`sourceEpoch` is always the **document's** collaboration epoch at the moment of the export, also
for a checkpoint: a checkpoint records no epoch of its own, so it is identified by
`sourceRevisionId` and its own `sourceStateVersion`, and the epoch only says which room
generation was current when it was rendered.

`$export->lossy` is `true` when the stored content could not be read whole (written by a newer
schema, or content its type reads only partly): the bytes are what the type could read, not
necessarily everything that is stored. Do not cache or archive such a render as the document's
export. Over HTTP the file carries `X-Vaulter-Export-Lossy: 1` in that case.

If you cache renders, key them on `(documentId, sourceEpoch, sourceStateVersion | sourceRevisionId,
exporter key, format)` and re-check access on every hit.

<!-- snippet: host -->

```ts
import { useVaulterClient } from '@vaulter/vue'

const file = await useVaulterClient().documents.export(nodeId, 'markdown')
// → { blob: Blob, filename: 'notes.md', mimeType: 'text/markdown' }
```

## Registering your own exporter

Scaffold one with `php artisan vaulter:make:exporter Pdf` (writes
`app/Vaulter/Exporters/PdfExporter.php` and prints the config line), or write it by hand:

<!-- snippet: host -->

```php
final class PdfExporter implements DocumentExporter
{
    public function __construct(private readonly TipTapRenderer $renderer) {}

    public function key(): string { return 'acme/pdf'; }
    public function format(): string { return 'pdf'; }
    public function mimeType(): string { return 'application/pdf'; }
    public function extension(): string { return 'pdf'; }
    public function documentTypes(): array { return ['vaulter/rich-text']; }

    public function export(DocumentContent $content, DocumentType $type): string
    {
        if (!$content instanceof TipTapContent) {
            throw UnsupportedDocumentContentException::forExporter($this->format(), TipTapContent::class, $content::class);
        }

        return MyPdfLibrary::fromHtml($this->renderer->toHtml($content));
    }
}
```

Register it by class name in config (cache-safe), with the `vaulter.exporters` container tag, or
from a provider:

<!-- snippet: config -->

```php
// config/vaulter-documents.php
'exporters' => [HtmlExporter::class, MarkdownExporter::class, App\Vaulter\Exporters\PdfExporter::class],

// a provider's register()/boot(), before the application has booted
Documents::exporters()->register(PdfExporter::class);
Documents::exporters()->replace('vaulter/html', MyHtmlExporter::class); // swap a built-in
```

Two exporters for one (type, format) pair fail at boot with `DuplicateDocumentExporterException`;
swap one with `replace()` instead. The registry freezes once the application has booted.

## Generator

<!-- snippet: shell -->

```bash
php artisan vaulter:make:exporter Pdf
```

Writes an exporter in `app/Vaulter/Exporters/PdfExporter.php` and a Pest test in `tests/Unit/Vaulter/` that runs `ExporterContractTests`. It prints the line to `vaulter-documents.exporters`
and never edits your config. An existing file is kept unless you pass `--force`.

## Testing your extension

`ExporterContractTests` checks an exporter against the contract:

<!-- snippet: host -->

```php
use Vaulter\Documents\Testing\Contracts\ExporterContractTests;

it('keeps the exporter contract', function (): void {
    (new ExporterContractTests)->assertExporterContract(app(PdfExporter::class));
});
```

Run it inside the application. It checks the key, format, extension and mime type formats; that
the registry resolves the exporter for every `(type, format)` it declares and refuses a type it
does not render, and a second registration; that the bound `ExportFilenameResolver` gives a file
name ending with the extension; and that exporting the default content of each installed type
twice gives the same bytes.

## Errors

| Code | Status | When |
|---|---|---|
| `permission_denied` | 403 | The actor may view but not download. |
| `node_not_found` | 404 | No access, a trashed node or another tenant's. |
| `document_type_unavailable` | 409 | The document's type is not installed. |
| `unknown_export_format` | 422 | The type has no exporter for the format; `allowed` lists the formats it does have. |
| `invalid_document_content` | 422 | An exporter was given content it cannot render. |

## Reference

- Contract — `Vaulter\Documents\Contracts\DocumentExporter`
- Registry — `Vaulter\Documents\Export\DocumentExporterRegistry` (`register`, `replace`, `for`, `formatsFor`)
- Action — `Vaulter\Documents\Actions\Export\ExportDocumentAction`; result `Vaulter\Documents\ValueObjects\DocumentExport`
- Built-ins — `HtmlExporter`, `MarkdownExporter`; renderer `TipTapRenderer` (`toHtml`, `toMarkdown`)

## Next

- [Document Nodes](./documents.md) — create, edit and version the content you export.
- [Document Types](/extending/document-types) — which types exist and how to add one.
