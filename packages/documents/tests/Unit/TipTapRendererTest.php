<?php

declare(strict_types=1);

use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Content\TipTap\TipTapRenderer;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;

function sampleDocument(): TipTapContent
{
    return TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Title']]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Hello '],
                ['type' => 'text', 'text' => 'world', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' & '],
                ['type' => 'text', 'text' => 'link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://x.test']]]],
            ]],
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'one']]]]],
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'two']]]]],
            ]],
        ],
    ]);
}

/**
 * Every link and image target of the markdown output, both `](...)` and the angle form `](<...>)`.
 * A property, not a substring: the test proves that EVERY target actually emitted has an
 * allowed scheme, whereas an assertion like "the output has no javascript:alert(1)" fits one
 * vector and lets the neighbouring one through.
 *
 * @return list<string>
 */
function markdownLinkTargets(string $markdown): array
{
    preg_match_all('/!?\]\(\s*(?:<([^>]*)>|([^)\s]*))/', $markdown, $matches, PREG_SET_ORDER);

    $targets = [];

    foreach ($matches as $match) {
        $target = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');

        if ($target !== '') {
            $targets[] = urldecode($target);
        }
    }

    return $targets;
}

it('renders TipTap content to HTML with escaping', function (): void {
    $html = app(TipTapRenderer::class)->toHtml(sampleDocument());

    expect($html)->toContain('<h2>Title</h2>')
        ->toContain('<strong>world</strong>')
        ->toContain('<a href="https://x.test">link</a>')
        ->toContain('&amp;')
        ->toContain('<li><p>one</p></li>');
});

it('renders TipTap content to Markdown', function (): void {
    $markdown = app(TipTapRenderer::class)->toMarkdown(sampleDocument());

    expect($markdown)->toContain('## Title')
        ->toContain('**world**')
        ->toContain('[link](https://x.test)')
        ->toContain('- one')
        ->toContain('- two');
});

it('renders the newer contract nodes (image, table) and the underline mark', function (): void {
    $doc = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [
            ['type' => 'image', 'attrs' => ['src' => 'https://x.test/a.png', 'alt' => 'pic']],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'u', 'marks' => [['type' => 'underline']]],
            ]],
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [
                    ['type' => 'tableHeader', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'h']]]]],
                ]],
                ['type' => 'tableRow', 'content' => [
                    ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'c']]]]],
                ]],
            ]],
        ],
    ]);

    $renderer = app(TipTapRenderer::class);

    // The tag shape is the one AFTER the sanitizer (it normalizes `<img>` to `<img />` and adds
    // `<tbody>`): the test checks what actually reaches the consumer.
    expect($renderer->toHtml($doc))
        ->toContain('<img src="https://x.test/a.png" alt="pic" />')
        ->toContain('<u>u</u>')
        ->toContain('<th><p>h</p></th>')
        ->toContain('<td><p>c</p></td>');

    expect($renderer->toMarkdown($doc))
        ->toContain('![pic](https://x.test/a.png)')
        ->toContain('| h |')
        ->toContain('| c |');
});

// ─── stored XSS via javascript: href (store time) ──────────────

it('rejects a javascript: link href at store time', function (): void {
    TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'click me',
                'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]],
            ]],
        ]],
    ]);
})->throws(InvalidDocumentContentException::class);

it('rejects obfuscated javascript:/data: schemes at store time', function (string $href): void {
    TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'x',
                'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]],
            ]],
        ]],
    ]);
})->with([
    'uppercase scheme' => ['JAVASCRIPT:alert(1)'],
    'embedded tab' => ["java\tscript:alert(1)"],
    'BiDi override' => ["\u{202E}javascript:alert(1)"],
    'data URI' => ['data:text/html;base64,PHNjcmlwdD4='],
])->throws(InvalidDocumentContentException::class);

it('rejects an unknown node type at store time', function (): void {
    TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [['type' => 'script', 'content' => []]],
    ]);
})->throws(InvalidDocumentContentException::class);

it('rejects an unknown attribute at store time', function (): void {
    TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'attrs' => ['style' => 'background:url(javascript:alert(1))'],
            'content' => [],
        ]],
    ]);
})->throws(InvalidDocumentContentException::class);

// ─── second line of defence (render-time HTML sanitizer) ───────────

/*
 * The second line of defence used to be UNREACHABLE: the only way into TipTapContent was the strict fromArray(),
 * which rejected dangerous content before the sanitizer could see it, so the "second line
 * tests" actually pinned the store-time check. With decay on read a legacy snapshot with a
 * javascript: href REALLY reaches the renderer, and symfony/html-sanitizer neutralizes it.
 */
