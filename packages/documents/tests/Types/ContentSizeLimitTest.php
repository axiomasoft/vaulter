<?php

declare(strict_types=1);

use Vaulter\Documents\Content\ContentHasher;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;

/**
 * Every document type is size-limited by the decoder, not only TipTap —
 * by its codec's maxSizeBytes(), else by vaulter-documents.content.max_size_kb. A
 * spreadsheet used to be unlimited. Content of exactly the limit is accepted.
 */

/** @return array<string, mixed> an object whose stored size is exactly $bytes */
function contentOfSize(int $bytes, string $field): array
{
    return [$field => str_repeat('x', $bytes - ContentHasher::size([$field => '']))];
}

dataset('size-limited types', [
    // The opaque note codec has no limit of its own: the global one applies.
    'opaque note, global limit' => ['acme/opaque-note', 'notes', 1024],
    // acme/kanban's codec allows 4096 bytes, which wins over the 1 KB global limit.
    'own codec limit' => ['acme/kanban', 'columns', 4096],
]);

beforeEach(function (): void {
    config()->set('vaulter-documents.content.max_size_kb', 1);
});

it('creates content of exactly the limit and refuses one byte more', function (string $type, string $field, int $limit): void {
    $documents = Documents::in(DocumentTestHelpers::drive());

    $node = $documents->create('At the limit', type: $type, content: contentOfSize($limit, $field));
    expect(Document::query()->where('node_id', $node->id)->firstOrFail()->payload?->content_size)->toBe($limit);

    expect(fn () => $documents->create('Over the limit', type: $type, content: contentOfSize($limit + 1, $field)))
        ->toThrow(InvalidDocumentContentException::class, sprintf('(%d bytes > %d bytes)', $limit + 1, $limit));
})->with('size-limited types');

it('updates to exactly the limit and refuses one byte more without writing', function (string $type, string $field, int $limit): void {
    $documents = Documents::in(DocumentTestHelpers::drive());
    $node = $documents->create('Grows', type: $type, content: [$field => '']);

    $documents->update($node->id, contentOfSize($limit, $field));
    $before = Document::query()->where('node_id', $node->id)->firstOrFail();
    expect($before->payload?->content_size)->toBe($limit);

    expect(fn () => $documents->update($node->id, contentOfSize($limit + 1, $field)))
        ->toThrow(InvalidDocumentContentException::class, sprintf('(%d bytes > %d bytes)', $limit + 1, $limit));
    expect(Document::query()->where('node_id', $node->id)->firstOrFail()->state_version)->toBe($before->state_version);
})->with('size-limited types');

it('measures non-Latin text by its UTF-8 bytes against the limit', function (): void {
    $documents = Documents::in(DocumentTestHelpers::drive());
    // 400 Cyrillic letters are 800 bytes of UTF-8 but were 2400 bytes as \uXXXX escapes, over the 1 KB limit.
    $content = ['notes' => str_repeat('ж', 400)];

    $node = $documents->create('Cyrillic', type: 'acme/opaque-note', content: $content);

    expect(Document::query()->where('node_id', $node->id)->firstOrFail()->payload?->content_size)
        ->toBe(strlen('{"notes":""}') + 800);
});
