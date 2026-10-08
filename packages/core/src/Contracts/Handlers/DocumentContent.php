<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Handlers;

/**
 * The content payload of a document, as the exporter seam sees it. Core knows only
 * this interface, never a concrete shape: the document type that wrote the content
 * decides it.
 *
 * Document types implement `Vaulter\Documents\Contracts\DocumentContent`, which
 * extends this one; it stays in core only while core's DocumentExporter names it.
 */
interface DocumentContent
{
    /**
     * The JSON payload to persist in the version's `content` column.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * A deterministic sha256 over the canonical payload — lets the autosave path skip a
     * new row when the content did not actually change.
     */
    public function contentHash(): string;

    /**
     * The byte size of the canonical JSON payload — the write path stamps it on the
     * node's size_bytes so a document node participates in quota the same as a file,
     * whatever the shape of the payload.
     */
    public function sizeInBytes(): int;
}
