<?php

declare(strict_types=1);

namespace VaulterRef\Documents\Content;

use JsonException;

/**
 * Copy of the existing Vaulter\Documents\Content\ContentHasher (packages/documents/src/Content/ContentHasher.php):
 * sha256 over json_encode() with default flags after a recursive ksort(SORT_STRING) of maps. It defines
 * v_document_payloads.content_hash and nothing else. It is NOT the JCS digest of an office proposal (default
 * flags escape "/" and non-ASCII, ksort orders bytes, not UTF-16 units). One deliberate difference: the
 * original casts a failed json_encode() to "" and hashes the empty string; here the failure is thrown.
 */
final class ContentHasher
{
    /** @param array<mixed> $content */
    public static function hash(array $content): string
    {
        return hash('sha256', self::encode($content));
    }

    /** @param array<mixed> $content */
    public static function encode(array $content): string
    {
        try {
            return json_encode(self::canonical($content), JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new \InvalidArgumentException('Content is not encodable JSON: '.$error->getMessage(), 0, $error);
        }
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
