<?php

declare(strict_types=1);

use Vaulter\AzGuard\AzGuardPermissionDriver;
use Vaulter\AzGuard\Configuration\AzGuardConfig;
use Vaulter\AzGuard\Contracts\AzGuardGuard;
use Vaulter\AzGuard\Providers\BindingsRegistrar;
use Vaulter\AzGuard\Testing\FakeAzGuardGuard;
use Vaulter\AzGuard\Tests\Stubs\HostGuard;
use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\PermissionDriverCatalog;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Kernel;
use Vaulter\Modules\MissingExtensionException;

/**
 * The bridge binds no guard of its own: the seam is the optional capability the host names in
 * `vaulter-azguard.guard`, validated at boot; without it the driver builds an adapter per actor.
 */
it('provides no guard until the host names one', function (): void {
    $capabilities = app(Kernel::class)->capabilities();

    expect($capabilities->has(AzGuardGuard::class))->toBeFalse()
        ->and(fn () => $capabilities->require(AzGuardGuard::class))->toThrow(MissingExtensionException::class);
});

it('provides the configured guard class as the capability', function (): void {
    HostGuard::bind($guard = FakeAzGuardGuard::grantingWorkspace('project', 1, 'documents.view'));
    $capabilities = app(Kernel::class)->capabilities();

    expect($capabilities->has(AzGuardGuard::class))->toBeTrue()
        ->and($capabilities->require(AzGuardGuard::class))->toBe($guard);
});

it('refuses a guard class that does not implement the seam', function (): void {
    config()->set('vaulter-azguard.guard', stdClass::class);

    expect(fn () => app(AzGuardConfig::class)->guard())->toThrow(InvalidConfigurationException::class, 'vaulter-azguard.guard')
        ->and(fn () => (new BindingsRegistrar)->boot(app()))->toThrow(InvalidConfigurationException::class);

    config()->set('vaulter-azguard.guard', 'No\\Such\\Guard');

    expect(fn () => app(AzGuardConfig::class)->guard())->toThrow(InvalidConfigurationException::class);
});

it('contributes its permission driver to the drive slot and lets the host map override the key', function (): void {
    $catalog = app(PermissionDriverCatalog::class);

    expect(app(Kernel::class)->extensions()->handlerClass(PermissionDriver::class, 'azguard'))->toBe(AzGuardPermissionDriver::class)
        ->and($catalog->class('azguard'))->toBe(AzGuardPermissionDriver::class);

    config()->set('vaulter-drive.access.permission_drivers.azguard', DatabasePermissionDriver::class);

    expect($catalog->class('azguard'))->toBe(DatabasePermissionDriver::class);
});

it('keeps its actor-only driver non-enumerating through the core manager', function (): void {
    $manager = app(PermissionDriverManager::class);

    expect($manager->enumeratesRoleSubjects(app(AzGuardPermissionDriver::class)))->toBeFalse();
});
