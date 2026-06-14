<?php

declare(strict_types=1);

use DocVault\Support\TipTapRenderer;
use DocVault\ValueObjects\TipTapContent;

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
    $html = (new TipTapRenderer)->toHtml(sampleDocument());

    expect($html)->toContain('<h2>Title</h2>')
        ->toContain('<strong>world</strong>')
        ->toContain('<a href="https://x.test">link</a>')
        ->toContain('&amp;')
        ->toContain('<li><p>one</p></li>');
});

it('renders TipTap content to Markdown', function (): void {
    $markdown = (new TipTapRenderer)->toMarkdown(sampleDocument());

    expect($markdown)->toContain('## Title')
        ->toContain('**world**')
        ->toContain('[link](https://x.test)')
        ->toContain('- one')
        ->toContain('- two');
});
