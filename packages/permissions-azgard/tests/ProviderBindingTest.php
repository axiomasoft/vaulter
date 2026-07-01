<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Vaulter\Contracts\Access\AzgardGuard;
use Vaulter\Permissions\Azgard\AzgardGuardAdapter;
use Vaulter\Permissions\Azgard\NullAzgardGuard;
use Vaulter\Permissions\Azgard\Tests\Stubs\SpyAzgardUser;

/**
 * The provider resolves the AzgardGuard seam from the CURRENT request's user: an
 * AzGuard-backed user yields a real adapter, anything else the deny-all guard — so the
 * host wires nothing beyond selecting the driver and adding the trait to its User.
 */
it('binds an adapter over the authenticated AzGuard-backed user', function (): void {
    $user = SpyAzgardUser::make()->grantGlobal(['*']);
    $this->actingAs($user);

    $guard = app(AzgardGuard::class);

    expect($guard)->toBeInstanceOf(AzgardGuardAdapter::class)
        // The adapter really wraps THIS user — its super-admin grant surfaces.
        ->and($guard->isSuperAdmin())->toBeTrue();
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
