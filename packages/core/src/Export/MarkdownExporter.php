<?php

declare(strict_types=1);

namespace Vaulter\Export;

use Vaulter\Contracts\Documents\DocumentExporter;
use Vaulter\Enums\Documents\ExportFormat;
use Vaulter\Support\TipTapRenderer;
use Vaulter\ValueObjects\Documents\TipTapContent;

final class MarkdownExporter implements DocumentExporter
{
    public function __construct(
        private readonly TipTapRenderer $renderer,
    ) {}

    public function format(): string
    {
        return 'markdown';
    }

    public function mimeType(): string
    {
        return 'text/markdown';
    }

    public function extension(): string
    {
        return 'md';
    }

    public function export(TipTapContent $content): string
    {
        return $this->renderer->render($content, ExportFormat::Markdown);
    }
}
