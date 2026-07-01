<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Documents;

use Vaulter\Documents\Handlers\UniverHandler;
use Vaulter\ValueObjects\Documents\OpaqueDocumentContent;
use Vaulter\ValueObjects\Documents\TipTapContent;

/**
 * The content payload a document version stores (§2.5 / M4a). A document node's
 * handler decides the SHAPE: a rich-text handler produces a validated TipTap doc
 * ({@see TipTapContent}); a non-rich-text handler
 * (e.g. the spreadsheet {@see UniverHandler}) produces an
 * OPAQUE JSON snapshot the core stores verbatim ({@see OpaqueDocumentContent}).
 *
 * The write path (request → action) speaks to this interface, so it never assumes
 * TipTap: it stores `toArray()` in the version's jsonb `content` and dedups identical
 * consecutive autosaves by `contentHash()`.
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
     * regardless of whether the payload is a TipTap doc or an opaque snapshot.
     */
    public function sizeInBytes(): int;
}
