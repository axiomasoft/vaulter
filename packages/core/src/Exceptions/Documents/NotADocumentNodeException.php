<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Documents;

use Vaulter\Exceptions\VaulterException;
use Vaulter\Support\Identity;

/**
 * A document content/version operation targeted a node that is not kind=document
 * (§2.5 / M4a). A folder/file/shortcut has no v_node_document_versions payload, so
 * the operation is rejected rather than silently writing an orphan version.
 */
final class NotADocumentNodeException extends VaulterException
{
    public static function forNode(mixed $nodeId): self
    {
        return new self(
            sprintf('[Vaulter] Node [%s] is not a document node. ', Identity::stringify($nodeId))
            .'Why: document content/version operations require a kind=document node '
            .'(its payload lives in v_node_document_versions). '
            .'Fix: create the document via CreateDocumentNodeAction first, or target a document node. '
            .'Docs: '.self::docs('not-a-document-node'),
        );
    }
}
