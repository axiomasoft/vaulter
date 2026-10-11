<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\Testing;

use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentExporter;
use Vaulter\Documents\Contracts\DocumentType;

/** Breaks the exporter contract: the same content renders to different bytes each time. */
final class BrokenExporter implements DocumentExporter
{
    private int $calls = 0;

    public function key(): string
    {
        return 'acme/broken';
    }

    public function format(): string
    {
        return 'html';
    }

    public function mimeType(): string
    {
        return 'text/html';
    }

    public function extension(): string
    {
        return 'html';
    }

    public function documentTypes(): array
    {
        return ['vaulter/rich-text'];
    }

    public function export(DocumentContent $content, DocumentType $type): string
    {
        return '<p>'.++$this->calls.'</p>';
    }
}
