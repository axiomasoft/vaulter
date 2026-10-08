<?php

declare(strict_types=1);

namespace Vaulter\Documents\Content;

/**
 * The content_hash and content_size every document content carries: sha256 and byte
 * length of the JSON encoding of the payload. One definition for all codecs, so the
 * dedup of an unchanged autosave compares like with like whatever the type.
 */
final class ContentHasher
{
    /** @param array<array-key, mixed> $content */
    public static function hash(array $content): string
    {
        return hash('sha256', self::encode($content));
    }

    /** @param array<array-key, mixed> $content */
    public static function size(array $content): int
    {
        return strlen(self::encode($content));
    }

    /** @param array<array-key, mixed> $content */
    private static function encode(array $content): string
    {
        return (string) json_encode($content);
    }
}
