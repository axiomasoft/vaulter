<?php

declare(strict_types=1);

namespace DocVault\Exceptions;

final class InvalidTipTapContentException extends DocVaultException
{
    public static function missingDocNode(): self
    {
        return new self(
            '[DocVault] Invalid TipTap content. '
            .'Why: the root node must be {"type":"doc", ...}. '
            .'Fix: pass a TipTap JSON document, or use TipTapContent::empty(). '
            .'Docs: '.self::docs('invalid-tiptap-content'),
        );
    }

    public static function tooLarge(float $sizeKb, int $maxKb): self
    {
        $size = number_format($sizeKb, 1);

        return new self(
            sprintf('[DocVault] Document content is too large (%s KB > %d KB). ', $size, $maxKb)
            .'Why: it exceeds config(docvault.max_document_size_kb). '
            .'Fix: split the document or raise the limit. '
            .'Docs: '.self::docs('content-too-large'),
        );
    }
}
