<?php

declare(strict_types=1);

use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Content\OpaqueJsonCodec;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Contracts\SupportsCollaboration;
use Vaulter\Documents\Data\Types\DocumentFeatures;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\Testing\Contracts\DocumentTypeContractTests;
use Vaulter\Documents\Tests\Fixtures\Types\AcmeKanbanType;
use Vaulter\Documents\Tests\Fixtures\Types\OpaqueNoteType;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;

/**
 * The built-in types (and a third-party one) keep the document type
 * contract; markdown accepts only what Markdown can represent.
 */
uses(DocumentTypeContractTests::class);

it('keeps the document type contract', function (DocumentType $type): void {
    $this->assertDocumentTypeContract($type);
})->with([
    'rich text' => fn (): DocumentType => new RichTextDocumentType,
    'markdown' => fn (): DocumentType => new MarkdownDocumentType,
    'opaque note' => fn (): DocumentType => new OpaqueNoteType,
    'third party' => fn (): DocumentType => new AcmeKanbanType,
]);

it('describes each built-in type by key, viewer and collaboration', function (): void {
    expect(DocumentFeatures::readOnly()->toArray())
        ->toBe(['editable' => false, 'previewable' => true, 'thumbnailable' => false, 'downloadable' => false]);

    $rich = new RichTextDocumentType;
    $markdown = new MarkdownDocumentType;
    $opaque = new OpaqueNoteType;

    expect($rich->key())->toBe('vaulter/rich-text')
        ->and($rich->viewer()->toArray())->toBe(['key' => 'vaulter/tiptap', 'props' => ['preset' => 'rich-text']])
        ->and($rich)->toBeInstanceOf(SupportsCollaboration::class)
        ->and($rich->collaborationProtocol())->toBe('yjs')
        ->and($rich->features()->toArray())->toBe(['editable' => true, 'previewable' => true, 'thumbnailable' => false, 'downloadable' => false])
        ->and($markdown->key())->toBe('vaulter/markdown')
        ->and($markdown->viewer()->toArray())->toBe(['key' => 'vaulter/tiptap', 'props' => ['preset' => 'markdown']])
        ->and($markdown)->toBeInstanceOf(SupportsCollaboration::class)
        ->and($opaque->key())->toBe('acme/opaque-note')
        ->and($opaque->viewer()->key)->toBe('acme/opaque-note')
        ->and($opaque)->not->toBeInstanceOf(SupportsCollaboration::class)
        ->and($opaque->features()->editable)->toBeTrue()
        ->and($opaque->codec())->toBeInstanceOf(OpaqueJsonCodec::class);
});

it('restricts markdown to the nodes and marks Markdown can represent', function (array $doc): void {
    expect(fn (): DocumentContent => (new MarkdownDocumentType)->codec()->decode($doc, 1))->toThrow(InvalidDocumentContentException::class);
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
    expect(fn (): DocumentContent => (new MarkdownDocumentType)->codec()->decode($doc, 1))->toThrow(InvalidDocumentContentException::class);
});

it('accepts any JSON object for an opaque type and refuses a list', function (): void {
    $codec = (new OpaqueNoteType)->codec();

    expect($codec->decode(['sheets' => ['s1' => []]], 1))->toBeInstanceOf(OpaqueContent::class)
        ->and($codec->decode([], 1)->toArray())->toBe([]);
    expect(fn (): DocumentContent => $codec->decode([1, 2, 3], 1))->toThrow(InvalidDocumentContentException::class);
});
