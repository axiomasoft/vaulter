<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Vaulter\Access\HostRoleDriverManager;
use Vaulter\Access\ValueObjects\AclScope;
use Vaulter\Contracts\Access\AzgardGuard;
use Vaulter\Drivers\Permission\AzgardPermissionResolver;
use Vaulter\Drivers\Permission\DatabasePermissionResolver;
use Vaulter\Drivers\Permission\GatePermissionResolver;
use Vaulter\Enums\Access\Effect;
use Vaulter\Exceptions\Access\MissingHostRoleDriverException;
use Vaulter\Testing\FakeAzgardGuard;
use Vaulter\Testing\NullRoleDriver;

// Boot the package inside testbench so config()/Gate()/container resolve.
uses(Vaulter\Tests\TestCase::class)->in(__DIR__);

function reproUser(): User
{
    return new class extends User
    {
        protected $guarded = [];
    };
}

function reproScope(): AclScope
{
    return new AclScope(panel: 'default', workspaceType: 'project', workspaceId: 1);
}

// ---- C1: fail-closed on missing/unknown/null-in-production driver ------------

it('C1: unknown driver key throws, never falls back to allow', function (): void {
    $manager = new HostRoleDriverManager(container: app(), isProduction: false);

    expect(fn () => $manager->driver('does-not-exist'))
        ->toThrow(MissingHostRoleDriverException::class);
});

it('C1: the open null driver is rejected in production (fail-loud)', function (): void {
    $manager = new HostRoleDriverManager(container: app(), isProduction: true);

    expect(fn () => $manager->driver('null'))
        ->toThrow(MissingHostRoleDriverException::class);
});

it('C1: the null driver IS selectable (and open) outside production', function (): void {
    $manager = new HostRoleDriverManager(container: app(), isProduction: false);

    expect($manager->driver('null'))->toBeInstanceOf(NullRoleDriver::class);
});

// ---- C2: Null* seams -------------------------------------------------------

it('C2: a non-super FakeAzgardGuard denies every workspace/scoped probe', function (): void {
    // Stand-in for the deny-all NullAzgardGuard (separate package, not autoloadable
    // here): an empty guard denies all three seam methods.
    $guard = new FakeAzgardGuard(superAdmin: false);

    expect($guard->hasScopedPermission('documents.view', reproUser()))->toBeFalse()
        ->and($guard->hasPermissionIn('project', 1, 'documents.update'))->toBeFalse()
        ->and($guard->isSuperAdmin())->toBeFalse();
});

it('C2: NullRoleDriver is the OPEN driver by design (override=Allow, create=true)', function (): void {
    // Documents the actual behaviour: contrary to a naive "null = deny" reading,
    // NullRoleDriver grants everything (ADR-0007) and is gated to non-prod by C1.
    $driver = new NullRoleDriver;

    expect($driver->override(reproUser(), reproScope()))->toBe(Effect::Allow)
        ->and($driver->canCreateInWorkspace(reproUser(), 'project', 1))->toBeTrue();
});

// ---- C3: override() only allows an explicit super-admin --------------------

it('C3: DatabasePermissionResolver never overrides (no super-admin lane)', function (): void {
    $driver = new DatabasePermissionResolver;

    expect($driver->override(reproUser(), reproScope()))->toBeNull();
});

it('C3: GatePermissionResolver defers (null) when the super ability is not granted', function (): void {
    // No Gate::define('vaulter.super') → Gate denies → override defers, never allows.
    $driver = new GatePermissionResolver;

    expect($driver->override(reproUser(), reproScope()))->toBeNull();
});

it('C3: AzgardPermissionResolver overrides ONLY for a super-admin guard', function (): void {
    $user = reproUser();

    app()->instance(AzgardGuard::class, new FakeAzgardGuard(superAdmin: false));
    expect(app(AzgardPermissionResolver::class)->override($user, reproScope()))->toBeNull();

    app()->instance(AzgardGuard::class, FakeAzgardGuard::superAdmin());
    expect(app(AzgardPermissionResolver::class)->override($user, reproScope()))->toBe(Effect::Allow);
});
