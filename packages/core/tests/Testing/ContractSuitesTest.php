<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\Drivers\GatePermissionDriver;
use Vaulter\Drive\Enums\Access\Effect;
use Vaulter\Drive\Handlers\Builtin\PreviewHandler;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Testing\Fixtures\Access\RoleStubDriver;
use Vaulter\Testing\Contracts\FileHandlerContractTests;
use Vaulter\Testing\Contracts\PermissionDriverContractTests;
use Vaulter\Testing\Contracts\PermissionFixture;
use Vaulter\Tests\Fixtures\Testing\BrokenFileHandler;

/**
 * Every contract suite is proved twice: green on a reference implementation and red
 * on one that breaks the contract, so a suite that stops catching a violation fails here.
 */
beforeEach(fn () => config()->set('vaulter.access.permission_driver', 'database'));

it('accepts a conforming file handler', function (): void {
    (new FileHandlerContractTests)->assertFileHandlerContract(new PreviewHandler);
});

it('rejects a file handler whose capabilities depend on the node', function (): void {
    (new FileHandlerContractTests)->assertFileHandlerContract(new BrokenFileHandler);
})->throws(AssertionFailedError::class, 'only on the mime type');

it('accepts drivers that decide like the reference on the shared fixture', function (): void {
    $contract = new PermissionDriverContractTests;
    $fixture = PermissionFixture::build();

    $contract->assertSameDecisionsAsReference(new DatabasePermissionDriver, $fixture);
    $contract->assertSameDecisionsAsReference(app(GatePermissionDriver::class), $fixture);
});

it('rejects a driver that opens everything up', function (): void {
    (new PermissionDriverContractTests)->assertSameDecisionsAsReference(new RoleStubDriver(overrideEffect: Effect::Allow));
})->throws(AssertionFailedError::class, 'decides differently');

it('rejects a driver that opens everything up even when the profile pins its own driver', function (): void {
    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key('default')->permissionDriver('database'));
    $registry->freeze();
    app()->instance(ProfileRegistry::class, $registry);

    (new PermissionDriverContractTests)->assertSameDecisionsAsReference(new RoleStubDriver(overrideEffect: Effect::Allow));
})->throws(AssertionFailedError::class, 'decides differently');

it('restores the access configuration after a driver was compared', function (): void {
    $before = config('vaulter.access');

    (new PermissionDriverContractTests)->assertSameDecisionsAsReference(new DatabasePermissionDriver);

    expect(config('vaulter.access'))->toBe($before);
});
