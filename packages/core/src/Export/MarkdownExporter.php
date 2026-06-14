<?php

declare(strict_types=1);

namespace DocVault\Export;

use DocVault\Contracts\DocumentExporter;
use DocVault\Enums\ExportFormat;
use DocVault\Support\TipTapRenderer;
use DocVault\ValueObjects\TipTapContent;

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
