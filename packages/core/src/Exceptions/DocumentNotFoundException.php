<?php

declare(strict_types=1);

namespace DocVault\Exceptions;

final class DocumentNotFoundException extends DocVaultException
{
    public static function withId(int|string $id): self
    {
        return new self(
            sprintf('[DocVault] Document [%s] was not found. ', $id)
            .'Why: it does not exist, was soft-deleted, or is outside the current workspace. '
            .'Fix: verify the id and the workspace filter. '
            .'Docs: '.self::docs('document-not-found'),
        );
    }
}
