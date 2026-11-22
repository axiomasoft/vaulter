<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Vaulter\Documents\Configuration\DocumentsOptions;
use Vaulter\Documents\Content\TipTap\BlockIdentity;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Content\TipTap\TipTapRenderer;
use Vaulter\Documents\Content\TipTap\TipTapSchema;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\Exceptions\StaleDocumentStateException;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers as Docs;
use Vaulter\Documents\Tests\TestCase;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;

uses(TestCase::class, RefreshDatabase::class);

it('defaults vaulter-documents.tiptap.block_ids to false', function (): void {
    $options = app(DocumentsOptions::class);

    expect($options->tiptap->blockIds)->toBeFalse();
    expect((new RichTextDocumentType)->schemaVersion())->toBe(1);
});

it('assigns unique blockIds to paragraphs and headings and preserves existing valid ones', function (): void {
    $seq = 0;
    $gen = static function () use (&$seq): string {
        return 'blk_'.(++$seq);
    };

    $doc = [
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2, 'blockId' => 'h1'], 'content' => [['type' => 'text', 'text' => 'Title']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First']]],
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'h1'], 'content' => [['type' => 'text', 'text' => 'Dup']]],
            ['type' => 'blockquote', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Quoted']]],
            ]],
        ],
    ];

    $result = BlockIdentity::normalize($doc, $gen);

    expect($result['changed'])->toBeTrue();
    expect(BlockIdentity::extractIds($result['doc']))->toBe(['h1', 'blk_1', 'blk_2', 'blk_3']);
    expect($result['doc']['content'][0]['attrs']['level'])->toBe(2);

    $second = BlockIdentity::normalize($result['doc'], $gen);
    expect($second['changed'])->toBeFalse();
});

it('regenerates all blockIds on copy semantics', function (): void {
    $seq = 0;
    $gen = static function () use (&$seq): string {
        return 'copy_'.(++$seq);
    };

    $doc = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'p1'], 'content' => [['type' => 'text', 'text' => 'A']]],
            ['type' => 'heading', 'attrs' => ['level' => 1, 'blockId' => 'h1'], 'content' => [['type' => 'text', 'text' => 'B']]],
        ],
    ];

    $copied = BlockIdentity::regenerateAll($doc, $gen);

    expect(BlockIdentity::extractIds($copied))->toBe(['copy_1', 'copy_2']);
});

it('rejects duplicate or malformed blockIds in TipTapContent::fromArray', function (): void {
    $duplicate = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'p42'], 'content' => [['type' => 'text', 'text' => 'A']]],
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'p42'], 'content' => [['type' => 'text', 'text' => 'B']]],
        ],
    ];

    expect(fn () => TipTapContent::fromArray($duplicate, TipTapSchema::richText()))
        ->toThrow(InvalidDocumentContentException::class);

    $malformed = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'bad id!'], 'content' => [['type' => 'text', 'text' => 'A']]],
        ],
    ];

    expect(fn () => TipTapContent::fromArray($malformed, TipTapSchema::richText()))
        ->toThrow(InvalidDocumentContentException::class);
});

it('preserves bold and link marks under block p42 in PHP serialization and rendering (3.1-B)', function (): void {
    $doc = [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'attrs' => ['blockId' => 'p42'],
                'content' => [
                    ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => '15'],
                    ['type' => 'text', 'text' => ' '],
                    [
                        'type' => 'text',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com', 'target' => '_blank']]],
                        'text' => 'docs',
                    ],
                ],
            ],
        ],
    ];

    $content = TipTapContent::fromArray($doc, TipTapSchema::richText());
    expect($content->toArray()['content'][0]['attrs']['blockId'])->toBe('p42');
    expect($content->toArray()['content'][0]['content'][0]['marks'])->toBe([['type' => 'bold']]);

    $renderer = app(TipTapRenderer::class);
    expect($renderer->toHtml($content))->toContain('<strong>15</strong>');
    expect($renderer->toHtml($content))->toContain('https://example.com');
    expect($renderer->toMarkdown($content))->toContain('**15**');
    expect($renderer->toMarkdown($content))->toContain('[docs](https://example.com)');
});

