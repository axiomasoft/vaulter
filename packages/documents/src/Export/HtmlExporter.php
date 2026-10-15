<?php

declare(strict_types=1);

namespace Vaulter\Documents\Export;

use Vaulter\Attributes\Api;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Content\TipTap\TipTapRenderer;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentExporter;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Exceptions\UnsupportedDocumentContentException;

#[Api]
final class HtmlExporter implements DocumentExporter
{
    public function __construct(
        private readonly TipTapRenderer $renderer,
    ) {}

    public function key(): string
    {
        return 'vaulter/html';
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
        return ['vaulter/rich-text', 'vaulter/markdown'];
    }

    public function export(DocumentContent $content, DocumentType $type): string
    {
        if (!$content instanceof TipTapContent) {
            throw UnsupportedDocumentContentException::forExporter('html', TipTapContent::class, $content::class);
        }

        return $this->renderer->toHtml($content);
    }
}
