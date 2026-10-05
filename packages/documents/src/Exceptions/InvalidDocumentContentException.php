<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

final class InvalidDocumentContentException extends VaulterException implements ProblemException
{
    private const string PROBLEM_CODE = 'invalid_document_content';

    private const int HTTP_STATUS = 422;

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

    public static function unknownNodeType(string $type): self
    {
        return new self(
            sprintf('[Vaulter] Invalid TipTap content: unknown node type "%s". ', $type)
            .'Why: the store-time validator only accepts a closed set of node types. '
            .'Fix: use one of the supported TipTap nodes, or extend the allow-list deliberately. '
            .'Docs: '.self::docs('invalid-tiptap-content'),
        );
    }

    public static function unknownMarkType(string $type): self
    {
        return new self(
            sprintf('[Vaulter] Invalid TipTap content: unknown mark type "%s". ', $type)
            .'Why: the store-time validator only accepts a closed set of marks. '
            .'Fix: use one of the supported TipTap marks, or extend the allow-list deliberately. '
            .'Docs: '.self::docs('invalid-tiptap-content'),
        );
    }

    public static function disallowedAttribute(string $nodeType, string $attribute): self
    {
        return new self(
            sprintf('[Vaulter] Invalid TipTap content: attribute "%s" is not allowed on node/mark "%s". ', $attribute, $nodeType)
            .'Why: the store-time validator rejects attributes outside the closed allow-list per type. '
            .'Fix: remove the attribute, or extend the allow-list deliberately. '
            .'Docs: '.self::docs('invalid-tiptap-content'),
        );
    }

    public static function disallowedUrlScheme(string $href): self
    {
        return new self(
            sprintf('[Vaulter] Invalid TipTap content: link href "%s" uses a disallowed URL scheme. ', $href)
            .'Why: only http, https and mailto schemes are allowed (F-P1.11-01, stored XSS). '
            .'Fix: use an http(s)/mailto link. '
            .'Docs: '.self::docs('invalid-tiptap-content'),
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

    /**
     * @return array<string, mixed>
     */
    protected function renderExtras(): array
    {
        return ['reason' => 'invalid_tiptap'];
    }
}
