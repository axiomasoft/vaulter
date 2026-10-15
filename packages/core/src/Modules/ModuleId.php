<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use InvalidArgumentException;
use Stringable;
use Vaulter\Attributes\Api;

/**
 * The stable name of a Vaulter module (`core`, `drive`, `webhooks`): the key of `vaulter.modules`
 * overrides, of `requires()` and of every diagnostic.
 */
#[Api]
final readonly class ModuleId implements Stringable
{
    public const string PATTERN = '/^[a-z][a-z0-9-]{1,31}$/';

    public function __construct(public string $value)
    {
        if (!self::isValid($value)) {
            throw new InvalidArgumentException(sprintf('[Vaulter] "%s" is not a module id: expected %s.', $value, self::PATTERN));
        }
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
