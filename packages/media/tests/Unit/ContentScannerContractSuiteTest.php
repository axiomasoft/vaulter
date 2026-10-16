<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Vaulter\Media\Content\ScanResult;
use Vaulter\Media\Enums\Cas\ScanVerdict;
use Vaulter\Media\Testing\Contracts\ContentScannerContractTests;
use Vaulter\Media\Testing\FakeContentScanner;
use Vaulter\Media\Tests\Fixtures\Testing\ReadErrorSwallowingScanner;
use Vaulter\Testing\Support\FailingStream;

/**
 * The scanner contract suite of the media package is proved twice: green on a reference implementation and red
 * on one that breaks the contract, so a suite that stops catching a violation fails here.
 */
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

it('rejects a scanner that turns unreadable bytes into a verdict, and accepts one that throws or fails', function (): void {
    $contract = new ContentScannerContractTests;

    expect(fn () => $contract->assertUnreadableBytesAreNeverClean(new ReadErrorSwallowingScanner))->toThrow(AssertionFailedError::class);
    $contract->assertUnreadableBytesAreNeverClean((new FakeContentScanner)->alwaysReturns(new RuntimeException('engine down')));
    $contract->assertUnreadableBytesAreNeverClean(new FakeContentScanner);
    expect(in_array(FailingStream::SCHEME, stream_get_wrappers(), true))->toBeFalse();
});
