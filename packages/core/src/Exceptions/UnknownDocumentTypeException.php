<?php

declare(strict_types=1);

namespace DocVault\Exceptions;

final class UnknownDocumentTypeException extends DocVaultException
{
    /**
     * @param list<string> $allowed
     */
    public static function forType(string $type, array $allowed): self
    {
        $list = implode(', ', $allowed);

        return new self(
            sprintf('[DocVault] Unknown document type [%s]. ', $type)
            .sprintf('Why: it is not in the enabled types [%s]. ', $list)
            .'Fix: enable it in config(docvault.enabled_document_types) or register a custom type '
            .'via DocVault::registerDocumentType(). '
            .'Docs: '.self::docs('unknown-document-type'),
        );
    }
}
