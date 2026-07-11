<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Documents;

use Vaulter\Contracts\Documents\DocumentContent;
use Vaulter\Exceptions\Documents\InvalidTipTapContentException;
use Vaulter\Support\Config;

/**
 * Immutable wrapper around a validated TipTap JSON document
 * ({"type":"doc","content":[...]}). The rich-text {@see DocumentContent}.
 *
 * WRITE and READ are deliberately DIFFERENT contracts (VLT-REMED D29):
 *
 *  - {@see fromArray()} — STORE time. Strict, fail-closed: an unknown node/mark, an
 *    attribute outside the allow-list, or a disallowed `href`/`src` scheme REJECTS the
 *    whole document (F-P1.11-01/-02). Garbage never enters the store.
 *  - {@see decayFrom()} — READ time. Lenient: unknown nodes/marks/attribute KEYS are
 *    STRIPPED and a valid doc always comes back (never throws — the documents package's
 *    NodeDocumentVersion::tipTapContent() promises exactly this; core has no dependency on
 *    that package, one-way T6/D2, so it is named as plain text, never imported). One legacy
 *    snapshot must not take down preview, export and the whole listing UI.
 *
 * The allow-list is the set of nodes/marks the PACKAGE ITSELF offers the editor through
 * `#[TiptapExtensions]` on DocumentType (StarterKit + Image + Table + Link + CodeBlock) —
 * anything less is a denial of service on the package's own feature set (VLT-REMED R-01 /
 * D27). It is EXTENSIBLE by the host, but ADDITIVELY only (D28): the canon below is the
 * floor and config can never remove from it — a host that accidentally dropped `doc`/`text`
 * would silently break every save.
 *
 * The URL-scheme guard is NOT part of that seam: `link.href` and `image.src` always go
 * through {@see LinkSchemeValidator} (http/https/mailto), whatever the config says. That is
 * a security invariant, not a matter of host taste.
 */
