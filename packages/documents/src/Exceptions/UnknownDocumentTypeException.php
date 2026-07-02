<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Exceptions\VaulterException;

final class UnknownDocumentTypeException extends VaulterException
{
    /**
     * @param list<string> $allowed
     */
    public static function forType(string $type, array $allowed): self
    {
        $list = implode(', ', $allowed);

        return new self(
            sprintf('[Vaulter] Unknown document type [%s]. ', $type)
            .sprintf('Why: it is not in the enabled types [%s]. ', $list)
            .'Fix: enable it in config(vaulter.enabled_document_types) or register a custom type '
            .'via Vaulter::registerDocumentType(). '
            .'Docs: '.self::docs('unknown-document-type'),
        );
    }
}
