<?php

declare(strict_types=1);

namespace Vaulter\Testing\Contracts;

use Illuminate\Contracts\Auth\Access\Authorizable;
use PHPUnit\Framework\Assert;
use Vaulter\Access\AccessResolver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Models\Drive\Node;

/**
 * Reusable assertions for a host driver in pass-through mode. The host supplies
 * a Writer subtree allow on $parent and a Writer item deny on $child for $actor.
 * No override or role permission should be enabled on that fixture.
 */
final class PermissionDriverContractTests
{
    public function assertPermissionDriverContract(AccessResolver $resolver, Authorizable $actor, Node $parent, Node $child): void
    {
        Assert::assertTrue($resolver->can($actor, Ability::Edit, $parent));
        Assert::assertTrue($resolver->can($actor, Ability::Comment, $parent));
        Assert::assertFalse($resolver->can($actor, Ability::View, $child));
        Assert::assertFalse($resolver->can($actor, Ability::Edit, $child));
        Assert::assertFalse($resolver->can($actor, Ability::Comment, $child));
        $sets = $resolver->evaluateMany($actor, [$parent, $child]);
        foreach ([$parent, $child] as $node) {
            foreach (Ability::cases() as $ability) {
                Assert::assertSame($resolver->can($actor, $ability, $node), $sets[(string) $node->id]->allows($ability));
                Assert::assertSame($resolver->can($actor, $ability, $node), $resolver->explain($actor, $ability, $node)->allowed);
            }
        }
    }
}
