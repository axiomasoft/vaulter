<?php

declare(strict_types=1);

use Vaulter\Access\AccessResolver;
use Vaulter\Access\HostRoleDriverManager;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Contracts\Access\HostRoleDriver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\Access\RoleStubDriver;

it('matches individual ACL resolution on 500 seeded random trees', function (): void {
    $seed = 20261002;
    mt_srand($seed);
    $actor = AccessTestHelpers::user();
    $storage = AccessTestHelpers::storage();
    $levels = GrantLevel::cases();

    for ($tree = 0; $tree < 500; $tree++) {
        $subjects = array_map(static fn (int $id): Subject => Subject::ofModel(AccessTestHelpers::user($id)), range(1, mt_rand(1, 3)));
        $driver = Mockery::mock(HostRoleDriver::class);
        $driver->shouldReceive('subjectsFor')->andReturn($subjects);
        $driver->shouldReceive('override')->andReturn(null);
        $driver->shouldReceive('resolve')->andReturn(null);
        app()->instance(HostRoleDriver::class, $driver);
        config()->set('vaulter.permission_drivers.equivalence', HostRoleDriver::class);
        app()->forgetInstance(HostRoleDriverManager::class);
        app()->forgetInstance(AccessResolver::class);
        config()->set('vaulter.drivers.permission', 'equivalence');
        $nodes = [];
        $depths = [];
        $count = mt_rand(8, 15);

        for ($i = 0; $i < $count; $i++) {
            $parents = array_keys(array_filter($depths, static fn (int $depth): bool => $depth < 5));
            $parentIndex = $parents === [] || mt_rand(0, 3) === 0 ? null : $parents[mt_rand(0, count($parents) - 1)];
            $node = AccessTestHelpers::folder($storage, $parentIndex === null ? null : $nodes[$parentIndex], "tree-{$tree}-{$i}", mt_rand(0, 4) !== 0);
            $nodes[] = $node;
            $depths[] = $parentIndex === null ? 1 : $depths[$parentIndex] + 1;

            $seen = [];
            for ($j = 0, $grants = mt_rand(0, 4); $j < $grants; $j++) {
                $effect = mt_rand(0, 1) === 0 ? Effect::Allow : Effect::Deny;
                $scope = mt_rand(0, 1) === 0 ? GrantScope::Item : GrantScope::Subtree;
                $subjectId = mt_rand(1, 3);
                $key = $effect->value.':'.$scope->value.':'.$subjectId;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                AccessTestHelpers::grant(
                    $node,
                    $levels[mt_rand(0, count($levels) - 1)],
                    effect: $effect,
                    scope: $scope,
                    permissions: mt_rand(0, 31),
                    subjectId: $subjectId,
                    expiresAt: mt_rand(0, 4) === 0 ? now()->subMinute() : null,
                );
            }
        }

        $resolver = app(AccessResolver::class);
        $sets = $resolver->evaluateMany($actor, $nodes);
        foreach ($nodes as $node) {
            expect((array) $sets[(string) $node->id])->toBe((array) $resolver->effectiveGrants($actor, $node), "seed={$seed}, tree={$tree}, node={$node->id}");
        }
    }
});

it('preserves absolute overrides, empty subjects and role grants in batch', function (?Effect $override, ?GrantLevel $role, bool $subjects): void {
    $storage = AccessTestHelpers::storage();
    $nodes = [AccessTestHelpers::folder($storage, null, 'one'), AccessTestHelpers::folder($storage, null, 'two')];
    AccessTestHelpers::grant($nodes[0], GrantLevel::Owner, effect: Effect::Deny);
    $driver = new RoleStubDriver(roleLevel: $role, overrideEffect: $override, includeActor: $subjects);
    app()->instance(HostRoleDriver::class, $driver);
    config()->set('vaulter.permission_drivers.batch', HostRoleDriver::class);
    config()->set('vaulter.drivers.permission', 'batch');
    app()->forgetInstance(HostRoleDriverManager::class);
    app()->forgetInstance(AccessResolver::class);
    $resolver = app(AccessResolver::class);
    $actor = AccessTestHelpers::user();
    $batch = $resolver->evaluateMany($actor, $nodes);
    foreach ($nodes as $node) {
        expect((array) $batch[(string) $node->id])->toBe((array) $resolver->effectiveGrants($actor, $node));
    }
})->with([
    'super allow' => [Effect::Allow, null, true],
    'super deny' => [Effect::Deny, GrantLevel::Owner, true],
    'empty subjects' => [null, GrantLevel::Owner, false],
    'role and deny' => [null, GrantLevel::Owner, true],
]);

