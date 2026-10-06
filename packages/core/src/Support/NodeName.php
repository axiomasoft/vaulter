<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Normalizer;
use Vaulter\Exceptions\Nodes\InvalidNodeNameException;

/**
 * The canonical form of a node name, applied by every Action that writes one. A name
 * is a single tree segment, so separators, control characters and the `.`/`..` path
 * aliases are rejected rather than escaped. NFC keeps visually identical names (a
 * precomposed `é` and `e` + combining accent) from coexisting as siblings; without
 * ext-intl the name is kept as given.
 */
final class NodeName
{
    public static function normalize(string $name, int $maxLength = 255): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            throw InvalidNodeNameException::forName($name, 'it is not valid UTF-8');
        }

        $name = trim($name, " \t\n\r\x0B");

        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($name, Normalizer::FORM_C);
            $name = is_string($normalized) ? $normalized : $name;
        }

        if ($name === '') {
            throw InvalidNodeNameException::forName($name, 'it is empty');
        }

        if ($name === '.' || $name === '..') {
            throw InvalidNodeNameException::forName($name, 'it is a path alias');
        }

        if (preg_match('~[/\\\\]~', $name) === 1) {
            throw InvalidNodeNameException::forName($name, 'it contains a path separator');
        }

        if (preg_match('/[\x{00}-\x{1F}\x{7F}-\x{9F}]/u', $name) === 1) {
            throw InvalidNodeNameException::forName($name, 'it contains a control character');
        }

        if (strlen($name) > $maxLength) {
            throw InvalidNodeNameException::forName($name, "it is longer than {$maxLength} bytes");
        }

        return $name;
    }
}
