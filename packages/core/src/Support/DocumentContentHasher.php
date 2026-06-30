<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Vaulter\ValueObjects\TipTapContent;

/**
 * The content_hash a document version carries (§2.5) — a deterministic sha256 over
 * the canonical TipTap JSON, used to dedup identical consecutive snapshots (an
 * autosave that did not actually change the content can skip a new row). A pure,
 * stateless helper (technical axis — no domain knowledge).
 */
final class DocumentContentHasher
{
    public static function hash(TipTapContent $content): string
    {
        return hash('sha256', (string) json_encode($content->toArray()));
    }
}
