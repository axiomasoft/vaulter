<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api]
final class InvalidDocumentContentException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const string PROBLEM_CODE = 'invalid_document_content';

    private const int HTTP_STATUS = 422;

    private string $reason = 'invalid_tiptap';

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
            .'Why: it exceeds config(vaulter-documents.content.max_size_kb). '
            .'Fix: split the document or raise the limit. '
            .'Docs: '.self::docs('content-too-large'),
        );
    }

    public static function tooLargeBytes(int $sizeBytes, int $maxBytes): self
    {
        $exception = new self(
            sprintf('[Vaulter] Document content is too large (%d bytes > %d bytes). ', $sizeBytes, $maxBytes)
            .'Why: it exceeds the limit of its document type or config(vaulter-documents.content.max_size_kb). '
            .'Fix: split the document or raise the limit. '
            .'Docs: '.self::docs('content-too-large'),
        );
        $exception->reason = 'too_large';

        return $exception;
    }

    /** A collaboration store without the JSON projection a type cannot derive from its state. */
    public static function missingProjection(string $type): self
    {
        $exception = new self(
            sprintf('[Vaulter] The collaboration store for a "%s" document carries no JSON content. ', $type)
            .'Why: this type does not project its JSON from the collaboration state, so the hub must send it. '
            .'Fix: send `content` (TiptapTransformer.fromYdoc) with every store. '
            .'Docs: '.self::docs('invalid-document-content'),
        );
        $exception->reason = 'missing_projection';

        return $exception;
    }

    public static function notAnObject(): self
    {
        $exception = new self(
            '[Vaulter] Invalid document content. '
            .'Why: the content of this document type must be a JSON object. '
            .'Fix: send an object ({...}), not a list or a scalar. '
            .'Docs: '.self::docs('invalid-document-content'),
        );
        $exception->reason = 'invalid_json';

        return $exception;
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

    public static function malformedNode(string $nodeType, string $field): self
    {
        return new self(
            sprintf('[Vaulter] Invalid TipTap content: field "%s" of node "%s" is not TipTap JSON. ', $field, $nodeType)
            .'Why: a node carries only type, attrs (an object), marks and content, or a string text on a text node; anything else would be lost on the next read. '
            .'Fix: send the editor\'s getJSON() output unchanged. '
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
    public function problemDetails(): array
    {
        return ['reason' => $this->reason];
    }
}
