<?php

declare(strict_types=1);

use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Azgard\AzgardGuardAdapter;
use Vaulter\Azgard\AzgardPermissionDriver;
use Vaulter\Azgard\Contracts\AzgardGuard;
use Vaulter\Azgard\Tests\Stubs\SpyAzgardUser;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;

/**
 * The bridge driving the REAL Vaulter AzgardPermissionDriver: the adapter is bound as
 * the host's AzgardGuard, and the driver's every lane (super-admin override, the
 * workspace-role → GrantLevel fold, the create gate) is exercised end-to-end. Only the
 * leaf user is a controllable double; the adapter and the resolver are production code.
 */
function bindAdapterFor(SpyAzgardUser $user): void
{
    app()->instance(AzgardGuard::class, new AzgardGuardAdapter($user));
}

function azgardDriver(): AzgardPermissionDriver
{
    return app(AzgardPermissionDriver::class);
}

it('grants an absolute override from an AzGuard super-admin (wildcard)', function (): void {
    $user = SpyAzgardUser::make()->grantGlobal(['*']);
    bindAdapterFor($user);

    $scope = new AccessScope(panel: 'default', workspaceType: 'project', workspaceId: 9);

    expect(azgardDriver()->override($user, $scope))->toBe(Effect::Allow);
});

it('defers (no override) for a non super-admin', function (): void {
    $user = SpyAzgardUser::make()->grantGlobal(['documents.view']);
    bindAdapterFor($user);

    $scope = new AccessScope(panel: 'default', workspaceType: 'project', workspaceId: 9);

    expect(azgardDriver()->override($user, $scope))->toBeNull();
});

it('folds a workspace-scoped permission into the matching grant level', function (): void {
    // documents.update is the Writer probe (Edit → documents.update) in the ability map.
    $user = SpyAzgardUser::make()->grantInWorkspace('project', 1, ['documents.update']);
    bindAdapterFor($user);

    $scope = new AccessScope(panel: 'default', workspaceType: 'project', workspaceId: 1);
    $decision = azgardDriver()->resolve(Subject::ofModel($user), $user, $scope);

    expect($decision)->not->toBeNull()
        ->and($decision->level)->toBe(GrantLevel::Writer)
        ->and($decision->isAllow())->toBeTrue();
});

it('yields no decision when the workspace grants nothing', function (): void {
    $user = SpyAzgardUser::make();
    bindAdapterFor($user);

    $scope = new AccessScope(panel: 'default', workspaceType: 'project', workspaceId: 1);

    expect(azgardDriver()->resolve(Subject::ofModel($user), $user, $scope))->toBeNull();
});

it('gates workspace creation through the create permission', function (): void {
    $user = SpyAzgardUser::make()->grantInWorkspace('project', 5, ['documents.create']);
    bindAdapterFor($user);

    expect(azgardDriver()->canCreateInWorkspace($user, 'project', 5))->toBeTrue()
        ->and(azgardDriver()->canCreateInWorkspace($user, 'project', 6))->toBeFalse();
});

it('exposes only the actor in its subject-set', function (): void {
    $user = SpyAzgardUser::make(3);
    bindAdapterFor($user);

    $subjects = azgardDriver()->subjectsFor($user);

    expect($subjects)->toHaveCount(1)
        ->and($subjects[0]->id)->toBe(3);
});
