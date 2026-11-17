<?php

declare(strict_types=1);

namespace Vaulter\Drive\Access\Roles;

use Packman\Attributes\Api;
use Vaulter\Drive\Enums\Access\Ability;

/**
 * A named, versioned set of abilities. A grant stores (key, version), so changing
 * what a role may do means a new version plus an explicit migration of the grants
 * that should move to it; a stored version keeps its meaning forever.
 */
#[Api]
final readonly class Role
{
    public int $bits;

    /** @param list<Ability> $abilities */
    public function __construct(
        public string $key,
        public int $version,
        public array $abilities,
    ) {
        $this->bits = Ability::mask($abilities);
    }

    public function allows(Ability $ability): bool
    {
        return $ability->satisfiedBy($this->bits);
    }

    /** Whether this role carries every ability of $other. */
    public function covers(self $other): bool
    {
        return ($this->bits & $other->bits) === $other->bits;
    }

    public function is(self $other): bool
    {
        return $this->key === $other->key && $this->version === $other->version;
    }

    /**
     * The role among $roles that carries the abilities of all of them, or null when
     * their union is not one of them. The built-in roles nest, so for them the union
     * is always one of the roles.
     *
     * @param list<self> $roles
     */
    public static function covering(array $roles): ?self
    {
        foreach ($roles as $candidate) {
            $all = array_all($roles, fn ($role) => $candidate->covers($role));
            if ($all) {
                return $candidate;
            }
        }

        return null;
    }
}