function legacyHostileSnapshot(): array
{
    return [
        'type' => 'doc',
        'content' => [
            ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'alert(1)']]],
            ['type' => 'paragraph', 'content' => [[
                'type' => 'text',
                'text' => 'click me',
                'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]],
            ]]],
            ['type' => 'image', 'attrs' => ['src' => 'javascript:alert(1)']],
        ],
    ];
}

it('a legacy snapshot with a javascript: href reaches the renderer and is neutralized by the sanitizer', function (): void {
    $html = app(TipTapRenderer::class)->toHtml(TipTapContent::decayFrom(legacyHostileSnapshot()));

    // The sanitizer removed the dangerous href/src; the link text is kept.
    expect($html)->not->toContain('javascript:')
        ->and($html)->toContain('click me');
});

it('the second line of defence is REACHABLE: with a pass-through sanitizer the same snapshot leaks', function (): void {
    // Proof that the test can go red: replace the sanitizer with a transparent stub. If the
    // renderer cut href itself before the sanitizer, the second line would be fiction and this test would
    // stay green; it must fail, showing that the second line has a real input.
    app()->instance(HtmlSanitizerInterface::class, new class implements HtmlSanitizerInterface
    {
        public function sanitize(string $input): string
        {
            return $input;
        }

        public function sanitizeFor(string $element, string $input): string
        {
            return $input;
        }
    });

    $html = app(TipTapRenderer::class)->toHtml(TipTapContent::decayFrom(legacyHostileSnapshot()));

    expect($html)->toContain('javascript:alert(1)');
});

// ─── markdown path ──────────────────────────────

it('markdown export: the scheme of EVERY emitted link target is allowed', function (string $label, string $href): void {
    // A legacy snapshot (decay, not fromArray): the store-time href could be legal while the payload
    // sits in the LABEL; or an href with a legal scheme carries ')' and closes the target early.
    $markdown = app(TipTapRenderer::class)->toMarkdown(TipTapContent::decayFrom([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => $label,
                'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]],
            ]],
        ]],
    ]));

    $schemes = array_map(
        static fn (string $target): string => mb_strtolower((string) parse_url($target, PHP_URL_SCHEME)),
        markdownLinkTargets($markdown),
    );

    // One check that ALWAYS runs (including when no targets are left at all):
    // the set of schemes of the emitted targets has nothing outside the allow-list.
    expect(array_values(array_diff($schemes, ['http', 'https', 'mailto'])))
        ->toBe([], "dangerous scheme in the markdown link targets; output: [{$markdown}]");
})->with([
    // Vector A: injection through the label.
    'label closes the link' => ['click](javascript:alert(1))', 'https://ok.example'],
    // Vector B: ')' inside an allowed https scheme.
    'href with a parenthesis' => ['safe', 'https://a.example/) [pwn](javascript:alert(1)'],
    'javascript' => ['x', 'javascript:alert(1)'],
    'data' => ['x', 'data:text/html,<script>alert(1)</script>'],
    'vbscript' => ['x', 'vbscript:msgbox(1)'],
    'file' => ['x', 'file:///etc/passwd'],
    'tab obfuscation' => ['x', "java\tscript:alert(1)"],
    'case obfuscation' => ['x', 'JaVaScRiPt:alert(1)'],
    'percent obfuscation' => ['x', '%6a%61%76%61script:alert(1)'],
    'image in the label' => ['![x](javascript:alert(1))', 'https://ok.example'],
]);

it('markdown export: a dangerous image src is not emitted', function (): void {
    $markdown = app(TipTapRenderer::class)->toMarkdown(TipTapContent::decayFrom([
        'type' => 'doc',
        'content' => [['type' => 'image', 'attrs' => ['src' => 'javascript:alert(1)', 'alt' => 'x']]],
    ]));

    expect(markdownLinkTargets($markdown))->toBe([]);
});

it('escapes raw text in markdown export', function (): void {
    // A literal "<script>" is legitimate text (not an injection at the TipTap JSON level), and
    // the store-time check does not reject it. The export must emit it inert.
    $doc = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => '<script>alert(1)</script>']],
        ]],
    ]);

    $markdown = app(TipTapRenderer::class)->toMarkdown($doc);

    expect($markdown)
        ->not->toContain('<script>alert(1)</script>')
        // Markdown escaping, not HTML: `\<script\>`, NOT `&lt;script&gt;`.
        ->toContain('\<script\>')
        ->not->toContain('&lt;');
});

