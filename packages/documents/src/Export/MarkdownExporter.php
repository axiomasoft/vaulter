<?php

declare(strict_types=1);

namespace Vaulter\Documents\Export;

use Vaulter\Contracts\Handlers\DocumentContent;
use Vaulter\Contracts\Handlers\DocumentExporter;
use Vaulter\Documents\Enums\Export\ExportFormat;
use Vaulter\Documents\Exceptions\UnsupportedDocumentContentException;
use Vaulter\Documents\Support\TipTapRenderer;
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

    public function export(DocumentContent $content): string
    {
        if (!$content instanceof TipTapContent) {
            throw UnsupportedDocumentContentException::forExporter('markdown', TipTapContent::class, $content::class);
        }

        return $this->renderer->render($content, ExportFormat::Markdown);
    }
}
