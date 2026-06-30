<?php

declare(strict_types=1);

use Vaulter\Access\AccessResolver;
use Vaulter\Access\HostRoleDriverManager;
use Vaulter\Contracts\HostRoleDriver;
use Vaulter\Drivers\Permission\AzgardPermissionResolver;
use Vaulter\Drivers\Permission\DatabasePermissionResolver;
use Vaulter\Drivers\Permission\GatePermissionResolver;
use Vaulter\Drivers\Permission\SpatiePermissionResolver;
use Vaulter\Enums\Ability;
use Vaulter\Enums\Effect;
use Vaulter\Enums\GrantLevel;
use Vaulter\Enums\GrantScope;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\Access\RoleStubDriver;

/**
 * The HostRoleDriver contract test (master plan §12 / H.2): ANY driver that only
 * contributes the actor to the subject-set and adds no roles/override MUST yield
 * the SAME AccessResolver decision on a shared ACL fixture. This is the stub M2b's
 * Gate/Spatie/Azgard drivers reuse — pointing the resolver at each driver over one
 * fixture and asserting identical results.
 *
 * The fixture: a Writer subtree allow on a parent, a deny on a child. Every
 * "pass-through" driver (no override, no extra subjects, no role decision) must
 * agree: parent editable, child denied.
 */
function bindDriver(HostRoleDriver $driver): AccessResolver
{
    app()->instance($driver::class, $driver);
    config()->set('vaulter.permission_drivers.contract', $driver::class);
    config()->set('vaulter.drivers.permission', 'contract');
    app()->forgetInstance(HostRoleDriverManager::class);
    app()->forgetInstance(AccessResolver::class);

    return app(AccessResolver::class);
}

/**
 * @return array<string, bool>
 */
function decisionsOver(AccessResolver $resolver): array
{
    $actor = AccessTestHelpers::user();
    $storage = AccessTestHelpers::storage();
    $parent = AccessTestHelpers::folder($storage, null, 'parent');
    $child = AccessTestHelpers::folder($storage, $parent, 'child');

    AccessTestHelpers::grant($parent, GrantLevel::Writer, scope: GrantScope::Subtree);
    AccessTestHelpers::grant(
        $child,
        GrantLevel::Writer,
        effect: Effect::Deny,
        scope: GrantScope::Item,
    );

    return [
        'parent.edit' => $resolver->can($actor, Ability::Edit, $parent),
        'child.view' => $resolver->can($actor, Ability::View, $child),
        'child.edit' => $resolver->can($actor, Ability::Edit, $child),
    ];
}

/**
 * The shared truth the algorithm dictates on the fixture: parent editable, child
 * denied (deny-wins). EVERY pass-through driver must agree.
 */
function sharedFixtureTruth(): array
{
    return [
        'parent.edit' => true,
        'child.view' => false,
        'child.edit' => false,
    ];
}

it('yields identical decisions across pass-through host-role drivers on a shared fixture', function (): void {
    // The real standalone database driver…
    $databaseDecisions = decisionsOver(bindDriver(new DatabasePermissionResolver));

    // …and a stub driver that ALSO contributes only the actor, no roles/override.
    $stubDecisions = decisionsOver(bindDriver(new RoleStubDriver(
        roleLevel: null,
        overrideEffect: null,
        includeActor: true,
    )));

    expect($stubDecisions)->toBe($databaseDecisions)
        ->and($databaseDecisions)->toBe(sharedFixtureTruth());
});

/**
 * H.2 hardening: the SAME contract harness against EACH real M2b driver in
 * pass-through mode (no super ability/permission granted, no role-mapped abilities
 * granted, no spatie roles). Every driver must contribute only the actor to the
 * subject-set and add no role decision — so the AccessResolver decision is byte-for-
 * byte identical to the database lane on the shared ACL fixture.
 */
dataset('pass-through drivers', [
    'gate' => fn (): HostRoleDriver => new GatePermissionResolver,
    'spatie' => fn (): HostRoleDriver => new SpatiePermissionResolver,
    // AzGuard with no AzgardGuard bound (and an actor that does not implement the
    // seam) is a pure pass-through: resolve/override yield null, so the decision is
    // byte-for-byte identical to the database lane on the shared ACL fixture.
    'azgard' => fn (): HostRoleDriver => app(AzgardPermissionResolver::class),
]);

it('yields the shared-fixture truth for each real driver in pass-through', function (HostRoleDriver $driver): void {
    expect(decisionsOver(bindDriver($driver)))->toBe(sharedFixtureTruth());
})->with('pass-through drivers');
