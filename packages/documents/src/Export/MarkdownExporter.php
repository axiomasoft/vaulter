<?php

declare(strict_types=1);

namespace Vaulter\Documents\Export;

use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Content\TipTap\TipTapRenderer;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentExporter;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Exceptions\UnsupportedDocumentContentException;

final class MarkdownExporter implements DocumentExporter
{
    public function __construct(
        private readonly TipTapRenderer $renderer,
    ) {}

    public function key(): string
    {
        return 'vaulter/markdown';
    }

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

    public function documentTypes(): array
    {
        return ['vaulter/rich-text', 'vaulter/markdown'];
    }

    public function export(DocumentContent $content, DocumentType $type): string
    {
        if (!$content instanceof TipTapContent) {
            throw UnsupportedDocumentContentException::forExporter('markdown', TipTapContent::class, $content::class);
        }

        return $this->renderer->toMarkdown($content);
    }
}
