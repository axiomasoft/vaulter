<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Operations;

use InvalidArgumentException;
use Vaulter\Support\Identity;

/**
 * The tenant an operation runs for, tagged so that "no tenant" is an explicit value
 * (`none`) rather than a null that would weaken uniqueness or comparisons.
 */
final readonly class TenantRef
{
    public const string NONE = 'none';

    public const string ID = 'id';

    private function __construct(
        public string $kind,
        public ?string $id,
    ) {}

    public static function none(): self
    {
        return new self(kind: self::NONE, id: null);
    }

    public static function id(int|string $id): self
    {
        $id = Identity::stringify($id);
        if ($id === '' || strlen($id) > 191) {
            throw new InvalidArgumentException('[Vaulter] A tenant id must be nonempty and at most 191 bytes.');
        }

        return new self(kind: self::ID, id: $id);
    }

    /** A host tenant key as stored on a row: null or empty means no tenant. */
    public static function of(mixed $key): self
    {
        $id = Identity::stringify($key);

        return $id === '' ? self::none() : self::id($id);
    }

    public function isNone(): bool
    {
        return $this->kind === self::NONE;
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->id === $other->id;
    }
}
