<?php

declare(strict_types=1);

use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Content\OpaqueJsonCodec;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Contracts\SupportsCollaboration;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\Testing\Contracts\DocumentTypeContractTests;
use Vaulter\Documents\Tests\Fixtures\Types\AcmeKanbanType;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Documents\Types\SpreadsheetDocumentType;

/**
 * PLAN2.P4.3: the built-in types (and a third-party one) keep the document type
 * contract; markdown accepts only what Markdown can represent.
 */
uses(DocumentTypeContractTests::class);

it('keeps the document type contract', function (DocumentType $type): void {
    $this->assertDocumentTypeContract($type);
})->with([
    'rich text' => fn (): DocumentType => new RichTextDocumentType,
    'markdown' => fn (): DocumentType => new MarkdownDocumentType,
    'spreadsheet' => fn (): DocumentType => new SpreadsheetDocumentType,
    'third party' => fn (): DocumentType => new AcmeKanbanType,
]);

it('describes each built-in type by key, viewer and collaboration', function (): void {
    $rich = new RichTextDocumentType;
    $markdown = new MarkdownDocumentType;
    $sheet = new SpreadsheetDocumentType;

    expect($rich->key())->toBe('vaulter/rich-text')
        ->and($rich->viewer()->toArray())->toBe(['key' => 'vaulter/tiptap', 'props' => ['preset' => 'rich-text']])
        ->and($rich)->toBeInstanceOf(SupportsCollaboration::class)
        ->and($rich->collaborationProtocol())->toBe('yjs')
        ->and($rich->capabilities()->collaborative)->toBeTrue()
        ->and($markdown->key())->toBe('vaulter/markdown')
        ->and($markdown->viewer()->toArray())->toBe(['key' => 'vaulter/tiptap', 'props' => ['preset' => 'markdown']])
        ->and($markdown)->toBeInstanceOf(SupportsCollaboration::class)
        ->and($sheet->key())->toBe('vaulter/spreadsheet')
        ->and($sheet->viewer()->key)->toBe('vaulter/univer')
        ->and($sheet)->not->toBeInstanceOf(SupportsCollaboration::class)
        ->and($sheet->capabilities()->collaborative)->toBeFalse()
        ->and($sheet->codec())->toBeInstanceOf(OpaqueJsonCodec::class);
});

it('restricts markdown to the nodes and marks Markdown can represent', function (array $doc): void {
    expect(fn () => (new MarkdownDocumentType)->codec()->decode($doc, 1))->toThrow(InvalidDocumentContentException::class);
    expect((new RichTextDocumentType)->codec()->decode($doc, 1))->toBeInstanceOf(TipTapContent::class);
})->with([
    'image' => [['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => 'https://example.com/a.png']]]]],
    'table' => [['type' => 'doc', 'content' => [['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [['type' => 'paragraph']]]]]]]]]],
    'underline' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'u', 'marks' => [['type' => 'underline']]]]]]]],
]);

it('keeps the markdown schema closed to host editor extensions', function (): void {
    config()->set('vaulter-documents.tiptap.extra_nodes', ['callout']);
    $doc = ['type' => 'doc', 'content' => [['type' => 'callout']]];

    expect((new RichTextDocumentType)->codec()->decode($doc, 1))->toBeInstanceOf(TipTapContent::class);
    expect(fn () => (new MarkdownDocumentType)->codec()->decode($doc, 1))->toThrow(InvalidDocumentContentException::class);
});

it('accepts any JSON object for an opaque type and refuses a list', function (): void {
    $codec = (new SpreadsheetDocumentType)->codec();

    expect($codec->decode(['sheets' => ['s1' => []]], 1))->toBeInstanceOf(OpaqueContent::class)
        ->and($codec->decode([], 1)->toArray())->toBe([]);
    expect(fn () => $codec->decode([1, 2, 3], 1))->toThrow(InvalidDocumentContentException::class);
});