it('evaluates override and role once per storage and node owner and subjects once per batch', function (): void {
    $storageA = AccessTestHelpers::storage();
    $storageB = AccessTestHelpers::storage();
    $nodes = [
        AccessTestHelpers::folder($storageA, null, 'owner-a'),
        AccessTestHelpers::folder($storageA, null, 'owner-b'),
        AccessTestHelpers::folder($storageB, null, 'other-storage'),
        AccessTestHelpers::folder($storageA, null, 'denied-owner'),
    ];
    foreach ($nodes as $i => $node) {
        $node->forceFill(['owner_id' => $i === 3 ? '11' : '22'])->save();
    }
    $overrides = 0;
    $roles = 0;
    $subjectCalls = 0;
    $actor = AccessTestHelpers::user();
    $driver = Mockery::mock(HostRoleDriver::class);
    $driver->shouldReceive('subjectsFor')->andReturnUsing(function () use ($actor, &$subjectCalls): array {
        $subjectCalls++;

        return [Subject::ofModel($actor)];
    });
    $driver->shouldReceive('override')->andReturnUsing(function ($actor, $scope) use (&$overrides): ?Effect {
        $overrides++;

        return (string) $scope->nodeOwner?->id === '11' ? Effect::Deny : null;
    });
    $driver->shouldReceive('resolve')->andReturnUsing(function () use (&$roles) {
        $roles++;

        return Decision::allow(GrantLevel::Reader);
    });
    app()->instance(HostRoleDriver::class, $driver);
    config()->set('vaulter.permission_drivers.batch', HostRoleDriver::class);
    config()->set('vaulter.drivers.permission', 'batch');
    app()->forgetInstance(HostRoleDriverManager::class);
    app()->forgetInstance(AccessResolver::class);
    $resolver = app(AccessResolver::class);
    $batch = $resolver->evaluateMany($actor, $nodes);
    expect($overrides)->toBe(3)->and($roles)->toBe(2)->and($subjectCalls)->toBe(1);
    foreach ($nodes as $node) {
        expect((array) $batch[(string) $node->id])->toBe((array) $resolver->effectiveGrants($actor, $node));
    }
});

it('preserves deciding grants above the 64th ancestor', function (Effect $effect): void {
    $storage = AccessTestHelpers::storage();
    $root = AccessTestHelpers::folder($storage, null, 'deep-root');
    // Fixed-width bigint ids avoid the dependency's SQLite path suffix collisions.
    $root->forceFill(['id' => 10000])->save();
    AccessTestHelpers::grant($root, GrantLevel::Reader, effect: $effect);
    $parent = $root;
    for ($i = 0; $i < 66; $i++) {
        $parent = AccessTestHelpers::folder($storage, $parent, 'deep-'.$i);
    }
    $driver = new RoleStubDriver(roleLevel: $effect === Effect::Deny ? GrantLevel::Reader : null);
    app()->instance(HostRoleDriver::class, $driver);
    config()->set('vaulter.permission_drivers.deep', HostRoleDriver::class);
    config()->set('vaulter.drivers.permission', 'deep');
    app()->forgetInstance(HostRoleDriverManager::class);
    app()->forgetInstance(AccessResolver::class);
    $resolver = app(AccessResolver::class);
    $actor = AccessTestHelpers::user();
    $single = $resolver->effectiveGrants($actor, $parent);
    expect($single->allows(Ability::View))->toBe($effect === Effect::Allow);
    expect((array) $resolver->evaluateMany($actor, [$parent])[(string) $parent->id])->toBe((array) $single);
})->with(['far deny' => Effect::Deny, 'far allow' => Effect::Allow]);

it('stops a batch ancestor walk at the same cycle as individual resolution', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'cycle-node');
    $parent = AccessTestHelpers::folder($storage, $node, 'cycle-parent');
    $node->forceFill(['parent_id' => $parent->id])->save();
    AccessTestHelpers::grant($parent, GrantLevel::Reader);
    $resolver = app(AccessResolver::class);
    $actor = AccessTestHelpers::user();
    expect((array) $resolver->evaluateMany($actor, [$node])[(string) $node->id])
        ->toBe((array) $resolver->effectiveGrants($actor, $node));
});
