<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Tests\Access\Contract;

use Vaulter\AzGuard\AzGuardPermissionDriver;
use Vaulter\AzGuard\Tests\Access\AccessTestHelpers;
use Vaulter\AzGuard\Tests\Stubs\HostGuard;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\GrantScope;

/**
 * The PermissionDriver contract test: ANY driver that only
 * contributes the actor to the subject-set and adds no roles/override MUST yield
 * the SAME AccessResolver decision on a shared ACL fixture. This is the stub the
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
 * Hardening: the SAME contract harness against EACH real driver in
 * pass-through mode (no super ability/permission granted, no role-mapped abilities
 * granted, no spatie roles). Every driver must contribute only the actor to the
 * subject-set and add no role decision — so the AccessResolver decision is byte-for-
 * byte identical to the database lane on the shared ACL fixture.
 */
it('yields the shared-fixture truth for the moved driver in pass-through', function (): void {
    HostGuard::clear();

    $databaseDecisions = decisionsOver(bindDriver(new DatabasePermissionDriver));
    $bridgeDecisions = decisionsOver(bindDriver(app(AzGuardPermissionDriver::class)));

    expect($bridgeDecisions)->toBe($databaseDecisions)
        ->and($bridgeDecisions)->toBe(sharedFixtureTruth());
});
