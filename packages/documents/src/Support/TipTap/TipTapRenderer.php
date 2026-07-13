<?php

declare(strict_types=1);

namespace Vaulter\Documents\Support\TipTap;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Vaulter\Documents\Enums\Export\ExportFormat;
use Vaulter\Documents\ValueObjects\LinkSchemeValidator;
use Vaulter\Documents\ValueObjects\TipTapContent;

/**
 * Renders a TipTap JSON document to HTML or Markdown. Covers the node/mark set the package
 * offers the editor through `#[TiptapExtensions]` (headings, lists, blockquote, code blocks,
 * images, tables; bold/italic/strike/code/underline/link marks); unknown nodes fall back to
 * rendering their children.
 *
 * Second echelon of the F-P1.11-01 stored-XSS fix (D4): {@see TipTapContent} rejects
 * disallowed node/mark/attribute shapes and non-http(s)/mailto URL schemes at STORE time,
 * but content persisted before that validator existed decays through on READ with its
 * attribute VALUES intact (D29) — so a legacy `javascript:` href really does arrive here.
 * The two output paths neutralise it differently, because they are different languages:
 *
 *  - HTML     → `symfony/html-sanitizer` (injected, never resolved inline). This renderer
 *               deliberately does NOT pre-filter the href: the sanitizer IS the echelon, and
 *               pre-filtering would leave it nothing to catch (VLT-REMED R-07).
 *  - Markdown → no sanitizer exists, so the escaping is done here — and it is MARKDOWN
 *               escaping, not HTML escaping (VLT-REMED R-04/R-10). Running text through
 *               `htmlspecialchars` neutralises `<>&` but leaves `[`, `]`, `(`, `)` live, so a
 *               label like `click](javascript:alert(1))` closed the link early and opened a
 *               NEW, live one — the allow-list had checked the href, not the string actually
 *               emitted. Destinations are emitted through {@see markdownDestination()}, never
 *               concatenated.
 */
final class TipTapRenderer
{
    public function __construct(
        private readonly HtmlSanitizerInterface $sanitizer,
    ) {}

    public function render(TipTapContent $content, ExportFormat $format): string
    {
        return match ($format) {
            ExportFormat::Html => $this->toHtml($content),
            ExportFormat::Markdown => $this->toMarkdown($content),
        };
    }

    public function toHtml(TipTapContent $content): string
    {
        $html = $this->htmlNodes($this->children($content->toArray()));

        return $this->sanitizer->sanitize($html);
    }

    public function toMarkdown(TipTapContent $content): string
    {
        $blocks = array_map($this->markdownBlock(...), $this->children($content->toArray()));

        return trim(implode("\n\n", array_filter($blocks, static fn (string $block): bool => $block !== '')))."\n";
    }

