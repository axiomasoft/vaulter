<?php

declare(strict_types=1);

namespace Vaulter\Drive\Support;

use Normalizer;
use Packman\Attributes\Internal;
use Vaulter\Drive\Exceptions\Nodes\InvalidNodeNameException;
use Vaulter\Drive\Naming\NamePolicy;

/**
 * The canonical form of a node name and its comparison key. A name is a single
 * tree segment, so separators, control characters and the `.`/`..` path aliases
 * are rejected rather than escaped. NFC keeps visually identical names (a
 * precomposed `é` and `e` + combining accent) from coexisting as siblings; without
 * ext-intl the name is kept as given.
 *
 * Nothing is trimmed: a name with edge whitespace is either kept exactly (the
 * permissive policy) or refused (the portable policy) — never silently changed.
 * These base rules hold under every {@see NamePolicy}.
 */
#[Internal]
final class NodeName
{
    /** The width of the v_nodes.name column. */
    public const int MAX_BYTES = 255;

    public static function normalize(string $name): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            throw InvalidNodeNameException::forName($name, InvalidNodeNameException::RESERVED_CHAR);
        }

        $name = self::nfc($name);

        if ($name === '') {
            throw InvalidNodeNameException::forName($name, InvalidNodeNameException::EMPTY);
        }

        if ($name === '.' || $name === '..') {
            throw InvalidNodeNameException::forName($name, InvalidNodeNameException::PATH_ALIAS);
        }

        if (preg_match('~[/\\\\]~', $name) === 1) {
            throw InvalidNodeNameException::forName($name, InvalidNodeNameException::SEPARATOR);
        }

        if (preg_match('/[\x{00}-\x{1F}\x{7F}-\x{9F}]/u', $name) === 1) {
            throw InvalidNodeNameException::forName($name, InvalidNodeNameException::CONTROL_CHAR);
        }

        if (strlen($name) > self::MAX_BYTES) {
            throw InvalidNodeNameException::forName($name, InvalidNodeNameException::TOO_LONG);
        }

        return $name;
    }

    /**
     * The sibling-uniqueness key: NFC(casefold(NFC(name))). Compared byte for byte
     * (PG COLLATE "C", MySQL utf8mb4_bin, SQLite BINARY), so the contract never
     * depends on the host database collation. Full case folding maps "ß" to "ss"
     * and "Σ"/"ς" to "σ", which LOWER() does not. The fold follows the Unicode
     * version of the running mbstring; changing it needs a recompute migration.
     */
    public static function key(string $name): string
    {
        return self::nfc(mb_convert_case(self::nfc($name), MB_CASE_FOLD, 'UTF-8'));
    }

    private static function nfc(string $name): string
    {
        if (!class_exists(Normalizer::class)) {
            return $name;
        }

        $normalized = Normalizer::normalize($name, Normalizer::FORM_C);

        return is_string($normalized) ? $normalized : $name;
    }
}
