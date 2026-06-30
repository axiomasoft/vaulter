<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Documents;

use Vaulter\ValueObjects\Documents\TipTapContent;

/**
 * Renders a document's TipTap content into an export format. Built-in HTML and
 * Markdown exporters ship with the package; register your own (PDF, DOCX, …)
 * via config('vaulter.exporters') or Vaulter::registerExporter().
 *
 * Binary formats should return an encoded string (e.g. base64) and advertise the
 * matching mime type.
 */
interface DocumentExporter
{
    /** The format key (e.g. 'html', 'pdf'); also the value echoed in the API. */
    public function format(): string;

    public function mimeType(): string;

    /** File extension, without the dot. */
    public function extension(): string;

    public function export(TipTapContent $content): string;
}