    // ─── HTML ────────────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function htmlNodes(array $nodes): string
    {
        return implode('', array_map($this->htmlNode(...), $nodes));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function htmlNode(array $node): string
    {
        $children = $this->children($node);

        return match ($this->type($node)) {
            'paragraph' => '<p>'.$this->htmlNodes($children).'</p>',
            'heading' => $this->htmlHeading($node, $children),
            'text' => $this->htmlText($node),
            'hardBreak' => '<br>',
            'horizontalRule' => '<hr>',
            'bulletList' => '<ul>'.$this->htmlNodes($children).'</ul>',
            'orderedList' => '<ol>'.$this->htmlNodes($children).'</ol>',
            'listItem' => '<li>'.$this->htmlNodes($children).'</li>',
            'blockquote' => '<blockquote>'.$this->htmlNodes($children).'</blockquote>',
            'codeBlock' => $this->htmlCodeBlock($node, $children),
            'image' => $this->htmlImage($node),
            'table' => '<table>'.$this->htmlNodes($children).'</table>',
            'tableRow' => '<tr>'.$this->htmlNodes($children).'</tr>',
            'tableCell' => '<td>'.$this->htmlNodes($children).'</td>',
            'tableHeader' => '<th>'.$this->htmlNodes($children).'</th>',
            default => $this->htmlNodes($children),
        };
    }

    /**
     * @param array<string, mixed>       $node
     * @param list<array<string, mixed>> $children
     */
    private function htmlHeading(array $node, array $children): string
    {
        $level = $this->headingLevel($node);

        return sprintf('<h%1$d>%2$s</h%1$d>', $level, $this->htmlNodes($children));
    }

    /**
     * @param array<string, mixed>       $node
     * @param list<array<string, mixed>> $children
     */
    private function htmlCodeBlock(array $node, array $children): string
    {
        $language = $this->stringValue($this->attrs($node), 'language');
        $class = $language === '' ? '' : ' class="language-'.$this->escape($language).'"';

        return '<pre><code'.$class.'>'.$this->escape($this->plainText($children)).'</code></pre>';
    }

    /**
     * The `src` is emitted ESCAPED but NOT scheme-filtered: the injected sanitizer is the
     * second echelon (D4) and owns that call — see the class docblock (R-07).
     *
     * @param array<string, mixed> $node
     */
    private function htmlImage(array $node): string
    {
        $attrs = $this->attrs($node);

        $html = '<img src="'.$this->escape($this->stringValue($attrs, 'src')).'"';

        foreach (['alt', 'title'] as $key) {
            $value = $this->stringValue($attrs, $key);

            if ($value !== '') {
                $html .= ' '.$key.'="'.$this->escape($value).'"';
            }
        }

        return $html.'>';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function htmlText(array $node): string
    {
        $text = $this->escape($this->stringValue($node, 'text'));

        foreach ($this->marks($node) as $mark) {
            $text = match ($this->type($mark)) {
                'bold' => sprintf('<strong>%s</strong>', $text),
                'italic' => sprintf('<em>%s</em>', $text),
                'strike' => sprintf('<s>%s</s>', $text),
                'code' => sprintf('<code>%s</code>', $text),
                'underline' => sprintf('<u>%s</u>', $text),
                // No scheme pre-filter here on purpose: the injected sanitizer is the second
                // echelon and must actually SEE the bad href to strip it (R-07).
                'link' => '<a href="'.$this->escape($this->stringValue($this->attrs($mark), 'href')).'">'.$text.'</a>',
                default => $text,
            };
        }

        return $text;
    }

    // ─── Markdown ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $node
     */
    private function markdownBlock(array $node): string
    {
        $children = $this->children($node);

        return match ($this->type($node)) {
            'paragraph' => $this->markdownInline($children),
            'heading' => str_repeat('#', $this->headingLevel($node)).' '.$this->markdownInline($children),
            'bulletList' => $this->markdownList($children, '- '),
            'orderedList' => $this->markdownOrderedList($children),
            'blockquote' => '> '.str_replace("\n", "\n> ", $this->markdownInline($children)),
            'codeBlock' => $this->markdownCodeBlock($node, $children),
            'horizontalRule' => '---',
            'image' => $this->markdownImage($node),
            'table' => $this->markdownTable($children),
            default => $this->markdownInline($children),
        };
    }

    /**
     * A fenced code block — the BLOCK sibling of {@see markdownCodeSpan()}, and the same
     * class of hole (VLT-REMED P5.2 review / F-P1.11-01, F-P1.11-04). Both halves used to be
     * emitted RAW, and both halves close the fence:
     *
     *  - the BODY. A code block is inert only while its fence holds, and a fence is closed by
     *    a line of backticks at least as long as the opening one. `"a\n```\n<script>…"` — which
     *    store-time validation accepts, it is legitimate TEXT — therefore closed its own fence
     *    and dropped the rest of the block into the document as top-level markup: a LIVE
     *    raw-HTML block in the exported `.md`. CommonMark's rule is the fix: the fence is one
     *    backtick longer than the longest backtick run in the body, so nothing inside it can
     *    reach the fence's length. The body is then carried VERBATIM (a code block must be) —
     *    no escaping, and none needed.
     *  - the INFO STRING (`attrs.language`). It is on the store-time attribute allow-list, but
     *    its VALUE is not validated there, so `"php\n```\n<img src=x onerror=alert(1)>"` closed
     *    the fence with a newline and emitted a live `<img onerror>`. An info string is a bare
     *    language token: anything outside `[A-Za-z0-9_+-]` is not one, and is dropped whole
     *    rather than sanitised into something that merely looks like one.
     *
     * @param array<string, mixed>       $node
     * @param list<array<string, mixed>> $children
     */
    private function markdownCodeBlock(array $node, array $children): string
    {
        $body = $this->plainText($children);
        $fence = str_repeat('`', max(3, $this->longestBacktickRun($body) + 1));

        return $fence.$this->markdownInfoString($this->stringValue($this->attrs($node), 'language'))
            ."\n".$body."\n".$fence;
    }

    /**
     * The longest run of consecutive backticks anywhere in $body — the fence must exceed it.
     */
    private function longestBacktickRun(string $body): int
    {
        preg_match_all('/`+/', $body, $matches);

        $longest = 0;

        foreach ($matches[0] as $run) {
            $longest = max($longest, strlen($run));
        }

        return $longest;
    }

    /**
     * The code-fence info string, or '' when the declared language is not a bare language
     * token (see {@see markdownCodeBlock()} — a newline or a backtick in there closes the
     * fence from the OPENING line).
     */
    private function markdownInfoString(string $language): string
    {
        return preg_match('/^[A-Za-z0-9_+-]*$/', $language) === 1 ? $language : '';
    }

    /**
     * `![alt](src)` — the destination goes through the same safe emitter as a link (scheme
     * allow-list + a destination that cannot be broken out of, R-04). A disallowed scheme
     * drops the image entirely: an image with no usable src is nothing anyway.
     *
     * @param array<string, mixed> $node
     */
    private function markdownImage(array $node): string
    {
        $attrs = $this->attrs($node);
        $destination = $this->markdownDestination($this->stringValue($attrs, 'src'));

        if ($destination === null) {
            return '';
        }

        return '!['.$this->escapeMarkdown($this->stringValue($attrs, 'alt')).']('.$destination.')';
    }

    /**
     * A GFM pipe table. The first row is the header (TipTap puts `tableHeader` cells there).
     *
     * The cell does NOT re-escape the pipe (VLT-REMED P5.2): {@see escapeMarkdown()} already
     * emits `\|`, and a second `str_replace('|', '\|')` on top of it produced `\\|` — which
     * GFM reads as an escaped BACKSLASH followed by a LIVE delimiter, splitting the cell into
     * an extra column and shifting every column after it. The escaping lives in exactly one
     * place; the one path that cannot take a backslash escape (a code span) neutralises its
     * own pipe — see {@see markdownCodeSpan()}.
     *
     * A newline inside a cell would end the row just as surely as a pipe would add a column,
     * so cells are flattened to a single line.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function markdownTable(array $rows): string
    {
        $lines = [];

        foreach ($rows as $index => $row) {
            $cells = array_map(
                fn (array $cell): string => $this->singleLine(trim($this->markdownInline($this->children($cell)))),
                $this->children($row),
            );

            $lines[] = '| '.implode(' | ', $cells).' |';

            if ($index === 0) {
                $lines[] = '|'.str_repeat(' --- |', max(count($cells), 1));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function markdownList(array $items, string $bullet): string
    {
        $lines = array_map(fn (array $item): string => $bullet.$this->markdownInline($this->children($item)), $items);

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function markdownOrderedList(array $items): string
    {
        $lines = [];
        foreach ($items as $index => $item) {
            $lines[] = ($index + 1).'. '.$this->markdownInline($this->children($item));
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function markdownInline(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= match ($this->type($node)) {
                'text' => $this->markdownText($node),
                'hardBreak' => "\n",
                'image' => $this->markdownImage($node),
                default => $this->markdownInline($this->children($node)),
            };
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function markdownText(array $node): string
    {
        $raw = $this->stringValue($node, 'text');

        // MARKDOWN escaping, not HTML escaping (R-04/R-10). htmlspecialchars() neutralises
        // `<>&` but leaves the markdown metacharacters live — which is exactly what a payload
        // uses to close the link it sits in and open a new one.
        $text = $this->escapeMarkdown($raw);

        foreach ($this->marks($node) as $mark) {
            $text = match ($this->type($mark)) {
                'bold' => sprintf('**%s**', $text),
                'italic' => sprintf('*%s*', $text),
                'strike' => sprintf('~~%s~~', $text),
                'code' => $this->markdownCodeSpan($raw),
                'underline' => sprintf('<u>%s</u>', $text),
                'link' => $this->markdownLink($text, $this->stringValue($this->attrs($mark), 'href')),
                default => $text,
            };
        }

        return $text;
    }

    /**
     * An inline code span.
     *
     * A code span is inert — but ONLY while it actually closes, and backslash escapes are
     * LITERAL inside one, so the text cannot simply be run through {@see escapeMarkdown()}.
     * That left the branch emitting RAW text, and two characters can prise the span open
     * (VLT-REMED P5.2):
     *
     *  - a BACKTICK closes it early (stripped, as before);
     *  - a BLANK LINE ends the enclosing paragraph outright, so everything after it lands in
     *    the document as top-level markup. That is how `a\n\n<script>alert(1)</script>` with
     *    a `code` mark — which store-time validation accepts, it is legitimate TEXT — left
     *    the span behind and emitted a LIVE raw-HTML block into the exported `.md`, exactly
     *    the F-P1.11-04 class the sibling text branch escapes `<`/`>` to prevent. Newlines
     *    are collapsed to spaces: the span always closes on the line it opened on.
     *
     * The pipe is the third: a raw `|` inside a table cell adds a column, and this branch is
     * the one that bypasses {@see escapeMarkdown()}. `\|` is GFM's documented way to carry a
     * pipe through a cell — including inside an inline span — so it is escaped here, at the
     * cost of a literal `\|` when a code span outside a table happens to contain one.
     */
    private function markdownCodeSpan(string $raw): string
    {
        $inline = str_replace(['`', '|'], ['', '\|'], $this->singleLine($raw));

        return $inline === '' ? '' : sprintf('`%s`', $inline);
    }

    /**
     * Collapse the line terminators that would break out of an inline construct (a code span,
     * a table row) into spaces.
     */
    private function singleLine(string $value): string
    {
        return str_replace(["\r\n", "\r", "\n"], ' ', $value);
    }

    /**
     * `[label](destination)` — Markdown has no sanitizer, so BOTH halves are handled here
     * (R-04): the label arrives markdown-escaped (its `]` can no longer close the link
     * early), and the destination goes through {@see markdownDestination()} instead of being
     * concatenated in. A disallowed scheme drops the link wrapper and keeps the escaped
     * label as plain text.
     */
    private function markdownLink(string $label, string $href): string
    {
        $destination = $this->markdownDestination($href);

        return $destination === null ? $label : '['.$label.']('.$destination.')';
    }

    /**
     * A link/image destination that cannot be broken out of, or null when the scheme is not
     * on the allow-list (http/https/mailto — the same {@see LinkSchemeValidator} the
     * store-time validator uses, re-applied because decayed legacy content reaches us with
     * its href intact, D29).
     *
     * The scheme check alone is NOT enough (R-04, vector B): a perfectly legal
     * `https://a.example/) [pwn](javascript:alert(1)` closes the destination early through
     * its `)` and injects a second, live link. CommonMark's angle-bracket form takes `(`/`)`
     * and spaces verbatim and only forbids `<`, `>` and newlines — which we percent-encode.
     * So: plain form when the URL is boring, angle form otherwise.
     *
     * The `|` is percent-encoded in BOTH forms (VLT-REMED P5.2). A destination never passes
     * through {@see escapeMarkdown()} — this is the one place it is built — and neither form
     * protects it: a perfectly legal `https://a.example/?a=1|2|3` inside a table cell rendered
     * as `| [x](https://a.example/?a=1|2|3) | y |`, which GFM reads as FOUR columns, shifting
     * every column after it. The backslash escape is no help inside a destination (CommonMark
     * would carry the backslash into the URL), so `%7C` it is — the browser resolves it
     * identically.
     */
    private function markdownDestination(string $url): ?string
    {
        $sanitized = LinkSchemeValidator::sanitize($url);

        if ($sanitized === null) {
            return null;
        }

        $sanitized = str_replace('|', '%7C', $sanitized);

        if (preg_match('/[\s()<>]/', $sanitized) !== 1) {
            return $sanitized;
        }

        return '<'.str_replace(['<', '>', ' '], ['%3C', '%3E', '%20'], $sanitized).'>';
    }

    /**
     * Backslash-escape the markdown metacharacters that can change the STRUCTURE of the
     * output (CommonMark allows a backslash escape before any ASCII punctuation). `<` and
     * `>` are included because a Markdown consumer may pass raw HTML through — a literal
     * `<script>` in the text must not become live markup (F-P1.11-04).
     */
    private function escapeMarkdown(string $value): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+!|~<>])/', '\\\\$1', $value);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>       $node
     * @return list<array<string, mixed>>
     */
    private function children(array $node): array
    {
        return $this->arrayOfNodes($node['content'] ?? null);
    }

    /**
     * @param  array<string, mixed>       $node
     * @return list<array<string, mixed>>
     */
    private function marks(array $node): array
    {
        return $this->arrayOfNodes($node['marks'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function arrayOfNodes(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $nodes = [];
        foreach ($value as $node) {
            if (is_array($node)) {
                /** @var array<string, mixed> $node */
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function type(array $node): string
    {
        return $this->stringValue($node, 'type');
    }

    /**
     * @param  array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function attrs(array $node): array
    {
        $attrs = $node['attrs'] ?? null;

        /** @var array<string, mixed> $result */
        $result = is_array($attrs) ? $attrs : [];

        return $result;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function headingLevel(array $node): int
    {
        $level = $this->attrs($node)['level'] ?? 1;
        $level = is_numeric($level) ? (int) $level : 1;

        return max(1, min(6, $level));
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function plainText(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= $this->type($node) === 'text'
                ? $this->stringValue($node, 'text')
                : $this->plainText($this->children($node));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function stringValue(array $node, string $key): string
    {
        $value = $node[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }
}
