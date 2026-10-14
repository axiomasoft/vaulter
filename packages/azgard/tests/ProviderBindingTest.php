<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Vaulter\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Access\PermissionDriverManager;
use Vaulter\Azgard\AzgardGuardAdapter;
use Vaulter\Azgard\AzgardPermissionDriver;
use Vaulter\Azgard\Contracts\AzgardGuard;
use Vaulter\Azgard\NullAzgardGuard;
use Vaulter\Azgard\Providers\BindingsRegistrar;
use Vaulter\Azgard\Tests\Stubs\SpyAzgardUser;

/**
 * The provider resolves the AzgardGuard seam from the CURRENT request's user: an
 * AzGuard-backed user yields a real adapter, anything else the deny-all guard — so the
 * host wires nothing beyond selecting the driver and adding the trait to its User.
 */
it('binds an adapter over the authenticated AzGuard-backed user', function (): void {
    $user = SpyAzgardUser::make()->makeSuperAdmin();
    $this->actingAs($user);

    $guard = app(AzgardGuard::class);

    expect($guard)->toBeInstanceOf(AzgardGuardAdapter::class)
        // The adapter really wraps THIS user — its super-admin decisions surface.
        ->and($guard->hasPermissionIn('project', 1, 'documents.manage'))->toBeTrue();
});

it('binds the deny-all null guard for a guest', function (): void {
    expect(app(AzgardGuard::class))->toBeInstanceOf(NullAzgardGuard::class);
});

it('binds the deny-all null guard for a user that is not AzGuard-backed', function (): void {
    $this->actingAs(new class extends User
    {
        protected $guarded = [];
    });

    expect(app(AzgardGuard::class))->toBeInstanceOf(NullAzgardGuard::class);
});

it('registers its permission driver and keeps a host driver override', function (): void {
    expect(config('vaulter.access.permission_drivers.azgard'))->toBe(AzgardPermissionDriver::class);

    config()->set('vaulter.access.permission_drivers.azgard', DatabasePermissionDriver::class);
    (new BindingsRegistrar)->register(app());

    expect(config('vaulter.access.permission_drivers.azgard'))->toBe(DatabasePermissionDriver::class);
});

it('preserves an explicitly configured null driver key', function (): void {
    config()->set('vaulter.access.permission_drivers.azgard', null);
    (new BindingsRegistrar)->register(app());

    expect(config('vaulter.access.permission_drivers.azgard'))->toBeNull();
});

it('keeps its actor-only driver non-enumerating through the core manager', function (): void {
    $manager = app(PermissionDriverManager::class);

    expect($manager->enumeratesRoleSubjects(app(AzgardPermissionDriver::class)))->toBeFalse();
});
