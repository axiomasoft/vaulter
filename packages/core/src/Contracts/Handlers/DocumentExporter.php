<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Handlers;

/**
 * Renders a document's content ({@see DocumentContent}, the same opaque payload
 * the write path stores) into an export format. Built-in HTML and Markdown
 * exporters ship with the package; register your own (PDF, DOCX, …) via
 * config('vaulter.exporters') or Vaulter::registerExporter().
 *
 * The contract does not assume TipTap — an exporter narrows to the concrete
 * content shape it understands (e.g. TipTapContent) internally, the same way a
 * {@see FileHandler} narrows a descriptor.
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

    public function export(DocumentContent $content): string;
}
