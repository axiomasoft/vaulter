<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts;

/**
 * Renders the content of a document into a file format. Registered in
 * Documents::exporters() and resolved by the pair (document type, format).
 *
 * An exporter only ever sees decoded inline content: it must not read storage, blobs
 * or URLs, so an export can never reach bytes the egress gate did not release.
 * Binary formats return the encoded bytes and advertise the matching mime type.
 *
 * @extensible Keys and formats are strings; a client must handle a format it does not know.
 */
interface DocumentExporter
{
    /** 'vendor/name', e.g. 'vaulter/html'. */
    public function key(): string;

    /** The URL segment and the value clients ask for, e.g. 'html'. */
    public function format(): string;

    public function mimeType(): string;

    /** File extension, without the dot. */
    public function extension(): string;

    /**
     * The document type keys this exporter can render.
     *
     * @return list<string>
     */
    public function documentTypes(): array;

    public function export(DocumentContent $content, DocumentType $type): string;
}
