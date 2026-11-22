<?php

declare(strict_types=1);

namespace Vaulter\Documents\Content\TipTap;

use Packman\Attributes\Api;
use Vaulter\Documents\Content\ContentHasher;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;

/**
 * Immutable wrapper around a validated TipTap JSON document
 * ({"type":"doc","content":[...]}), checked against a {@see TipTapSchema}
 * (rich text by default).
 *
 * WRITE and READ are deliberately DIFFERENT contracts:
 *
 *  - {@see fromArray()} — STORE time. Strict, fail-closed: an unknown node/mark, an
 *    attribute outside the allow-list, a field that is not part of a TipTap node, or a
 *    disallowed `href`/`src` scheme REJECTS the whole document. Garbage never enters the
 *    store, and whatever it accepts reads back without loss.
 *  - {@see decayFrom()} / {@see decayReporting()} — READ time. Lenient: unknown
 *    nodes/marks/attribute KEYS are STRIPPED and a valid doc always comes back (never
 *    throws). One legacy snapshot must not take down preview, export and the whole
 *    listing UI. {@see decayReporting()} also says whether anything was stripped: such
 *    content is shown but must not be saved back, or the save would delete the nodes of
 *    an editor extension that is merely switched off.
 *
 * The URL-scheme guard RIDES the schema's extension seam instead of ignoring it. Every
 * URL-bearing attribute goes through {@see LinkSchemeValidator} (http/https/mailto) at
 * store time AND on the JSON read path — and "every" includes the ones the HOST added,
 * not just `link.href` and `image.src`. Widening the allow-list is a matter of host
 * taste; sanitising a URL is not. See {@see TipTapSchema::urlAttributes()} for how a
 * host attribute is recognised.
 */
