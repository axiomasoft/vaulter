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
    $large = ['type' => 'doc', 'content' => array_fill(0, 2000, str_repeat('x', 100))];

    TipTapContent::fromArray($large)->ensureWithin(1);
})->throws(InvalidTipTapContentException::class);
