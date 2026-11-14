<?php

declare(strict_types=1);

use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\Drivers\GatePermissionDriver;
use Vaulter\Drive\Access\Drivers\SpatiePermissionDriver;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Contracts\PermissionDriverContractTests;
use Vaulter\Drive\Testing\Fixtures\Access\RoleStubDriver;

/**
 * The PermissionDriver contract test (master plan §12 / H.2): ANY driver that only
 * contributes the actor to the subject-set and adds no roles/override MUST yield
 * the SAME AccessResolver decision on a shared ACL fixture. This is the stub M2b's
 * Gate/Spatie/AzGuard drivers reuse — pointing the resolver at each driver over one
 * fixture and asserting identical results.
 *
 * The fixture: a writer subtree grant on a parent and an inheritance boundary on its
 * child. Every "pass-through" driver (no override, no extra subjects, no role
 * decision) must agree: parent editable, child unreachable.
 */
function bindDriver(PermissionDriver $driver): AccessResolver
{
    app()->instance($driver::class, $driver);
    config()->set('vaulter-drive.access.permission_drivers.contract', $driver::class);
    config()->set('vaulter-drive.access.permission_driver', 'contract');
    app()->forgetInstance(PermissionDriverManager::class);
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
    $child = AccessTestHelpers::folder($storage, $parent, 'child', inheritGrants: false);

    AccessTestHelpers::grant($parent, RoleRegistry::WRITER, scope: GrantScope::Subtree);

    $contract = new PermissionDriverContractTests;
    $contract->assertPermissionDriverContract($resolver, $actor, $parent, $child);

    return [
        'parent.edit' => $resolver->can($actor, Ability::Edit, $parent),
        'child.view' => $resolver->can($actor, Ability::View, $child),
        'child.edit' => $resolver->can($actor, Ability::Edit, $child),
    ];
}

/**
 * The shared truth the algorithm dictates on the fixture: parent editable, child
 * cut off by its boundary. EVERY pass-through driver must agree.
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
    $databaseDecisions = decisionsOver(bindDriver(new DatabasePermissionDriver));

    // …and a stub driver that ALSO contributes only the actor, no roles/override.
    $stubDecisions = decisionsOver(bindDriver(new RoleStubDriver(
        role: null,
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
    'gate' => fn (): PermissionDriver => app(GatePermissionDriver::class),
    'spatie' => fn (): PermissionDriver => app(SpatiePermissionDriver::class),

]);

it('yields the shared-fixture truth for each real driver in pass-through', function (PermissionDriver $driver): void {
    expect(decisionsOver(bindDriver($driver)))->toBe(sharedFixtureTruth());
})->with('pass-through drivers');
