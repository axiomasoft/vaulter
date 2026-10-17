<?php

declare(strict_types=1);

namespace Vaulter\Drive\Access\ValueObjects;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Models\Node;

/**
 * Per-node computed permissions for the front-end, generalising the
 * legacy DocumentAbilities pattern to the unified node tree. It asks the
 * AccessResolver for the full ability map of an actor on a node and exposes it as
 * the stable "abilities" object the Vue client binds against — so the UI renders
 * only the actions the actor may perform.
 *
 * When there is no acting user (an unauthenticated/system context), every flag is
 * false: the client shows a read-only shell rather than mis-advertising actions.
 */
#[Internal]
final readonly class NodeAbilities
{
    /**
     * @param array<string, bool> $flags ability value => allowed
     */
    public function __construct(
        public array $flags,
    ) {}

    public static function for(?Authorizable $actor, Node $node, AccessResolver $resolver): self
    {
        if ($actor === null) {
            return self::denied();
        }

        return new self(flags: $resolver->abilitiesFor($actor, $node));
    }

    /** Reuse the exact batch decision when serializing a listing. */
    public static function fromGrantSet(GrantSet $set): self
    {
        $flags = [];
        foreach (Ability::cases() as $ability) {
            $flags[$ability->value] = $set->allows($ability);
        }

        return new self(flags: $flags);
    }

    public static function denied(): self
    {
        $flags = [];

        foreach (Ability::cases() as $ability) {
            $flags[$ability->value] = false;
        }

        return new self(flags: $flags);
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return $this->flags;
    }
}