it('markdown export does NOT print HTML entities: & in URLs and < in text', function (): void {
    $doc = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'a < b & c'],
                ['type' => 'text', 'text' => 'q', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://x.test/?a=1&b=2']]]],
            ],
        ]],
    ]);

    $markdown = app(TipTapRenderer::class)->toMarkdown($doc);

    // The URL arrives whole (it used to be https://x.test/?a=1&amp;b=2, a broken link).
    expect(markdownLinkTargets($markdown))->toBe(['https://x.test/?a=1&b=2'])
        // The text shows no literal entities (it used to be `a &lt; b &amp; c`).
        ->and($markdown)->not->toContain('&lt;')
        ->and($markdown)->not->toContain('&amp;');
});

// ─── code span and table: two ways around escapeMarkdown ───────────

/*
 * The `code` branch is the only one that does NOT go through escapeMarkdown (backslashes are literal
 * inside a code span), so it emitted text RAW. A span is inert only as long as it
 * closes, and a blank line closes not the span but the whole PARAGRAPH: everything after it lands
 * in the .md as top-level markup. The store-time check accepts such a payload; it is legitimate
 * TEXT, not an injection at the TipTap JSON level. Exactly the class the
 * neighbouring text branch closes by escaping `<`/`>`.
 */
it('markdown export: a code mark does not let raw HTML out through a paragraph break', function (): void {
    $doc = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => "a\n\n<script>alert(1)</script>",
                'marks' => [['type' => 'code']],
            ]],
        ]],
    ]);

    $markdown = app(TipTapRenderer::class)->toMarkdown($doc);
    $span = trim($markdown);

    // Before the fix: "`a\n\n<script>alert(1)</script>`": the blank line broke the paragraph, the span did not
    // close, and <script> came out in the .md as a LIVE block of raw HTML.
    expect($markdown)->not->toContain("\n\n")
        // Exactly ONE closed code span in the whole output…
        ->and(substr_count($span, '`'))->toBe(2)
        ->and($span)->toStartWith('`')
        ->and($span)->toEndWith('`')
        // …and the whole payload inside it, hence inert.
        ->and($span)->toContain('<script>alert(1)</script>');
});

/*
 * The pipe was escaped TWICE: escapeMarkdown already returns `\|`, and the cell on top ran
 * str_replace('|', '\|'), giving `\\|`. GFM reads `\\` as an escaped backslash and the
 * following `|` as a LIVE separator: the cell splits into an extra column and shifts
 * all the following ones. The very column injection the comment promised to prevent.
 */
it('markdown export: a pipe in a cell does not add a column', function (): void {
    $cell = static fn (string $type, string $text, bool $code = false): array => [
        'type' => $type,
        'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => $text] + ($code ? ['marks' => [['type' => 'code']]] : []),
        ]]],
    ];

    $doc = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'table',
            'content' => [
                ['type' => 'tableRow', 'content' => [$cell('tableHeader', 'h1'), $cell('tableHeader', 'h2')]],
                ['type' => 'tableRow', 'content' => [$cell('tableCell', 'a|b'), $cell('tableCell', 'c')]],
                // A code span is the second way into a cell around escapeMarkdown.
                ['type' => 'tableRow', 'content' => [$cell('tableCell', 'x|y', code: true), $cell('tableCell', 'z')]],
            ],
        ]],
    ]);

    $markdown = app(TipTapRenderer::class)->toMarkdown($doc);

    // There is no double escaping on any path…
    expect($markdown)->not->toContain('\\\\|')
        ->and($markdown)->toContain('| a\|b | c |')
        ->and($markdown)->toContain('| `x\|y` | z |');

    // …and every table row carries EXACTLY two columns (the header sets the width).
    foreach (explode("\n", trim($markdown)) as $line) {
        expect(substr_count($line, '|') - substr_count($line, '\|'))->toBe(3, "broken table row: [{$line}]");
    }
});

/*
 * Found in review (the same class, a neighbouring path): the codeBlock markdown branch printed
 * `'```'.attrs.language."\n".plainText($children)."\n```"`, both halves RAW. A code block
 * body that contains its own fence closes it early, and everything after it
 * lands in the .md as top-level markup, LIVE raw HTML. The store-time check accepts such content:
 * it is legitimate TEXT. Exactly what is already closed for the inline code mark
 * (markdownCodeSpan), while its sibling node codeBlock stayed raw.
 *
 * A property, not a substring: the opening and closing fences are identical and longer than any
 * backtick run in the body (CommonMark), so the body is inert AS A WHOLE, whatever it
 * is.
 */