it('keeps markdown schema closed without blockId and working as before (3.1-B)', function (): void {
    expect(TipTapSchema::markdown()->attributes('paragraph'))->toBe([]);
    expect(TipTapSchema::markdown()->attributes('heading'))->toBe(['level']);

    $withBlockId = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'p1'], 'content' => [['type' => 'text', 'text' => 'hi']]],
        ],
    ];

    expect(fn () => TipTapContent::fromArray($withBlockId, TipTapSchema::markdown()))
        ->toThrow(InvalidDocumentContentException::class);
});

it('assigns block IDs on creation when enabled, preserves on reopen, and does not mutate on GET (3.1-A)', function (): void {
    Config::set('vaulter-documents.tiptap.block_ids', true);

    $docs = Documents::in(Docs::drive());

    $node = $docs->create('Spec', RichTextDocumentType::KEY, [
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Intro']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => '15']]],
        ],
    ]);

    $first = $docs->content($node);
    $ids = BlockIdentity::extractIds($first->content->toArray());

    expect($first->schemaVersion)->toBe(2);
    expect($ids)->toHaveCount(2);
    expect(array_unique($ids))->toHaveCount(2);

    // Reopen / GET must not change ETag, state_version, or block IDs.
    $second = $docs->content($node);
    expect($second->stateVersion)->toBe($first->stateVersion);
    expect($second->epoch)->toBe($first->epoch);
    expect(BlockIdentity::extractIds($second->content->toArray()))->toBe($ids);
});

it('normalizes legacy v1 rich-text document to v2 via CAS, rejects stale CAS, and is idempotent on repeat (3.1-A)', function (): void {
    Config::set('vaulter-documents.tiptap.block_ids', false);

    $docs = Documents::in(Docs::drive());

    $node = $docs->create('Legacy', RichTextDocumentType::KEY, [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]],
        ],
    ]);

    $before = $docs->content($node);
    expect($before->schemaVersion)->toBe(1);
    expect(BlockIdentity::extractIds($before->content->toArray()))->toBe([]);

    // Competing normalization with stale expectedStateVersion fails CAS.
    expect(fn () => $docs->initializeBlockIds($node, expectedStateVersion: $before->stateVersion + 99))
        ->toThrow(StaleDocumentStateException::class);

    // Authorized normalization transitions v1 -> v2.
    $normalized = $docs->initializeBlockIds($node, expectedStateVersion: $before->stateVersion);
    expect($normalized->schemaVersion)->toBe(2);
    expect($normalized->stateVersion)->toBe($before->stateVersion + 1);
    $ids = BlockIdentity::extractIds($normalized->content->toArray());
    expect($ids)->toHaveCount(1);

    // Competing normalization with the old state_version now fails CAS.
    expect(fn () => $docs->initializeBlockIds($node, expectedStateVersion: $before->stateVersion))
        ->toThrow(StaleDocumentStateException::class);

    // Repeating normalization on already-v2 doc does NOT create a second version.
    $repeated = $docs->initializeBlockIds($node);
    expect($repeated->stateVersion)->toBe($normalized->stateVersion);
    expect(BlockIdentity::extractIds($repeated->content->toArray()))->toBe($ids);
});

it('restores block IDs from the restored revision and advances collaboration epoch (3.1-A)', function (): void {
    Config::set('vaulter-documents.tiptap.block_ids', true);

    $docs = Documents::in(Docs::drive());

    $node = $docs->create('Versioned', RichTextDocumentType::KEY, [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'p42'], 'content' => [['type' => 'text', 'text' => 'v1']]],
        ],
    ]);
    $rev1 = $docs->snapshot($node, 'v1');
    $v1State = $docs->content($node);

    $docs->update($node, [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'p99'], 'content' => [['type' => 'text', 'text' => 'v2']]],
        ],
    ]);

    $restored = $docs->restore($node, $rev1);

    expect(BlockIdentity::extractIds($restored->content->toArray()))->toBe(['p42']);
    expect($restored->epoch)->toBe($v1State->epoch + 1);
});

it('creates and reads markdown documents normally when block_ids is enabled', function (): void {
    Config::set('vaulter-documents.tiptap.block_ids', true);

    $docs = Documents::in(Docs::drive());

    $node = $docs->create('Notes.md', MarkdownDocumentType::KEY, [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'plain']]],
        ],
    ]);

    $snap = $docs->content($node);
    expect($snap->schemaVersion)->toBe(1);
    expect(BlockIdentity::extractIds($snap->content->toArray()))->toBe([]);
});
