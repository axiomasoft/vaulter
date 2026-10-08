<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Tests\Access\Contract;

use Vaulter\Access\AccessResolver;
use Vaulter\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Access\PermissionDriverManager;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Azgard\AzgardPermissionDriver;
use Vaulter\Azgard\Contracts\AzgardGuard;
use Vaulter\Azgard\Tests\Access\AccessTestHelpers;
use Vaulter\Contracts\Access\PermissionDriver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\GrantScope;

/**
 * The PermissionDriver contract test (master plan §12 / H.2): ANY driver that only
 * contributes the actor to the subject-set and adds no roles/override MUST yield
 * the SAME AccessResolver decision on a shared ACL fixture. This is the stub M2b's
 * Gate/Spatie/bridge drivers reuse — pointing the resolver at each driver over one
 * fixture and asserting identical results.
 *
 * The fixture: a writer subtree grant on a parent and an inheritance boundary on its
 * child. Every "pass-through" driver (no override, no extra subjects, no role
 * decision) must agree: parent editable, child unreachable.
 */
function bindDriver(PermissionDriver $driver): AccessResolver
{
    app()->instance($driver::class, $driver);
    config()->set('vaulter.access.permission_drivers.contract', $driver::class);
    config()->set('vaulter.access.permission_driver', 'contract');
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

/**
 * H.2 hardening: the SAME contract harness against EACH real M2b driver in
 * pass-through mode (no super ability/permission granted, no role-mapped abilities
 * granted, no spatie roles). Every driver must contribute only the actor to the
 * subject-set and add no role decision — so the AccessResolver decision is byte-for-
 * byte identical to the database lane on the shared ACL fixture.
 */
it('yields the shared-fixture truth for the moved driver in pass-through', function (): void {
    app()->forgetInstance(AzgardGuard::class);
    app()->offsetUnset(AzgardGuard::class);

    $databaseDecisions = decisionsOver(bindDriver(new DatabasePermissionDriver));
    $bridgeDecisions = decisionsOver(bindDriver(app(AzgardPermissionDriver::class)));

    expect($bridgeDecisions)->toBe($databaseDecisions)
        ->and($bridgeDecisions)->toBe(sharedFixtureTruth());
});