final readonly class TipTapContent implements DocumentContent
{
    /**
     * Canonical node types — the baseline every install has, matching what the package
     * declares to the editor via `#[TiptapExtensions]` (VLT-REMED D27). A host adding an
     * extension of its own extends this through `vaulter.tiptap.extra_nodes` (D28).
     *
     * @var list<string>
     */
    private const array CANONICAL_NODE_TYPES = [
        // StarterKit
        'doc', 'paragraph', 'heading', 'text', 'hardBreak', 'horizontalRule',
        'bulletList', 'orderedList', 'listItem', 'blockquote', 'codeBlock',
        // Image
        'image',
        // Table
        'table', 'tableRow', 'tableCell', 'tableHeader',
    ];

    /**
     * Canonical mark types (StarterKit — which since Tiptap 3 ships Underline and Link —
     * plus the explicitly declared Link extension).
     *
     * @var list<string>
     */
    private const array CANONICAL_MARK_TYPES = ['bold', 'italic', 'strike', 'code', 'link', 'underline'];

    /**
     * Attribute allow-list per node/mark type. A type absent from this map may carry no
     * attributes at all. Attribute VALUES are only interpreted where this package needs
     * them (`heading.level`) or where they are a security primitive (`link.href`,
     * `image.src` — URL schemes).
     *
     * @var array<string, list<string>>
     */
    private const array CANONICAL_ATTRIBUTES = [
        'heading' => ['level'],
        'link' => ['href', 'target', 'rel'],
        'image' => ['src', 'alt', 'title'],
        'codeBlock' => ['language'],
        'tableCell' => ['colspan', 'rowspan', 'colwidth'],
        'tableHeader' => ['colspan', 'rowspan', 'colwidth'],
    ];

    /**
     * The node/mark attributes that carry a URL. They are ALWAYS scheme-checked, and the
     * config seam can never widen this (D28).
     *
     * @var array<string, string>
     */
    private const array URL_ATTRIBUTES = ['link' => 'href', 'image' => 'src'];

    /**
     * @param array<string, mixed> $doc
     */
    private function __construct(
        public array $doc,
    ) {}

    /**
     * STORE-time constructor: strict, fail-closed (F-P1.11-01/-02).
     *
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

    /**
     * READ-time constructor: DECAYS instead of throwing (VLT-REMED D29 / R-01).
     *
     * A snapshot written before today's allow-list existed (or by a host extension that
     * has since been switched off) is read back with the unknown nodes/marks/attribute
     * KEYS stripped out — the document degrades, it does not explode.
     *
     * What is deliberately NOT touched here are attribute VALUES: a legacy
     * `href="javascript:…"` is carried through to the renderer, where the render-time HTML
     * sanitizer (the second echelon of D4) neutralises it. Scrubbing it here would leave
     * that echelon with nothing to catch and make it untestable (VLT-REMED R-07) — and the
     * Markdown path re-applies {@see LinkSchemeValidator} on its own.
     *
     * @param array<string, mixed> $doc
     */
    public static function decayFrom(array $doc): self
    {
        if (($doc['type'] ?? null) !== 'doc') {
            return self::empty();
        }

        return new self([
            'type' => 'doc',
            'content' => self::decayChildren($doc['content'] ?? null),
        ]);
    }

    public static function empty(): self
    {
        return new self(['type' => 'doc', 'content' => []]);
    }

    /**
     * The node types accepted at store time: the canon plus whatever the host's own
     * extensions add (`vaulter.tiptap.extra_nodes`). Additive only (D28).
     *
     * @return list<string>
     */
    public static function allowedNodeTypes(): array
    {
        return array_values(array_unique([...self::CANONICAL_NODE_TYPES, ...Config::tiptapExtraNodes()]));
    }

    /**
     * @return list<string>
     */
    public static function allowedMarkTypes(): array
    {
        return array_values(array_unique([...self::CANONICAL_MARK_TYPES, ...Config::tiptapExtraMarks()]));
    }

    /**
     * The attributes accepted for a node/mark type: the canon MERGED with the host's
     * additions for that type (never replaced — D28).
     *
     * @return list<string>
     */
    public static function allowedAttributes(string $type): array
    {
        return array_values(array_unique([
            ...(self::CANONICAL_ATTRIBUTES[$type] ?? []),
            ...(Config::tiptapExtraAttributes()[$type] ?? []),
        ]));
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

    // ─── Store time: strict ──────────────────────────────────────────────────

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

        if (!is_string($type) || !in_array($type, self::allowedNodeTypes(), true)) {
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

            if (!is_string($type) || !in_array($type, self::allowedMarkTypes(), true)) {
                throw InvalidTipTapContentException::unknownMarkType(is_string($type) ? $type : '(missing)');
            }

            /** @var array<string, mixed> $mark */
            self::validateAttributes($type, $mark['attrs'] ?? null);
        }
    }

    /**
     * Reject any attribute outside the allow-list for the given node/mark type, then
     * validate the values this package actually interprets (`heading.level`) or that are
     * a security primitive (`link.href`, `image.src`).
     */
    private static function validateAttributes(string $type, mixed $attrs): void
    {
        $attributes = is_array($attrs) ? $attrs : [];
        $allowed = self::allowedAttributes($type);

        foreach (array_keys($attributes) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw InvalidTipTapContentException::disallowedAttribute($type, is_string($key) ? $key : '(non-string key)');
            }
        }

        if (array_key_exists($type, self::URL_ATTRIBUTES)) {
            self::validateUrl($attributes[self::URL_ATTRIBUTES[$type]] ?? null);
        }

        if ($type === 'heading' && array_key_exists('level', $attributes) && !is_numeric($attributes['level'])) {
            throw InvalidTipTapContentException::disallowedAttribute('heading', 'level');
        }
    }

    private static function validateUrl(mixed $url): void
    {
        if (!is_string($url) || !LinkSchemeValidator::isAllowed($url)) {
            throw InvalidTipTapContentException::disallowedUrlScheme(is_string($url) ? $url : '(non-string)');
        }
    }

    // ─── Read time: decay ────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    private static function decayChildren(mixed $content): array
    {
        if (!is_array($content)) {
            return [];
        }

        $kept = [];

        foreach ($content as $node) {
            if (!is_array($node)) {
                continue;
            }

            /** @var array<string, mixed> $node */
            $decayed = self::decayNode($node);

            if ($decayed !== null) {
                $kept[] = $decayed;
            }
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>      $node
     * @return array<string, mixed>|null null → the node is unknown and is dropped
     */
    private static function decayNode(array $node): ?array
    {
        $type = $node['type'] ?? null;

        if (!is_string($type) || !in_array($type, self::allowedNodeTypes(), true)) {
            return null;
        }

        $decayed = ['type' => $type];

        $attrs = self::decayAttributes($type, $node['attrs'] ?? null);

        if ($attrs !== []) {
            $decayed['attrs'] = $attrs;
        }

        if ($type === 'text') {
            $decayed['text'] = is_string($node['text'] ?? null) ? $node['text'] : '';

            $marks = self::decayMarks($node['marks'] ?? null);

            if ($marks !== []) {
                $decayed['marks'] = $marks;
            }

            return $decayed;
        }

        if (array_key_exists('content', $node)) {
            $decayed['content'] = self::decayChildren($node['content']);
        }

        return $decayed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function decayMarks(mixed $marks): array
    {
        if (!is_array($marks)) {
            return [];
        }

        $kept = [];

        foreach ($marks as $mark) {
            if (!is_array($mark)) {
                continue;
            }

            $type = $mark['type'] ?? null;

            if (!is_string($type) || !in_array($type, self::allowedMarkTypes(), true)) {
                continue;
            }

            $decayed = ['type' => $type];

            /** @var array<string, mixed> $mark */
            $attrs = self::decayAttributes($type, $mark['attrs'] ?? null);

            if ($attrs !== []) {
                $decayed['attrs'] = $attrs;
            }

            $kept[] = $decayed;
        }

        return $kept;
    }

    /**
     * Keep only the attribute KEYS the type is allowed to carry. VALUES are passed through
     * untouched on purpose (D29 — see {@see decayFrom()}).
     *
     * @return array<string, mixed>
     */
    private static function decayAttributes(string $type, mixed $attrs): array
    {
        if (!is_array($attrs)) {
            return [];
        }

        $allowed = self::allowedAttributes($type);
        $kept = [];

        foreach ($attrs as $key => $value) {
            if (is_string($key) && in_array($key, $allowed, true)) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }
}
