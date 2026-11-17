<?php

declare(strict_types=1);

namespace Vaulter\Drive\Naming;

use Packman\Attributes\Api;
use Vaulter\Drive\Exceptions\Nodes\InvalidNodeNameException;
use Vaulter\Drive\Support\NodeName;

/**
 * portable-v1 (the default): names every desktop OS and ZIP export can hold. On top
 * of the base rules it refuses the Windows reserved characters < > : " | ? *, edge
 * whitespace, a trailing dot, the Windows device names (CON, PRN, AUX, NUL,
 * COM0–COM9, LPT0–LPT9 and their superscript-digit forms, with any extension, in
 * any case), desktop.ini and .DS_Store, and names over 255 UTF-16 code units.
 */
#[Api]
final readonly class PortableNamePolicy implements NamePolicy
{
    public const string KEY = 'portable-v1';

    private const string DEVICE_NAME = '/^(?:CON|PRN|AUX|NUL|COM[0-9¹²³]|LPT[0-9¹²³])$/iu';

    private const array RESERVED_FILES = ['desktop.ini', '.ds_store'];

    public function key(): string
    {
        return self::KEY;
    }

    public function apply(string $name): string
    {
        $name = NodeName::normalize($name);

        if (preg_match('/[<>:"|?*]/', $name) === 1) {
            throw InvalidNodeNameException::forName($name, InvalidNodeNameException::RESERVED_CHAR);
        }

        if (preg_match('/^[\s\p{Z}]|[\s\p{Z}]$/u', $name) === 1) {
            throw InvalidNodeNameException::forName($name, InvalidNodeNameException::LEADING_OR_TRAILING_SPACE);
        }

        if (str_ends_with($name, '.')) {
            throw InvalidNodeNameException::forName($name, InvalidNodeNameException::TRAILING_DOT_OR_SPACE);
        }

        $stem = explode('.', $name, 2)[0];
        if (preg_match(self::DEVICE_NAME, $stem) === 1 || in_array(mb_strtolower($name, 'UTF-8'), self::RESERVED_FILES, true)) {
            throw InvalidNodeNameException::forName($name, InvalidNodeNameException::RESERVED_NAME);
        }

        if (strlen(mb_convert_encoding($name, 'UTF-16LE', 'UTF-8')) / 2 > NodeName::MAX_BYTES) {
            throw InvalidNodeNameException::forName($name, InvalidNodeNameException::TOO_LONG);
        }

        return $name;
    }
}
