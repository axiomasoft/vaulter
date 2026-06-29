<?php

declare(strict_types=1);

namespace Vaulter\Export;

use Vaulter\Contracts\DocumentExporter;
use Vaulter\Enums\ExportFormat;
use Vaulter\Support\TipTapRenderer;
use Vaulter\ValueObjects\TipTapContent;

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

    public function export(TipTapContent $content): string
    {
        return $this->renderer->render($content, ExportFormat::Html);
    }
}
