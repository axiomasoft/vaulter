<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Support\Identity;

/**
 * A document content/version operation targeted a node that is not kind=document
 * (§2.5 / M4a). A folder/file/shortcut has no v_node_document_versions payload, so
 * the operation is rejected rather than silently writing an orphan version.
 */
final class NotADocumentException extends VaulterException implements ProblemException
{
    private const string PROBLEM_CODE = 'not_a_document';

    private const int HTTP_STATUS = 409;

    public static function forNode(mixed $nodeId): self
    {
        return new self(
            sprintf('[Vaulter] Node [%s] is not a document node. ', Identity::stringify($nodeId))
            .'Why: document content/version operations require a kind=document node '
            .'(its payload lives in v_node_document_versions). '
            .'Fix: create the document via CreateDocumentAction first, or target a document node. '
            .'Docs: '.self::docs('not-a-document-node'),
        );
    }

    public function problemCode(): string
    {
        return self::PROBLEM_CODE;
    }

    public function httpStatus(): int
    {
        return self::HTTP_STATUS;
    }
}
