---
title: Exporters
---

# Exporters

An exporter renders the content of a document into a file format (`html`, `markdown`, your own).
Scaffold one:

<!-- snippet: shell -->

```bash
php artisan vaulter:make:exporter Pdf
```

An exporter implements `DocumentExporter`. It receives decoded inline content only; it must not read
storage, blobs or URLs.

<!-- snippet: signature Vaulter\Documents\Contracts\DocumentExporter -->

```php
interface DocumentExporter
{
    // 'vendor/name', e.g. 'vaulter/html'.
    public function key(): string;

    // The URL segment clients ask for, e.g. 'html'.
    public function format(): string;

    public function mimeType(): string;

    // File extension, without the dot.
    public function extension(): string;

    // The document type keys this exporter can render.
    public function documentTypes(): array;

    public function export(DocumentContent $content, DocumentType $type): string;
}
```

Register it through `Documents::exporters()`. Clients must tolerate a format they do not know. For
using the built-in exporters, see [Export](/guides/export).
