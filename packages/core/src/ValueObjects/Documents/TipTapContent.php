<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Documents;

use Vaulter\Contracts\Handlers\DocumentContent;
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
 * The URL-scheme guard RIDES that seam instead of ignoring it (VLT-REMED P5.2 / R-11).
 * Every URL-bearing attribute goes through {@see LinkSchemeValidator} (http/https/mailto) at
 * store time AND on the JSON read path — and "every" includes the ones the HOST added, not
 * just `link.href` and `image.src`. It used to include only those two, which made the seam a
 * way to switch the XSS barrier OFF: `extra_attributes = ['iframe' => ['src']]` bought a node
 * whose `src` was checked nowhere, so `javascript:` was accepted at write and handed back to
 * the editor at read, while the canonical `image.src` next to it was rejected. Widening the
 * allow-list is a matter of host taste; sanitising a URL is not. See
 * {@see urlAttributes()} for how a host attribute is recognised — by name, or by declaring it
 * in `vaulter.tiptap.url_attributes`.
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
     * The unit here is the attribute set an extension SERIALIZES, not its name (VLT-REMED
     * P5.2). Syncing the canon to extension names alone left the list one attribute short and
     * reopened R-01 on the write path: ProseMirror's `toJSON()` dumps a node's WHOLE attrs bag
     * — every key the extension's `addAttributes()` declares, defaults and `null`s included —
     * so anything the extension declares but this list omits rejects the document outright.
     * Two real cases from the shipped extensions (`@tiptap/extension-link ^3`,
     * `@tiptap/starter-kit ^3`): Link declares `class` alongside href/target/rel, so ANY
     * document containing a link could not be saved; OrderedList declares `start` and `type`.
     *
     * So: when a node/mark is added here, take its attribute set from the extension's
     * `addAttributes()`, not from the subset this package's renderer happens to read.
     *
     * @var array<string, list<string>>
     */
    private const array CANONICAL_ATTRIBUTES = [
        'heading' => ['level'],
        'link' => ['href', 'target', 'rel', 'class'],
        'image' => ['src', 'alt', 'title'],
        'codeBlock' => ['language'],
        'orderedList' => ['start', 'type'],
        'tableCell' => ['colspan', 'rowspan', 'colwidth'],
        'tableHeader' => ['colspan', 'rowspan', 'colwidth'],
    ];

    /**
     * The package's own URL-bearing attributes. They are always scheme-checked AND always
     * REQUIRED: a link with no href, an image with no src is not a degraded node, it is a
     * malformed one, and the store rejects it.
     *
     * @var array<string, list<string>>
     */
    private const array CANONICAL_URL_ATTRIBUTES = ['link' => ['href'], 'image' => ['src']];

    /**
     * Attribute names that are URL-bearing by NAME, whoever declared them (VLT-REMED P5.2 /
     * R-11). This is what makes the config seam fail CLOSED.
     *
     * The seam (`extra_nodes` + `extra_attributes`) lets a host declare nodes this package
     * has never heard of — and until now the URL guard was a hardcoded map of the two
     * canonical attributes, so `extra_attributes = ['iframe' => ['src']]` bought the host a
     * node whose `src` was scheme-checked NOWHERE: not at store time, not by
     * {@see withSanitizedUrls()} on the JSON read path that feeds the editor. A
     * `javascript:` src went in and came back out. Extending the allow-list is the host's
     * business; switching off the XSS barrier is not — so an attribute a browser will
     * resolve as a URL is treated as one no matter who put it on the list, and a host that
     * never heard of `vaulter.tiptap.url_attributes` is still covered.
     *
     * The list is names, not guesses: anything a host invents outside it (`location`,
     * `endpoint`, …) it declares itself through the config seam below.
     *
     * @var list<string>
     */
    private const array INFERRED_URL_ATTRIBUTE_NAMES = [
        'href', 'src', 'url', 'poster', 'action', 'formaction', 'cite', 'background', 'longdesc', 'xlink:href',
    ];

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
     * A consumer that hands this content to a CLIENT without a renderer in between (the JSON
     * API resource, an editor payload) has no such echelon behind it and must therefore take
     * {@see withSanitizedUrls()} (VLT-REMED P5.2 / R-12).
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
     * A copy whose `link.href` / `image.src` values are guaranteed to carry an allowed
     * scheme — every other URL is DROPPED (VLT-REMED P5.2 / R-12).
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
     * {@see fromArray()} unchanged (VLT-REMED P5.2). Leaving a link mark with no href behind
     * looked like a graceful degradation and was in fact a one-way door: GET returned
     * `{"type":"link","attrs":{"target":"_blank"}}` and the PATCH of that same payload was
     * rejected by the store gate, which REQUIRES the href — every legacy document with a
     * relative href became permanently unsavable. A dropped link mark leaves its text, a
     * dropped image leaves the rest of the document, and both round-trip.
     */
    public function withSanitizedUrls(): self
    {
        return new self(self::scrubNode($this->doc) ?? ['type' => 'doc', 'content' => []]);
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
     * Every attribute of this type that carries a URL, and therefore goes through
     * {@see LinkSchemeValidator} both at store time and on the JSON read path (R-11):
     *
     *  - the package's own (`link.href`, `image.src`);
     *  - anything on the type's allow-list whose NAME is URL-bearing — including attributes
     *    the HOST added through the seam (see {@see INFERRED_URL_ATTRIBUTE_NAMES});
     *  - anything the host explicitly declares URL-bearing under a name the package cannot
     *    recognise (`vaulter.tiptap.url_attributes`).
     *
     * @return list<string>
     */
    public static function urlAttributes(string $type): array
    {
        $declared = Config::tiptapUrlAttributes()[$type] ?? [];

        $inferred = array_intersect(self::allowedAttributes($type), self::INFERRED_URL_ATTRIBUTE_NAMES);

        return array_values(array_unique([
            ...(self::CANONICAL_URL_ATTRIBUTES[$type] ?? []),
            ...$inferred,
            ...$declared,
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
     * validate the values this package actually interprets (`heading.level`) or that are a
     * security primitive — every URL-bearing attribute of the type, the host's own included
     * (R-11: the seam widens the allow-list, it does not switch off the scheme check).
     *
     * Presence is required only for the CANONICAL URL attributes: the package knows a link
     * without an href is malformed. For a host's attribute it knows no such thing, so an
     * absent one passes and a present one is checked — a value guard, not a schema opinion.
     *
     * On top of that, EVERY attribute value is checked for an executable scheme, whatever
     * the attribute is called (VLT-REMED P5.2). Recognising URL attributes by NAME
     * ({@see INFERRED_URL_ATTRIBUTE_NAMES}) leaves the config seam fail-OPEN for every name
     * the package never heard of: `extra_attributes = ['embed' => ['source']]` bought a node
     * whose `source` was scheme-checked nowhere, so `javascript:` went in and came back out
     * to the editor — the very hole R-11 was supposed to close, one attribute name to the
     * left. The name is host taste; `javascript:`/`data:`/`vbscript:` in a VALUE never is.
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

        $required = self::CANONICAL_URL_ATTRIBUTES[$type] ?? [];

        foreach (self::urlAttributes($type) as $urlAttribute) {
            if (in_array($urlAttribute, $required, true) || array_key_exists($urlAttribute, $attributes)) {
                self::validateUrl($attributes[$urlAttribute] ?? null);
            }
        }

        foreach ($attributes as $value) {
            if (self::carriesDangerousScheme($value)) {
                throw InvalidTipTapContentException::disallowedUrlScheme(is_string($value) ? $value : '(nested value)');
            }
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

        foreach ($value as $item) {
            if (self::carriesDangerousScheme($item)) {
                return true;
            }
        }

        return false;
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

    // ─── Read time: URL scrub (the JSON path's own echelon) ──────────────────

    /**
     * Recursively make a node/mark SAFE to hand to a client AND acceptable back at the store
     * gate. Structure is otherwise left alone — this is a value guard, not a second decay.
     *
     * @param  array<string, mixed>      $node a node OR a mark (both carry type/attrs)
     * @return array<string, mixed>|null null → the node/mark itself must be dropped
     */
    private static function scrubNode(array $node): ?array
    {
        $type = $node['type'] ?? null;

        if (is_string($type)) {
            /** @var array<string, mixed> $attrs */
            $attrs = isset($node['attrs']) && is_array($node['attrs']) ? $node['attrs'] : [];
            $scrubbed = self::scrubAttributes($type, $attrs);

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
                $kept = self::scrubNode($child);

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
     * handed BACK (VLT-REMED P5.2 — the read→save circle):
     *
     *  - every URL-bearing attribute of the type — the host's own included, on the same map
     *    the store-time gate uses ({@see urlAttributes()}, R-11) — must carry an allowed
     *    scheme, or it goes;
     *  - every OTHER attribute whose VALUE carries an executable scheme goes too, whatever
     *    it is called (the name heuristic's fail-open half — see {@see validateAttributes()});
     *  - a `heading.level` the store gate would reject goes as well (it has a default).
     *
     * When the attribute that cannot survive is a REQUIRED one (`link.href`, `image.src`),
     * the node/mark is dropped WHOLE rather than emitted without it. Emitting the naked node
     * was the actual R-12 defect: `fromArray()` requires those attributes, so the editor got
     * `{"type":"link","attrs":{"target":"_blank"}}` back from GET and the very next PATCH of
     * that same payload blew up — every legacy document with a relative href (`/pricing`, an
     * anchor, and yes a `javascript:` one) became PERMANENTLY unsavable. Dropping the mark
     * leaves the text; dropping the image leaves the rest of the document; both survive a
     * round-trip.
     *
     * @param  array<string, mixed>      $attrs
     * @return array<string, mixed>|null null → the node/mark must be dropped
     */
    private static function scrubAttributes(string $type, array $attrs): ?array
    {
        $required = self::CANONICAL_URL_ATTRIBUTES[$type] ?? [];

        foreach (self::urlAttributes($type) as $urlAttribute) {
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

        return $attrs;
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
