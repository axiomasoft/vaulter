<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Documents;

use Vaulter\Exceptions\VaulterException;

final class DocumentNotFoundException extends VaulterException
{
    public static function withId(int|string $id): self
    {
        return new self(
            sprintf('[Vaulter] Document [%s] was not found. ', $id)
            .'Why: it does not exist, was soft-deleted, or is outside the current workspace. '
            .'Fix: verify the id and the workspace filter. '
            .'Docs: '.self::docs('document-not-found'),
        );
    }
}
