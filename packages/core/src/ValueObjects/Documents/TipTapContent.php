<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Documents;

use Vaulter\Contracts\Documents\DocumentContent;
use Vaulter\Exceptions\Documents\InvalidTipTapContentException;

/**
 * Immutable wrapper around a validated TipTap JSON document
 * ({"type":"doc","content":[...]}). The rich-text {@see DocumentContent}.
 */
final readonly class TipTapContent implements DocumentContent
{
    /**
     * Closed set of node types the documents package's TipTapRenderer knows how to
     * render (F-P1.11-02). Core has no dependency on that package (one-way, T6/D2) —
     * kept as a plain name, not an import. An unknown node type is a hard rejection
     * (fail-closed), never a "render its children and move on" fallback.
     *
     * @var list<string>
     */
    private const array ALLOWED_NODE_TYPES = [
        'doc', 'paragraph', 'heading', 'text', 'hardBreak', 'horizontalRule',
        'bulletList', 'orderedList', 'listItem', 'blockquote', 'codeBlock',
    ];

    /**
     * @var list<string>
     */
    private const array ALLOWED_MARK_TYPES = ['bold', 'italic', 'strike', 'code', 'link'];

    /**
     * Attribute allow-list per node/mark type. A type absent from this map may carry
     * no attributes at all.
     *
     * @var array<string, list<string>>
     */
    private const array ALLOWED_ATTRIBUTES = [
        'heading' => ['level'],
        'link' => ['href'],
    ];

    /**
     * @param array<string, mixed> $doc
     */
    private function __construct(
        public array $doc,
    ) {}

    /**
     * @param array<string, mixed> $doc
     */
    public static function fromArray(array $doc): self
    {
        if (($doc['type'] ?? null) !== 'doc') {
            throw InvalidTipTapContentException::missingDocNode();
        }

        self::validateChildren($doc['content'] ?? null);

        return new self($doc);
    }

    public static function empty(): self
    {
        return new self(['type' => 'doc', 'content' => []]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->doc;
    }

    public function contentHash(): string
    {
        return hash('sha256', (string) json_encode($this->doc));
    }

    public function sizeInBytes(): int
    {
        return mb_strlen((string) json_encode($this->doc), '8bit');
    }

    public function sizeInKb(): float
    {
        return $this->sizeInBytes() / 1024;
    }

    public function ensureWithin(int $maxKb): self
    {
        $size = $this->sizeInKb();

        if ($maxKb > 0 && $size > $maxKb) {
            throw InvalidTipTapContentException::tooLarge($size, $maxKb);
        }

        return $this;
    }

    /**
     * Validate a list of child nodes. Anything that is not a well-formed node array
     * is rejected the same as an unknown node type — a malformed entry never renders
     * as "no children".
     */
    private static function validateChildren(mixed $content): void
    {
        if ($content === null) {
            return;
        }

        if (!is_array($content)) {
            throw InvalidTipTapContentException::unknownNodeType('(non-array content)');
        }

        foreach ($content as $node) {
            if (!is_array($node)) {
                throw InvalidTipTapContentException::unknownNodeType('(non-object node)');
            }

            /** @var array<string, mixed> $node */
            self::validateNode($node);
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function validateNode(array $node): void
    {
        $type = $node['type'] ?? null;

        if (!is_string($type) || !in_array($type, self::ALLOWED_NODE_TYPES, true)) {
            throw InvalidTipTapContentException::unknownNodeType(is_string($type) ? $type : '(missing)');
        }

        self::validateAttributes($type, $node['attrs'] ?? null);

        if ($type === 'text') {
            self::validateMarks($node['marks'] ?? null);

            return;
        }

        self::validateChildren($node['content'] ?? null);
    }

    private static function validateMarks(mixed $marks): void
    {
        if ($marks === null) {
            return;
        }

        if (!is_array($marks)) {
            throw InvalidTipTapContentException::unknownMarkType('(non-array marks)');
        }

        foreach ($marks as $mark) {
            if (!is_array($mark)) {
                throw InvalidTipTapContentException::unknownMarkType('(non-object mark)');
            }

            $type = $mark['type'] ?? null;

            if (!is_string($type) || !in_array($type, self::ALLOWED_MARK_TYPES, true)) {
                throw InvalidTipTapContentException::unknownMarkType(is_string($type) ? $type : '(missing)');
            }

            /** @var array<string, mixed> $mark */
            self::validateAttributes($type, $mark['attrs'] ?? null);
        }
    }

    /**
     * Reject any attribute outside the closed allow-list for the given node/mark
     * type, then validate the values of the attributes this package actually
     * interprets (`heading.level`, `link.href`).
     */
    private static function validateAttributes(string $type, mixed $attrs): void
    {
        $attributes = is_array($attrs) ? $attrs : [];
        $allowed = self::ALLOWED_ATTRIBUTES[$type] ?? [];

        foreach (array_keys($attributes) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw InvalidTipTapContentException::disallowedAttribute($type, is_string($key) ? $key : '(non-string key)');
            }
        }

        if ($type === 'link') {
            self::validateHref($attributes['href'] ?? null);
        }

        if ($type === 'heading' && array_key_exists('level', $attributes) && !is_numeric($attributes['level'])) {
            throw InvalidTipTapContentException::disallowedAttribute('heading', 'level');
        }
    }

    private static function validateHref(mixed $href): void
    {
        if (!is_string($href) || !LinkSchemeValidator::isAllowed($href)) {
            throw InvalidTipTapContentException::disallowedUrlScheme(is_string($href) ? $href : '(non-string)');
        }
    }
}
