<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Documents;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;

/**
 * Command for CreateDocumentAction: a kind=document node under a folder (or the
 * storage root) with its first checkpoint. $content is already decoded by the type
 * named in $type, written in $schemaVersion. The destination and the type are
 * resolved and authorized by DocumentOperations; the actor comes from the operation
 * context, never from the input.
 */
#[Api]
final readonly class CreateDocumentInput
{
    /**
     * Every document node carries this mime. A document is presented by its type, so
     * the mime only tells file-oriented consumers that the node is not a file.
     */
    public const string NODE_MIME = 'application/vnd.vaulter.document+json';

    #[Internal]
    public function __construct(
        public Drive $storage,
        public string $name,
        public DocumentContent $content,
        public ?Node $parent = null,
        public string $type = 'vaulter/rich-text',
        public int $schemaVersion = 1,
        public OnNameConflict $onConflict = OnNameConflict::Fail,
    ) {}
}
