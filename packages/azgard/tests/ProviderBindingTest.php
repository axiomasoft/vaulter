<?php

declare(strict_types=1);

use Vaulter\Azgard\AzgardPermissionDriver;
use Vaulter\Azgard\Configuration\AzgardConfig;
use Vaulter\Azgard\Contracts\AzgardGuard;
use Vaulter\Azgard\Providers\BindingsRegistrar;
use Vaulter\Azgard\Testing\FakeAzgardGuard;
use Vaulter\Azgard\Tests\Stubs\HostGuard;
use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\PermissionDriverCatalog;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Kernel;
use Vaulter\Modules\MissingExtensionException;

/**
 * The bridge binds no guard of its own: the seam is the optional capability the host names in
 * `vaulter-azgard.guard`, validated at boot; without it the driver builds an adapter per actor.
 */
it('provides no guard until the host names one', function (): void {
    $capabilities = app(Kernel::class)->capabilities();

    expect($capabilities->has(AzgardGuard::class))->toBeFalse()
        ->and(fn () => $capabilities->require(AzgardGuard::class))->toThrow(MissingExtensionException::class);
});

it('provides the configured guard class as the capability', function (): void {
    HostGuard::bind($guard = FakeAzgardGuard::grantingWorkspace('project', 1, 'documents.view'));
    $capabilities = app(Kernel::class)->capabilities();

    expect($capabilities->has(AzgardGuard::class))->toBeTrue()
        ->and($capabilities->require(AzgardGuard::class))->toBe($guard);
});

it('refuses a guard class that does not implement the seam', function (): void {
    config()->set('vaulter-azgard.guard', stdClass::class);

    expect(fn () => app(AzgardConfig::class)->guard())->toThrow(InvalidConfigurationException::class, 'vaulter-azgard.guard')
        ->and(fn () => (new BindingsRegistrar)->boot(app()))->toThrow(InvalidConfigurationException::class);

    config()->set('vaulter-azgard.guard', 'No\\Such\\Guard');

    expect(fn () => app(AzgardConfig::class)->guard())->toThrow(InvalidConfigurationException::class);
});

it('contributes its permission driver to the drive slot and lets the host map override the key', function (): void {
    $catalog = app(PermissionDriverCatalog::class);

    expect(app(Kernel::class)->extensions()->handlerClass(PermissionDriver::class, 'azgard'))->toBe(AzgardPermissionDriver::class)
        ->and($catalog->class('azgard'))->toBe(AzgardPermissionDriver::class);

    config()->set('vaulter.access.permission_drivers.azgard', DatabasePermissionDriver::class);

    expect($catalog->class('azgard'))->toBe(DatabasePermissionDriver::class);
});

it('keeps its actor-only driver non-enumerating through the core manager', function (): void {
    $manager = app(PermissionDriverManager::class);

    expect($manager->enumeratesRoleSubjects(app(AzgardPermissionDriver::class)))->toBeFalse();
});
