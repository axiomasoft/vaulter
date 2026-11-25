<?php

declare(strict_types=1);

namespace Vaulter\Documents\Content;

use Packman\Attributes\Api;

/**
 * The content_hash and content_size every document content carries: sha256 and byte
 * length of the JSON encoding of the payload. One definition for all codecs, so the
 * dedup of an unchanged autosave compares like with like whatever the type.
 *
 * Object keys are sorted before encoding: a jsonb column (PostgreSQL) hands objects back
 * with its own key order, so a copy or a restore that re-reads stored content must still
 * get the hash the content was stored with.
 */
#[Api]
final class ContentHasher
{
    /** @param array<array-key, mixed> $content */
    public static function hash(array $content): string
    {
        return hash('sha256', self::encode($content));
    }

    /**
     * The UTF-8 bytes of the content as JSON. Key order does not change the length, so nothing is sorted; non-ASCII
     * text and slashes are counted as the bytes they are, not as their `\uXXXX` / `\/` escapes, which made a Cyrillic
     * or CJK document count two to three times its size against the size limit and the drive quota.
     *
     * @param array<array-key, mixed> $content
     */
    public static function size(array $content): int
    {
        return strlen(json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<array-key, mixed> $content */
    private static function encode(array $content): string
    {
        return (string) json_encode(self::canonical($content));
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
