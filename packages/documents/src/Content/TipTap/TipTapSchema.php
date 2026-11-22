<?php

declare(strict_types=1);

namespace Vaulter\Documents\Content\TipTap;

use Packman\Attributes\Api;
use Vaulter\Documents\Configuration\DocumentsOptions;

/**
 * The nodes, marks and attributes one TipTap-based document type accepts.
 *
 * The rich-text schema is the set of nodes/marks the package's own editor preset
 * offers (StarterKit + Image + Table + Link + CodeBlock) — anything less is a denial
 * of service on the package's own feature set. It is EXTENSIBLE by the host, but
 * ADDITIVELY only: the canon is the floor and config can never remove from it — a
 * host that accidentally dropped `doc`/`text` would silently break every save. The
 * host additions are read from config on every call, so a changed config applies
 * without rebuilding the type.
 *
 * The markdown schema is closed: only what Markdown can represent, so a markdown
 * document always exports without loss.
 */
#[Api]
final readonly class TipTapSchema
{
    /**
     * Rich-text node types (StarterKit, Image, Table).
     *
     * @var list<string>
     */
    private const array RICH_TEXT_NODES = [
        // StarterKit
        'doc', 'paragraph', 'heading', 'text', 'hardBreak', 'horizontalRule',
        'bulletList', 'orderedList', 'listItem', 'blockquote', 'codeBlock',
        // Image
        'image',
        // Table
        'table', 'tableRow', 'tableCell', 'tableHeader',
    ];

    /**
     * Rich-text marks (StarterKit — which since Tiptap 3 ships Underline and Link —
     * plus the explicitly declared Link extension).
     *
     * @var list<string>
     */
    private const array RICH_TEXT_MARKS = ['bold', 'italic', 'strike', 'code', 'link', 'underline'];

    /** @var list<string> */
    private const array MARKDOWN_NODES = [
        'doc', 'paragraph', 'text', 'heading', 'bulletList', 'orderedList', 'listItem',
        'blockquote', 'codeBlock', 'horizontalRule', 'hardBreak',
    ];

    /** @var list<string> */
    private const array MARKDOWN_MARKS = ['bold', 'italic', 'code', 'link', 'strike'];

    /**
     * Attribute allow-list per node/mark type. A type absent from this map may carry no
     * attributes at all. Attribute VALUES are only interpreted where this package needs
     * them (`heading.level`) or where they are a security primitive (`link.href`,
     * `image.src` — URL schemes).
     *
     * The unit here is the attribute set an extension SERIALIZES, not its name.
     * ProseMirror's `toJSON()` dumps a node's WHOLE attrs bag — every key the
     * extension's `addAttributes()` declares, defaults and `null`s included — so
     * anything the extension declares but this list omits rejects the document
     * outright. Two real cases from the shipped extensions (`@tiptap/extension-link ^3`,
     * `@tiptap/starter-kit ^3`): Link declares `class` alongside href/target/rel, so ANY
     * document containing a link could not be saved; OrderedList declares `start` and
     * `type`. A later pass found the same gap on `link.title`, `image.width`/`height` and
     * `tableCell`/`tableHeader` `align`: the hub's projection (packages/collaboration/hub) carries
     * them as `null`s, so they are on the list, and the shared fixture pins the attribute
     * sets of both sides (TipTapSchemaFixtureTest ↔ hub test/schema-parity.test.js).
     *
     * So: when a node/mark is added here, take its attribute set from the extension's
     * `addAttributes()`, not from the subset this package's renderer happens to read.
     *
     * @var array<string, list<string>>
     */
    private const array ATTRIBUTES = [
        'heading' => ['level'],
        'link' => ['href', 'target', 'rel', 'class', 'title'],
        'image' => ['src', 'alt', 'title', 'width', 'height'],
        'codeBlock' => ['language'],
        'orderedList' => ['start', 'type'],
        'tableCell' => ['colspan', 'rowspan', 'colwidth', 'align'],
        'tableHeader' => ['colspan', 'rowspan', 'colwidth', 'align'],
    ];

    /**
     * Additional canonical attributes that exist only on rich-text nodes (not Markdown).
     *
     * @var array<string, list<string>>
     */
    private const array RICH_TEXT_ONLY_ATTRIBUTES = [
        'paragraph' => [BlockIdentity::ATTR_KEY],
        'heading' => [BlockIdentity::ATTR_KEY],
    ];

    /**
     * The package's own URL-bearing attributes. They are always scheme-checked AND always
     * REQUIRED: a link with no href, an image with no src is not a degraded node, it is a
     * malformed one, and the store rejects it.
     *
     * @var array<string, list<string>>
     */
    public const array REQUIRED_URL_ATTRIBUTES = ['link' => ['href'], 'image' => ['src']];

    /**
     * Attribute names that are URL-bearing by NAME, whoever declared them. This is what
     * makes the config seam fail CLOSED: `extra_attributes = ['iframe' => ['src']]` must
     * not buy the host a node whose `src` is scheme-checked nowhere. Extending the
     * allow-list is the host's business; switching off the XSS barrier is not — so an
     * attribute a browser will resolve as a URL is treated as one no matter who put it on
     * the list.
     *
     * The list is names, not guesses: anything a host invents outside it (`location`,
     * `endpoint`, …) it declares itself through `vaulter-documents.tiptap.url_attributes`.
     *
     * @var list<string>
     */
    private const array INFERRED_URL_ATTRIBUTE_NAMES = [
        'href', 'src', 'url', 'poster', 'action', 'formaction', 'cite', 'background', 'longdesc', 'xlink:href',
    ];

    /**
     * @param list<string> $nodes
     * @param list<string> $marks
     */
    private function __construct(
        private array $nodes,
        private array $marks,
        private bool $hostExtensions,
    ) {}

    /** The full editor schema plus the host's additions from vaulter-documents.tiptap.*. */
    public static function richText(): self
    {
        return new self(self::RICH_TEXT_NODES, self::RICH_TEXT_MARKS, hostExtensions: true);
    }

    /** Only the nodes and marks Markdown can represent; not extensible. */
    public static function markdown(): self
    {
        return new self(self::MARKDOWN_NODES, self::MARKDOWN_MARKS, hostExtensions: false);
    }

    /** @return list<string> */
    public function nodeTypes(): array
    {
        return $this->hostExtensions
            ? array_values(array_unique([...$this->nodes, ...$this->config()->tiptap->extraNodes]))
            : $this->nodes;
    }

    /** @return list<string> */
    public function markTypes(): array
    {
        return $this->hostExtensions
            ? array_values(array_unique([...$this->marks, ...$this->config()->tiptap->extraMarks]))
            : $this->marks;
    }

    public function allowsNode(string $type): bool
    {
        return in_array($type, $this->nodeTypes(), true);
    }

    public function allowsMark(string $type): bool
    {
        return in_array($type, $this->markTypes(), true);
    }

    /**
     * The attributes accepted for a node/mark type: the canon MERGED with the host's
     * additions for that type (never replaced).
     *
     * @return list<string>
     */
    public function attributes(string $type): array
    {
        $canon = self::ATTRIBUTES[$type] ?? [];

        if (!$this->hostExtensions) {
            return $canon;
        }

        return array_values(array_unique([
            ...$canon,
            ...(self::RICH_TEXT_ONLY_ATTRIBUTES[$type] ?? []),
            ...($this->config()->tiptap->extraAttributes[$type] ?? []),
        ]));
    }

    /**
     * Every attribute of this type that carries a URL, and therefore goes through
     * {@see LinkSchemeValidator} both at store time and on the JSON read path:
     *
     *  - the package's own (`link.href`, `image.src`);
     *  - anything on the type's allow-list whose NAME is URL-bearing — including attributes
     *    the HOST added through the seam;
     *  - anything the host explicitly declares URL-bearing under a name the package cannot
     *    recognise (`vaulter-documents.tiptap.url_attributes`).
     *
     * @return list<string>
     */
    public function urlAttributes(string $type): array
    {
        $declared = $this->hostExtensions ? ($this->config()->tiptap->urlAttributes[$type] ?? []) : [];
        $inferred = array_intersect($this->attributes($type), self::INFERRED_URL_ATTRIBUTE_NAMES);

        return array_values(array_unique([
            ...(self::REQUIRED_URL_ATTRIBUTES[$type] ?? []),
            ...$inferred,
            ...$declared,
        ]));
    }

    private function config(): DocumentsOptions
    {
        return app(DocumentsOptions::class);
    }
}
