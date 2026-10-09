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
