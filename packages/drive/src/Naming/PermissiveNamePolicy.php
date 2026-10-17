<?php

declare(strict_types=1);

namespace Vaulter\Drive\Naming;

use Vaulter\Attributes\Api;
use Vaulter\Drive\Support\NodeName;

/**
 * permissive-v1: only the base rules every name obeys (a non-empty single segment,
 * no separators or control characters, not "."/"..", at most 255 bytes). Names
 * Windows or macOS cannot store, such as "a:b" or "CON.txt", are accepted.
 */
#[Api]
final readonly class PermissiveNamePolicy implements NamePolicy
{
    public const string KEY = 'permissive-v1';

    public function key(): string
    {
        return self::KEY;
    }

    public function apply(string $name): string
    {
        return NodeName::normalize($name);
    }
}
