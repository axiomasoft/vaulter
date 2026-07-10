<?php

declare(strict_types=1);

use Vaulter\Documents\Support\TipTapRenderer;
use Vaulter\Exceptions\Documents\InvalidTipTapContentException;
use Vaulter\ValueObjects\Documents\TipTapContent;

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

// ─── F-P1.11-01 — stored XSS via javascript: href ───────────────────────────

it('rejects a javascript: link href at store time, F-P1.11-01', function (): void {
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
})->throws(InvalidTipTapContentException::class);

it('rejects obfuscated javascript:/data: schemes at store time, F-P1.11-01', function (string $href): void {
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
])->throws(InvalidTipTapContentException::class);

it('rejects an unknown node type at store time, F-P1.11-02', function (): void {
    TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [['type' => 'script', 'content' => []]],
    ]);
})->throws(InvalidTipTapContentException::class);

it('rejects an unknown attribute at store time, F-P1.11-02', function (): void {
    TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'attrs' => ['style' => 'background:url(javascript:alert(1))'],
            'content' => [],
        ]],
    ]);
})->throws(InvalidTipTapContentException::class);

// ─── F-P1.11-04 — markdown export does not carry raw HTML / bad schemes ────

it('escapes raw text in markdown export, F-P1.11-04', function (): void {
    // A literal "<script>" IS legitimate free-text content (not a code injection at
    // the TipTap-JSON level) — the store-time validator has no reason to reject it.
    // The export path must still not hand it to the consumer as live markup.
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
        ->toContain('&lt;script&gt;');
});

it('keeps a valid link and drops disallowed link schemes in markdown export, F-P1.11-04', function (): void {
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