#[Api]
final readonly class TipTapContent implements DocumentContent
{
    /**
     * @param array<string, mixed> $doc
     */
    private function __construct(
        public array $doc,
    ) {}

    /**
     * STORE-time constructor: strict, fail-closed (02).
     *
     * @param array<string, mixed> $doc
     */
    public static function fromArray(array $doc, ?TipTapSchema $schema = null): self
    {
        if (($doc['type'] ?? null) !== 'doc') {
            throw InvalidDocumentContentException::missingDocNode();
        }

        self::validateNode($schema ?? TipTapSchema::richText(), $doc);
        BlockIdentity::assertUnique($doc);

        return new self($doc);
    }

    /**
     * READ-time constructor: DECAYS instead of throwing.
     *
     * A snapshot written before today's allow-list existed (or by a host extension that
     * has since been switched off) is read back with the unknown nodes/marks/attribute
     * KEYS stripped out — the document degrades, it does not explode.
     *
     * What is deliberately NOT touched here are attribute VALUES: a legacy
     * `href="javascript:…"` is carried through to the renderer, where the render-time HTML
     * sanitizer (the second echelon of) neutralises it. Scrubbing it here would leave
     * that echelon with nothing to catch and make it untestable — and the
     * Markdown path re-applies {@see LinkSchemeValidator} on its own.
     *
     * A consumer that hands this content to a CLIENT without a renderer in between (the JSON
     * API resource, an editor payload) has no such echelon behind it and must therefore take
     * {@see withSanitizedUrls()}.
     *
     * @param array<string, mixed> $doc
     */
    public static function decayFrom(array $doc, ?TipTapSchema $schema = null): self
    {
        return self::decayReporting($doc, $schema)[0];
    }

    /**
     * {@see decayFrom()}, plus whether the result kept everything the stored document
     * meant: false as soon as a node, a mark, an attribute key, a field that is not part
     * of a TipTap node or a malformed entry was dropped (or the root was not a doc at
     * all). Normalisations that lose nothing — an empty attrs bag, a text node without
     * text — do not count.
     *
     * @param  array<string, mixed>    $doc
     * @return array{0: self, 1: bool}
     */
    public static function decayReporting(array $doc, ?TipTapSchema $schema = null): array
    {
        if (($doc['type'] ?? null) !== 'doc') {
            return [self::empty(), false];
        }

        $lossless = true;
        $root = self::decayNode($schema ?? TipTapSchema::richText(), $doc, $lossless) ?? ['type' => 'doc'];
        $root['content'] ??= [];

        return [new self($root), $lossless];
    }

    public static function empty(): self
    {
        return new self(['type' => 'doc', 'content' => []]);
    }

    /**
     * A copy whose `link.href` / `image.src` values are guaranteed to carry an allowed
     * scheme — every other URL is DROPPED.
     *
     * This is the echelon for the JSON read path (the API resource that hands the stored
     * content straight to the editor). {@see decayFrom()} deliberately leaves attribute
     * VALUES alone because the HTML renderer has `symfony/html-sanitizer` behind it — but
     * the JSON path has NO renderer: whatever it returns is mounted by the client as-is,
     * so a legacy `javascript:` href would reach the DOM through the host's own editor. The
     * scheme check is {@see LinkSchemeValidator} (the same one the store-time gate uses), so
     * a control-character split like `java\tscript:` is caught with it.
     *
     * What cannot survive is dropped WITH its node/mark when the attribute is a required one
     * (`link.href`, `image.src`): the result of this method is what the API resource hands the
     * editor, and the editor hands it straight back on the next save — so it must pass
     * {@see fromArray()} unchanged. Leaving a link mark with no href behind
     * looked like a graceful degradation and was in fact a one-way door: GET returned
     * `{"type":"link","attrs":{"target":"_blank"}}` and the PATCH of that same payload was
     * rejected by the store gate, which REQUIRES the href — every legacy document with a
     * relative href became permanently unsavable. A dropped link mark leaves its text, a
     * dropped image leaves the rest of the document, and both round-trip.
     */
    public function withSanitizedUrls(): self
    {
        return new self(self::scrubNode(TipTapSchema::richText(), $this->doc) ?? ['type' => 'doc', 'content' => []]);
    }

    /**
     * The node types the rich-text schema accepts at store time.
     *
     * @return list<string>
     */
    public static function allowedNodeTypes(): array
    {
        return TipTapSchema::richText()->nodeTypes();
    }

    /**
     * @return list<string>
     */
    public static function allowedMarkTypes(): array
    {
        return TipTapSchema::richText()->markTypes();
    }

    /**
     * @return list<string>
     */
    public static function allowedAttributes(string $type): array
    {
        return TipTapSchema::richText()->attributes($type);
    }

    /**
     * @return list<string>
     */
    public static function urlAttributes(string $type): array
    {
        return TipTapSchema::richText()->urlAttributes($type);
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
        return ContentHasher::hash($this->doc);
    }

    public function sizeInBytes(): int
    {
        return ContentHasher::size($this->doc);
    }

    public function sizeInKb(): float
    {
        return $this->sizeInBytes() / 1024;
    }

    public function ensureWithin(int $maxKb): self
    {
        $size = $this->sizeInKb();

        if ($maxKb > 0 && $size > $maxKb) {
            throw InvalidDocumentContentException::tooLarge($size, $maxKb);
        }

        return $this;
    }

    // ─── The shape of a node, shared by both paths ───────────────────────────

    /**
     * The fields a TipTap node of $type carries. Marks may sit on any node, as in
     * ProseMirror JSON: an inline node inherits the marks around it.
     *
     * @return list<string>
     */
    private static function nodeFields(string $type): array
    {
        return $type === 'text' ? ['type', 'attrs', 'marks', 'text'] : ['type', 'attrs', 'marks', 'content'];
    }

    /** A value that means nothing (null, [] or ''), so dropping it loses nothing. */
    private static function blank(mixed $value): bool
    {
        return in_array($value, [null, [], ''], true);
    }

    // ─── Store time: strict ──────────────────────────────────────────────────

    /**
     * Validate a list of child nodes. Anything that is not a well-formed node array
     * is rejected the same as an unknown node type — a malformed entry never renders
     * as "no children".
     */
    private static function validateChildren(TipTapSchema $schema, mixed $content): void
    {
        if ($content === null) {
            return;
        }

        if (!is_array($content)) {
            throw InvalidDocumentContentException::unknownNodeType('(non-array content)');
        }

        foreach ($content as $node) {
            if (!is_array($node)) {
                throw InvalidDocumentContentException::unknownNodeType('(non-object node)');
            }

            /** @var array<string, mixed> $node */
            self::validateNode($schema, $node);
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function validateNode(TipTapSchema $schema, array $node): void
    {
        $type = $node['type'] ?? null;

        if (!is_string($type) || !$schema->allowsNode($type)) {
            throw InvalidDocumentContentException::unknownNodeType(is_string($type) ? $type : '(missing)');
        }

        foreach ($node as $field => $value) {
            if (!in_array($field, self::nodeFields($type), true) && !self::blank($value)) {
                throw InvalidDocumentContentException::malformedNode($type, $field);
            }
        }

        self::validateAttributes($schema, $type, $node['attrs'] ?? null);
        self::validateMarks($schema, $node['marks'] ?? null);

        if ($type === 'text') {
            if (!is_string($node['text'] ?? '')) {
                throw InvalidDocumentContentException::malformedNode($type, 'text');
            }

            return;
        }

        self::validateChildren($schema, $node['content'] ?? null);
    }

    private static function validateMarks(TipTapSchema $schema, mixed $marks): void
    {
        if ($marks === null) {
            return;
        }

        if (!is_array($marks)) {
            throw InvalidDocumentContentException::unknownMarkType('(non-array marks)');
        }

        foreach ($marks as $mark) {
            if (!is_array($mark)) {
                throw InvalidDocumentContentException::unknownMarkType('(non-object mark)');
            }

            $type = $mark['type'] ?? null;

            if (!is_string($type) || !$schema->allowsMark($type)) {
                throw InvalidDocumentContentException::unknownMarkType(is_string($type) ? $type : '(missing)');
            }

            /** @var array<string, mixed> $mark */
            self::validateAttributes($schema, $type, $mark['attrs'] ?? null);
        }
    }

    /**
     * Reject any attribute outside the allow-list for the given node/mark type, then
     * validate the values this package actually interprets (`heading.level`) or that are a
     * security primitive — every URL-bearing attribute of the type, the host's own included
     * (the seam widens the allow-list, it does not switch off the scheme check).
     *
     * Presence is required only for the CANONICAL URL attributes: the package knows a link
     * without an href is malformed. For a host's attribute it knows no such thing, so an
     * absent one passes and a present one is checked — a value guard, not a schema opinion.
     *
     * On top of that, EVERY attribute value is checked for an executable scheme, whatever
     * the attribute is called. Recognising URL attributes by NAME
     * ({@see INFERRED_URL_ATTRIBUTE_NAMES}) leaves the config seam fail-OPEN for every name
     * the package never heard of: `extra_attributes = ['embed' => ['source']]` bought a node
     * whose `source` was scheme-checked nowhere, so `javascript:` went in and came back out
     * to the editor — the very hole was supposed to close, one attribute name to the
     * left. The name is host taste; `javascript:`/`data:`/`vbscript:` in a VALUE never is.
     */
    private static function validateAttributes(TipTapSchema $schema, string $type, mixed $attrs): void
    {
        if (!is_array($attrs) && !self::blank($attrs)) {
            throw InvalidDocumentContentException::malformedNode($type, 'attrs');
        }

        $attributes = is_array($attrs) ? $attrs : [];
        $allowed = $schema->attributes($type);

        foreach (array_keys($attributes) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw InvalidDocumentContentException::disallowedAttribute($type, is_string($key) ? $key : '(non-string key)');
            }
        }

        $required = TipTapSchema::REQUIRED_URL_ATTRIBUTES[$type] ?? [];

        foreach ($schema->urlAttributes($type) as $urlAttribute) {
            if (in_array($urlAttribute, $required, true) || array_key_exists($urlAttribute, $attributes)) {
                self::validateUrl($attributes[$urlAttribute] ?? null);
            }
        }

        foreach ($attributes as $value) {
            if (self::carriesDangerousScheme($value)) {
                throw InvalidDocumentContentException::disallowedUrlScheme(is_string($value) ? $value : '(nested value)');
            }
        }

        if ($type === 'heading' && array_key_exists('level', $attributes) && !is_numeric($attributes['level'])) {
            throw InvalidDocumentContentException::disallowedAttribute('heading', 'level');
        }

        if (BlockIdentity::isAddressable($type)
            && array_key_exists(BlockIdentity::ATTR_KEY, $attributes)
            && $attributes[BlockIdentity::ATTR_KEY] !== null
            && !BlockIdentity::isValid($attributes[BlockIdentity::ATTR_KEY])) {
            throw InvalidDocumentContentException::disallowedAttribute($type, BlockIdentity::ATTR_KEY);
        }
    }

    private static function validateUrl(mixed $url): void
    {
        if (!is_string($url) || !LinkSchemeValidator::isAllowed($url)) {
            throw InvalidDocumentContentException::disallowedUrlScheme(is_string($url) ? $url : '(non-string)');
        }
    }

    /**
     * Does this attribute value (a string, or any string nested in a list-valued attribute
     * like `tableCell.colwidth`) carry an executable scheme? See
     * {@see LinkSchemeValidator::isDangerous()} — the guard that does not depend on the
     * attribute's NAME.
     */
    private static function carriesDangerousScheme(mixed $value): bool
    {
        if (is_string($value)) {
            return LinkSchemeValidator::isDangerous($value);
        }

        if (!is_array($value)) {
            return false;
        }

        return array_any($value, fn ($item): bool => self::carriesDangerousScheme($item));
    }

    // ─── Read time: decay ────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    private static function decayChildren(TipTapSchema $schema, mixed $content, bool &$lossless): array
    {
        if (!is_array($content)) {
            if ($content !== null) {
                $lossless = false;
            }

            return [];
        }

        $kept = [];

        foreach ($content as $node) {
            if (!is_array($node)) {
                $lossless = false;

                continue;
            }

            /** @var array<string, mixed> $node */
            $decayed = self::decayNode($schema, $node, $lossless);

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
    private static function decayNode(TipTapSchema $schema, array $node, bool &$lossless): ?array
    {
        $type = $node['type'] ?? null;

        if (!is_string($type) || !$schema->allowsNode($type)) {
            $lossless = false;

            return null;
        }

        foreach ($node as $field => $value) {
            if (!in_array($field, self::nodeFields($type), true) && !self::blank($value)) {
                $lossless = false;
            }
        }

        $decayed = ['type' => $type];

        $attrs = self::decayAttributes($schema, $type, $node['attrs'] ?? null, $lossless);

        if ($attrs !== []) {
            $decayed['attrs'] = $attrs;
        }

        if ($type === 'text') {
            $text = $node['text'] ?? null;
            if (!is_string($text) && $text !== null) {
                $lossless = false;
            }
            $decayed['text'] = is_string($text) ? $text : '';
        }

        $marks = self::decayMarks($schema, $node['marks'] ?? null, $lossless);

        if ($marks !== []) {
            $decayed['marks'] = $marks;
        }

        if ($type !== 'text' && array_key_exists('content', $node)) {
            $decayed['content'] = self::decayChildren($schema, $node['content'], $lossless);
        }

        return $decayed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function decayMarks(TipTapSchema $schema, mixed $marks, bool &$lossless): array
    {
        if (!is_array($marks)) {
            if ($marks !== null) {
                $lossless = false;
            }

            return [];
        }

        $kept = [];

        foreach ($marks as $mark) {
            if (!is_array($mark)) {
                $lossless = false;

                continue;
            }

            $type = $mark['type'] ?? null;

            if (!is_string($type) || !$schema->allowsMark($type)) {
                $lossless = false;

                continue;
            }

            $decayed = ['type' => $type];

            /** @var array<string, mixed> $mark */
            $attrs = self::decayAttributes($schema, $type, $mark['attrs'] ?? null, $lossless);

            if ($attrs !== []) {
                $decayed['attrs'] = $attrs;
            }

            $kept[] = $decayed;
        }

        return $kept;
    }

    // ─── Read time: URL scrub (the JSON path's own echelon) ──────────────────

    /**
     * Recursively make a node/mark SAFE to hand to a client AND acceptable back at the store
     * gate. Structure is otherwise left alone — this is a value guard, not a second decay.
     *
     * @param  array<string, mixed>      $node a node OR a mark (both carry type/attrs)
     * @return array<string, mixed>|null null → the node/mark itself must be dropped
     */
    private static function scrubNode(TipTapSchema $schema, array $node): ?array
    {
        $type = $node['type'] ?? null;

        if (is_string($type)) {
            /** @var array<string, mixed> $attrs */
            $attrs = isset($node['attrs']) && is_array($node['attrs']) ? $node['attrs'] : [];
            $scrubbed = self::scrubAttributes($schema, $type, $attrs);

            if ($scrubbed === null) {
                return null;
            }

            if ($scrubbed === []) {
                unset($node['attrs']);
            } else {
                $node['attrs'] = $scrubbed;
            }
        }

        foreach (['marks', 'content'] as $key) {
            if (!isset($node[$key]) || !is_array($node[$key])) {
                continue;
            }

            $scrubbed = [];

            foreach ($node[$key] as $child) {
                if (!is_array($child)) {
                    continue;
                }

                /** @var array<string, mixed> $child */
                $kept = self::scrubNode($schema, $child);

                if ($kept !== null) {
                    $scrubbed[] = $kept;
                }
            }

            if ($scrubbed === [] && $key === 'marks') {
                unset($node[$key]);

                continue;
            }

            $node[$key] = $scrubbed;
        }

        return $node;
    }

    /**
     * Scheme-guard the attributes of one node/mark, so that what the editor is handed can be
     * handed BACK (the read→save circle):
     *
     *  - every URL-bearing attribute of the type — the host's own included, on the same map
     *    the store-time gate uses ({@see urlAttributes()}) — must carry an allowed
     *    scheme, or it goes;
     *  - every OTHER attribute whose VALUE carries an executable scheme goes too, whatever
     *    it is called (the name heuristic's fail-open half — see {@see validateAttributes()});
     *  - a `heading.level` the store gate would reject goes as well (it has a default).
     *
     * When the attribute that cannot survive is a REQUIRED one (`link.href`, `image.src`),
     * the node/mark is dropped WHOLE rather than emitted without it. Emitting the naked node
     * was the actual defect: `fromArray()` requires those attributes, so the editor got
     * `{"type":"link","attrs":{"target":"_blank"}}` back from GET and the very next PATCH of
     * that same payload blew up — every legacy document with a relative href (`/pricing`, an
     * anchor, and yes a `javascript:` one) became PERMANENTLY unsavable. Dropping the mark
     * leaves the text; dropping the image leaves the rest of the document; both survive a
     * round-trip.
     *
     * @param  array<string, mixed>      $attrs
     * @return array<string, mixed>|null null → the node/mark must be dropped
     */
    private static function scrubAttributes(TipTapSchema $schema, string $type, array $attrs): ?array
    {
        $required = TipTapSchema::REQUIRED_URL_ATTRIBUTES[$type] ?? [];

        foreach ($schema->urlAttributes($type) as $urlAttribute) {
            $isRequired = in_array($urlAttribute, $required, true);
            $url = $attrs[$urlAttribute] ?? null;
            $safe = is_string($url) ? LinkSchemeValidator::sanitize($url) : null;

            if ($safe === null) {
                if ($isRequired) {
                    return null;
                }

                unset($attrs[$urlAttribute]);

                continue;
            }

            $attrs[$urlAttribute] = $safe;
        }

        foreach ($attrs as $key => $value) {
            if (self::carriesDangerousScheme($value)) {
                unset($attrs[$key]);
            }
        }

        if ($type === 'heading' && array_key_exists('level', $attrs) && !is_numeric($attrs['level'])) {
            unset($attrs['level']);
        }

        if (BlockIdentity::isAddressable($type)
            && array_key_exists(BlockIdentity::ATTR_KEY, $attrs)
            && $attrs[BlockIdentity::ATTR_KEY] !== null
            && !BlockIdentity::isValid($attrs[BlockIdentity::ATTR_KEY])) {
            unset($attrs[BlockIdentity::ATTR_KEY]);
        }

        return $attrs;
    }

    /**
     * Keep only the attribute KEYS the type is allowed to carry. VALUES are passed through
     * untouched on purpose (see {@see decayFrom()}).
     *
     * @return array<string, mixed>
     */
    private static function decayAttributes(TipTapSchema $schema, string $type, mixed $attrs, bool &$lossless): array
    {
        if (!is_array($attrs)) {
            if (!self::blank($attrs)) {
                $lossless = false;
            }

            return [];
        }

        $allowed = $schema->attributes($type);
        $kept = [];

        foreach ($attrs as $key => $value) {
            if (is_string($key) && in_array($key, $allowed, true)) {
                $kept[$key] = $value;
            } else {
                $lossless = false;
            }
        }

        return $kept;
    }
}
