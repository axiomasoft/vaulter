<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Documents;

use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentExporter;
use Vaulter\Documents\Contracts\DocumentType;

/**
 * A minimal DocumentExporter for registry tests: core has no concrete exporter of its
 * own (HtmlExporter/MarkdownExporter ship in vaulter-documents).
 */
final class FakeExporter implements DocumentExporter
{
    public function key(): string
    {
        return 'acme/fake';
    }

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

    public function documentTypes(): array
    {
        return ['vaulter/rich-text'];
    }

    public function export(DocumentContent $content, DocumentType $type): string
    {
        return 'fake-export';
    }
}
