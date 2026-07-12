<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Documents;

use Vaulter\Contracts\Handlers\DocumentContent;
use Vaulter\Contracts\Handlers\DocumentExporter;

/**
 * A minimal DocumentExporter used to prove VaulterManager::registerExporter()
 * resolves an FQCN through the container (F-P3.3-01) — core has no concrete
 * exporter of its own (HtmlExporter/MarkdownExporter ship in vaulter-documents).
 */
final class FakeExporter implements DocumentExporter
{
    public function format(): string
    {
        return 'fake';
    }

    public function mimeType(): string
    {
        return 'text/plain';
    }

    public function extension(): string
    {
        return 'fake';
    }

    public function export(DocumentContent $content): string
    {
        return 'fake-export';
    }
}
