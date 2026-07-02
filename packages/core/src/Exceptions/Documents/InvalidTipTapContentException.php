<?php

declare(strict_types=1);

namespace Vaulter\DocDrive\Exceptions;

use Vaulter\Exceptions\VaulterException;

final class InvalidTipTapContentException extends VaulterException
{
    public static function missingDocNode(): self
    {
        return new self(
            '[Vaulter] Invalid TipTap content. '
            .'Why: the root node must be {"type":"doc", ...}. '
            .'Fix: pass a TipTap JSON document, or use TipTapContent::empty(). '
            .'Docs: '.self::docs('invalid-tiptap-content'),
        );
    }

    public static function tooLarge(float $sizeKb, int $maxKb): self
    {
        $size = number_format($sizeKb, 1);

        return new self(
            sprintf('[Vaulter] Document content is too large (%s KB > %d KB). ', $size, $maxKb)
            .'Why: it exceeds config(vaulter.max_document_size_kb). '
            .'Fix: split the document or raise the limit. '
            .'Docs: '.self::docs('content-too-large'),
        );
    }
}
