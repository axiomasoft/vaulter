<?php

declare(strict_types=1);

namespace Vaulter\Drive\Testing\Contracts;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Assert;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Profiles\ProfileRegistry;

/**
 * Reusable assertions for a host driver in pass-through mode. The host supplies a
 * writer subtree grant on $parent for $actor, and $child (below $parent) has its
 * inheritance restricted with no grant of its own. No override or role permission
 * should be enabled on that fixture.
 */
#[Api]
final class PermissionDriverContractTests
{
    #[Internal]
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

    /**
     * The driver must decide exactly like the reference database driver on the shared
     * fixture, for every ability on the parent and on the restricted child. Run it
     * for a driver that adds no roles, subjects or override of its own.
     */
    public function assertSameDecisionsAsReference(PermissionDriver $driver, ?PermissionFixture $fixture = null): void
    {
        $fixture ??= PermissionFixture::build();

        $reference = $this->decisions(new DatabasePermissionDriver, $fixture);
        $actual = $this->decisions($driver, $fixture);

        Assert::assertSame($reference, $actual, sprintf('%s decides differently from the reference driver on the shared fixture.', $driver::class));
        Assert::assertTrue($actual['parent.'.Ability::Edit->value], 'The fixture grants the actor edit on the parent.');
        Assert::assertFalse($actual['child.'.Ability::View->value], 'The fixture cuts the child off from its parent.');
    }

    /** @return array<string, bool> */
    private function decisions(PermissionDriver $driver, PermissionFixture $fixture): array
    {
        $config = Config::get('vaulter-drive.access');
        App::instance($driver::class, $driver);
        // A profile may pin its own driver key; the driver under test must answer for that key too.
        $key = App::make(ProfileRegistry::class)->get($fixture->drive->profile)->getPermissionDriver() ?? 'contract';
        Config::set('vaulter-drive.access.permission_drivers.'.$key, $driver::class);
        Config::set('vaulter-drive.access.permission_driver', $key);
        App::forgetInstance(PermissionDriverManager::class);
        App::forgetInstance(AccessResolver::class);

        try {
            $resolver = App::make(AccessResolver::class);
            $decisions = [];
            // The fixture names its nodes by snapshot; the resolver decides on the current rows, read by id here.
            $nodes = App::make(DriveConfig::class)->model('node');
            foreach (['parent' => $fixture->parent, 'child' => $fixture->child] as $label => $snapshot) {
                $node = $nodes::query()->findOrFail($snapshot->id);
                foreach (Ability::cases() as $ability) {
                    $decisions[$label.'.'.$ability->value] = $resolver->can($fixture->actor, $ability, $node);
                }
            }

            return $decisions;
        } finally {
            Config::set('vaulter-drive.access', $config);
            App::forgetInstance(PermissionDriverManager::class);
            App::forgetInstance(AccessResolver::class);
        }
    }
}
