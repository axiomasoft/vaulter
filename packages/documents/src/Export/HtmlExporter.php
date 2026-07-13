<?php

declare(strict_types=1);

namespace Vaulter\Documents\Export;

use Vaulter\Contracts\Handlers\DocumentContent;
use Vaulter\Contracts\Handlers\DocumentExporter;
use Vaulter\Documents\Enums\Export\ExportFormat;
use Vaulter\Documents\Support\TipTap\TipTapRenderer;
use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Exceptions\Documents\UnsupportedDocumentContentException;

final class HtmlExporter implements DocumentExporter
{
    public function __construct(
        private readonly TipTapRenderer $renderer,
    ) {}

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

    public function export(DocumentContent $content): string
    {
        if (!$content instanceof TipTapContent) {
            throw UnsupportedDocumentContentException::forExporter('html', TipTapContent::class, $content::class);
        }

        return $this->renderer->render($content, ExportFormat::Html);
    }
}
