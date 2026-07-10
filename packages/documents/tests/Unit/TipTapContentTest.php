<?php

declare(strict_types=1);

use Vaulter\Exceptions\Documents\InvalidTipTapContentException;
use Vaulter\ValueObjects\Documents\TipTapContent;

it('accepts a valid TipTap document', function (): void {
    $content = TipTapContent::fromArray(['type' => 'doc', 'content' => []]);

    expect($content->toArray())->toBe(['type' => 'doc', 'content' => []]);
});

it('rejects a non-doc root node', function (): void {
    TipTapContent::fromArray(['type' => 'paragraph']);
})->throws(InvalidTipTapContentException::class);

it('builds an empty document', function (): void {
    expect(TipTapContent::empty()->toArray())->toBe(['type' => 'doc', 'content' => []]);
});

it('passes the size guard when unlimited', function (): void {
    expect(TipTapContent::empty()->ensureWithin(0))->toBeInstanceOf(TipTapContent::class);
});

it('throws when the content exceeds the size limit', function (): void {
    $paragraph = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => str_repeat('x', 100)]]];
    $large = ['type' => 'doc', 'content' => array_fill(0, 2000, $paragraph)];

    TipTapContent::fromArray($large)->ensureWithin(1);
})->throws(InvalidTipTapContentException::class);

// ─── F-P1.11-02 — closed node/mark/attribute allow-list ─────────────────────

it('rejects an unknown node type', function (): void {
    TipTapContent::fromArray(['type' => 'doc', 'content' => [['type' => 'script']]]);
})->throws(InvalidTipTapContentException::class);

it('rejects an unknown mark type', function (): void {
    TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'underline']]]],
        ]],
    ]);
})->throws(InvalidTipTapContentException::class);

it('rejects an attribute outside the allow-list for the node type', function (): void {
    TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [['type' => 'paragraph', 'attrs' => ['align' => 'center'], 'content' => []]],
    ]);
})->throws(InvalidTipTapContentException::class);

it('accepts the full allowed node/mark set', function (): void {
    $content = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Title']]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'a', 'marks' => [['type' => 'bold'], ['type' => 'italic'], ['type' => 'strike'], ['type' => 'code']]],
                ['type' => 'text', 'text' => 'link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://x.test']]]],
                ['type' => 'hardBreak'],
            ]],
            ['type' => 'horizontalRule'],
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => []]]]]],
            ['type' => 'orderedList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => []]]]]],
            ['type' => 'blockquote', 'content' => []],
            ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => 'code']]],
        ],
    ]);

    expect($content)->toBeInstanceOf(TipTapContent::class);
});

// ─── F-P1.11-01 — link href scheme allow-list ───────────────────────────────

it('rejects a disallowed link href scheme', function (string $href): void {
    TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]]],
        ]],
    ]);
})->with([
    'javascript' => ['javascript:alert(1)'],
    'vbscript' => ['vbscript:msgbox(1)'],
    'data' => ['data:text/html,<script>alert(1)</script>'],
    'missing href' => [''],
])->throws(InvalidTipTapContentException::class);

it('accepts http, https and mailto link href schemes', function (string $href): void {
    $content = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]]],
        ]],
    ]);

    expect($content)->toBeInstanceOf(TipTapContent::class);
})->with([
    'http' => ['http://x.test'],
    'https' => ['https://x.test'],
    'mailto' => ['mailto:a@x.test'],
]);
