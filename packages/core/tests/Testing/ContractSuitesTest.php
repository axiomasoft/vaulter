<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Vaulter\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Access\Drivers\GatePermissionDriver;
use Vaulter\Content\ScanResult;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Cas\ScanVerdict;
use Vaulter\Handlers\Builtin\PreviewHandler;
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Testing\Contracts\ContentScannerContractTests;
use Vaulter\Testing\Contracts\FileHandlerContractTests;
use Vaulter\Testing\Contracts\PermissionDriverContractTests;
use Vaulter\Testing\Contracts\PermissionFixture;
use Vaulter\Testing\FakeContentScanner;
use Vaulter\Testing\Support\FailingStream;
use Vaulter\Tests\Fixtures\Access\RoleStubDriver;
use Vaulter\Tests\Fixtures\Testing\BrokenFileHandler;
use Vaulter\Tests\Fixtures\Testing\ReadErrorSwallowingScanner;

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

it('accepts a scanner that maps every outcome as the lifecycle records it', function (): void {
    $scanner = (new FakeContentScanner)
        ->returnsFor('infected bytes', new ScanResult(ScanVerdict::Infected))
        ->returnsFor('encrypted bytes', new ScanResult(ScanVerdict::Failed, 'password_protected'))
        ->returnsFor('slow bytes', new ScanResult(ScanVerdict::Failed, 'scanner_timeout', true));

    (new ContentScannerContractTests)->assertContentScannerContract($scanner, [
        'clean' => 'plain',
        'infected' => 'infected bytes',
        'password_protected' => 'encrypted bytes',
        'timeout' => 'slow bytes',
    ]);
});

it('rejects a scanner that mistakes an unreadable stream for a clean file', function (): void {
    (new ContentScannerContractTests)->assertContentScannerContract(new ReadErrorSwallowingScanner, ['clean' => 'plain']);
})->throws(AssertionFailedError::class, 'never be reported clean');

it('rejects a scanner whose outcome does not match the sample', function (): void {
    (new ContentScannerContractTests)->assertContentScannerContract(new FakeContentScanner, ['clean' => 'plain', 'infected' => 'plain']);
})->throws(AssertionFailedError::class, 'must scan infected');

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

it('rejects a scanner that turns unreadable bytes into a verdict, and accepts one that throws or fails', function (): void {
    $contract = new ContentScannerContractTests;

    expect(fn () => $contract->assertUnreadableBytesAreNeverClean(new ReadErrorSwallowingScanner))->toThrow(AssertionFailedError::class);
    $contract->assertUnreadableBytesAreNeverClean((new FakeContentScanner)->alwaysReturns(new RuntimeException('engine down')));
    $contract->assertUnreadableBytesAreNeverClean(new FakeContentScanner);
    expect(in_array(FailingStream::SCHEME, stream_get_wrappers(), true))->toBeFalse();
});
