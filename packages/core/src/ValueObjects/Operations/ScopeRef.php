<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Operations;

use InvalidArgumentException;
use Vaulter\Attributes\Api;
use Vaulter\Support\Identity;

/**
 * What an operation acts within, as a neutral tagged reference: a kind (for example
 * `drive`) and the string id of that resource. The kernel never loads the resource;
 * the owning module maps its own models to and from this reference.
 */
#[Api]
final readonly class ScopeRef
{
    public const string DRIVE = 'drive';

    public function __construct(
        public string $kind,
        public string $id,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,31}$/', $kind) !== 1) {
            throw new InvalidArgumentException('[Vaulter] A scope kind must be a lowercase identifier of at most 32 bytes.');
        }
        if ($id === '' || strlen($id) > 191) {
            throw new InvalidArgumentException('[Vaulter] A scope id must be nonempty and at most 191 bytes.');
        }
    }

    public static function drive(int|string $id): self
    {
        return new self(kind: self::DRIVE, id: Identity::stringify($id));
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->id === $other->id;
    }
}
