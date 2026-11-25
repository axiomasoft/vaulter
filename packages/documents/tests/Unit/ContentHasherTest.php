<?php

declare(strict_types=1);

use Vaulter\Documents\Content\ContentHasher;

it('hashes the same content identically whatever the order of object keys', function (): void {
    $written = ['type' => 'doc', 'content' => [['type' => 'text', 'attrs' => ['b' => 1, 'a' => 2], 'text' => 'x']]];
    // jsonb hands objects back shortest key first, not in the order they were written.
    $readBack = ['content' => [['text' => 'x', 'type' => 'text', 'attrs' => ['a' => 2, 'b' => 1]]], 'type' => 'doc'];

    expect(ContentHasher::hash($readBack))->toBe(ContentHasher::hash($written))
        ->and(ContentHasher::size($readBack))->toBe(ContentHasher::size($written));
});

it('keeps the order of list items significant', function (): void {
    expect(ContentHasher::hash(['content' => ['a', 'b']]))->not->toBe(ContentHasher::hash(['content' => ['b', 'a']]));
});

it('counts non-ASCII text and slashes as their UTF-8 bytes, not as JSON escapes', function (): void {
    $content = ['type' => 'doc', 'text' => 'Привет, 世界 🙂 https://example.test/a'];

    // 12 bytes of Cyrillic, 6 of CJK, 4 of emoji: escaped they were 36, 12 and 12 bytes, plus one byte per slash.
    expect(ContentHasher::size($content))->toBe(strlen('{"type":"doc","text":"Привет, 世界 🙂 https://example.test/a"}'))
        ->and(ContentHasher::size($content))->toBe(72);
});

it('refuses to size content that is not valid UTF-8 instead of calling it empty', function (): void {
    expect(fn () => ContentHasher::size(['text' => "\xB1\x31"]))->toThrow(JsonException::class);
});
