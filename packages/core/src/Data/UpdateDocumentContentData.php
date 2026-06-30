<?php

declare(strict_types=1);

namespace Vaulter\Data;

use Vaulter\Models\Node;
use Vaulter\ValueObjects\TipTapContent;

/**
 * Command for UpdateDocumentContentAction (§2.5 / M4a) — the autosave path: write
 * the document node's content. Within the autosave-debounce window the head autosave
 * version is UPDATED in place; outside it a fresh is_autosave version is appended.
 * yjsState carries the optional binary Yjs CRDT update (the M4b realtime source of
 * truth); content is its TipTap projection.
 */
final readonly class UpdateDocumentContentData
{
    public function __construct(
        public Node $node,
        public TipTapContent $content,
        public ?string $yjsState = null,
        public int|string|null $actorId = null,
    ) {}
}