it('markdown export: a codeBlock body does not close its fence or let raw HTML out', function (): void {
    $body = "innocent\n```\n<script>alert(1)</script>\n```";

    $doc = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'codeBlock',
            'content' => [['type' => 'text', 'text' => $body]],
        ]],
    ]);

    $markdown = app(TipTapRenderer::class)->toMarkdown($doc);
    $lines = explode("\n", trim($markdown));

    // The fence is longer than the longest backtick run in the body (3), so at least 4.
    expect($lines[0])->toBe('````')
        ->and($lines[array_key_last($lines)])->toBe('````')
        // The body arrived verbatim and lies entirely BETWEEN the fences, hence inert.
        ->and(implode("\n", array_slice($lines, 1, -1)))->toBe($body);
});

/*
 * The second half: attrs.language is in CANONICAL_ATTRIBUTES, and the store-time check does not validate its
 * VALUE. The info string was printed raw, so `"php\n```\n<img src=x onerror=alert(1)>"`
 * closed the fence with a newline and let a live <img onerror> out into the .md.
 */
it('markdown export: the codeBlock language is validated, not printed raw', function (string $language, string $expectedFence): void {
    $doc = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'codeBlock',
            'attrs' => ['language' => $language],
            'content' => [['type' => 'text', 'text' => 'echo 1;']],
        ]],
    ]);

    $markdown = app(TipTapRenderer::class)->toMarkdown($doc);
    $lines = explode("\n", trim($markdown));

    expect($lines[0])->toBe($expectedFence)
        ->and($lines)->toHaveCount(3)
        ->and($lines[1])->toBe('echo 1;')
        ->and($markdown)->not->toContain('onerror');
})->with([
    // A legal language arrives as the info string.
    'plain language' => ['php', '```php'],
    'hyphen and plus' => ['objective-c++', '```objective-c++'],
    // Anything carrying a character outside [A-Za-z0-9_+-] never becomes an info string.
    'newline + payload' => ["php\n```\n<img src=x onerror=alert(1)>", '```'],
    'space and markup' => ['php <img src=x onerror=alert(1)>', '```'],
    'backtick' => ['php`', '```'],
]);

it('keeps a valid link and drops disallowed link schemes in markdown export', function (): void {
    $doc = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'click me',
                'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://x.test']]],
            ]],
        ]],
    ]);

    $markdown = app(TipTapRenderer::class)->toMarkdown($doc);

    expect($markdown)->toContain('[click me](https://x.test)');
});

/**
 * The width of every GFM table row in COLUMNS, counted by unescaped '|', exactly as
 * GFM reads them. A property, not a substring: the test proves that no cell can
 * add a column to the table, however the '|' got into it.
 *
 * @return list<int>
 */
function markdownTableWidths(string $markdown): array
{
    $widths = [];

    foreach (explode("\n", $markdown) as $line) {
        $line = trim($line);

        if (!str_starts_with($line, '|') || !str_ends_with($line, '|')) {
            continue;
        }

        $inner = substr($line, 1, -1);
        $cells = preg_split('/(?<!\\\\)\|/', $inner);

        $widths[] = is_array($cells) ? count($cells) : 0;
    }

    return $widths;
}

/**
 * A 2×2 table with arbitrary content in the first body cell.
 *
 * @param array<string, mixed> $cellContent
 */
function tableWithCell(array $cellContent): TipTapContent
{
    $cell = fn (string $type, array $inline): array => [
        'type' => $type,
        'content' => [['type' => 'paragraph', 'content' => $inline]],
    ];

    return TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'table',
            'content' => [
                ['type' => 'tableRow', 'content' => [
                    $cell('tableHeader', [['type' => 'text', 'text' => 'a']]),
                    $cell('tableHeader', [['type' => 'text', 'text' => 'b']]),
                ]],
                ['type' => 'tableRow', 'content' => [
                    $cell('tableCell', [$cellContent]),
                    $cell('tableCell', [['type' => 'text', 'text' => 'y']]),
                ]],
            ],
        ]],
    ]);
}

it('markdown export: a pipe in a link or image TARGET does not add table columns', function (array $cellContent): void {
    // The scheme is legal (http) and the store-time check accepts the URL, but the link target bypasses
    // escapeMarkdown(), and markdownDestination() encoded only <, > and space. GFM read
    // '| [x](https://a.example/?a=1|2|3) | y |' as FOUR columns and shifted everything after it.
    $markdown = app(TipTapRenderer::class)->toMarkdown(tableWithCell($cellContent));

    // header + separator + body row, each exactly 2 columns.
    expect(markdownTableWidths($markdown))->toBe([2, 2, 2]);
})->with([
    'link' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://a.example/?a=1|2|3']]]]],
    'image' => [['type' => 'image', 'attrs' => ['src' => 'https://a.example/i.png?a=1|2', 'alt' => 'x']]],
    'link with a parenthesis and a pipe' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://a.example/(x)?a=1|2']]]]],
]);
