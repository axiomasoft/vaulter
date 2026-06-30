<?php

declare(strict_types=1);

namespace Vaulter\Data;

use Vaulter\Models\Node;
use Vaulter\Models\Storage;
use Vaulter\ValueObjects\TipTapContent;

/**
 * Command for CreateDocumentNodeAction (§2.5 / M4a): create a kind=document node
 * under a folder (or the storage root) with an initial v1 document version from the
 * given TipTap content. format defaults to 'tiptap'. The destination parent / target
 * storage are route-resolved by the controller; the Action receives them resolved.
 */
final readonly class CreateDocumentNodeData
{
    public function __construct(
        public Storage $storage,
        public string $name,
        public TipTapContent $content,
        public ?Node $parent = null,
        public string $format = 'tiptap',
        public int|string|null $actorId = null,
    ) {}
}
