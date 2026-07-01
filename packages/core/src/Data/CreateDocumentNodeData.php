<?php

declare(strict_types=1);

namespace Vaulter\Data;

use Vaulter\Contracts\Documents\DocumentContent;
use Vaulter\Documents\Handlers\TipTapHandler;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;

/**
 * Command for CreateDocumentNodeAction (§2.5 / M4a): create a kind=document node
 * under a folder (or the storage root) with an initial v1 document version from the
 * given content. The content is handler-agnostic ({@see DocumentContent}) — a
 * rich-text TipTap doc OR an opaque snapshot (e.g. a Univer workbook, §7 level 2).
 *
 * mimeType is the value STAMPED on the node so a read re-resolves the same handler:
 * the TipTap synthetic document mime by default, or the format's concrete mime
 * (e.g. the xlsx sheet mime) for a non-rich-text handler. The destination parent /
 * target storage are route-resolved by the controller; the Action receives them
 * resolved.
 */
final readonly class CreateDocumentNodeData
{
    public function __construct(
        public Storage $storage,
        public string $name,
        public DocumentContent $content,
        public ?Node $parent = null,
        public string $format = 'tiptap',
        public string $mimeType = TipTapHandler::DOCUMENT_MIME,
        public int|string|null $actorId = null,
    ) {}
}
