<?php

declare(strict_types=1);

namespace Vaulter\Media\Testing\Contracts;

use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use Throwable;
use Vaulter\Attributes\Api;
use Vaulter\Media\Content\ScanInput;
use Vaulter\Media\Content\ScanResult;
use Vaulter\Media\Contracts\Cas\ContentScanner;
use Vaulter\Media\Enums\Cas\ScanVerdict;
use Vaulter\Testing\Support\FailingStream;

/**
 * What a host's content scanner must keep, however it talks to its engine:
 *
 * ```php
 * (new ContentScannerContractTests)->assertContentScannerContract($scanner, [
 *     'clean' => 'plain text',
 *     'infected' => $eicarSample,
 * ]);
 * ```
 *
 * $samples maps an outcome to bytes that make the scanner produce it; 'clean' is required.
 * The outcomes are clean, infected, unsupported, too_large, password_protected,
 * access_denied and timeout; the others are checked only when the host can produce them.
 * Each one must reach the evidence the way the scan lifecycle records it: clean and
 * infected are verdicts, the rest are failures with that reason, and only a timeout is
 * worth retrying.
 */
#[Api]
final class ContentScannerContractTests
{
    private const array FAILURES = [
        'unsupported' => ['unsupported', false],
        'too_large' => ['too_large', false],
        'password_protected' => ['password_protected', false],
        'access_denied' => ['access_denied', false],
        'timeout' => ['scanner_timeout', true],
    ];

    /** @param array<string, string> $samples */
    public function assertContentScannerContract(ContentScanner $scanner, array $samples): void
    {
        Assert::assertNotSame('', $scanner->key(), 'The scanner key identifies the engine in the evidence.');
        Assert::assertNotSame('', $scanner->version(), 'The scanner version identifies the signatures in the evidence.');
        Assert::assertSame($scanner->key(), $scanner->key(), 'The key must not change between calls.');
        Assert::assertSame($scanner->version(), $scanner->version(), 'The version must not change between calls.');
        Assert::assertArrayHasKey('clean', $samples, 'A "clean" sample is required.');
        Assert::assertSame([], array_diff(array_keys($samples), ['clean', 'infected', ...array_keys(self::FAILURES)]), 'Unknown outcome in the samples.');

        $clean = $this->scan($scanner, $samples['clean']);
        Assert::assertSame(ScanVerdict::Clean, $clean->verdict, 'The clean sample must scan clean.');
        Assert::assertNull($clean->reason);
        Assert::assertFalse($clean->retryable);

        if (isset($samples['infected'])) {
            $infected = $this->scan($scanner, $samples['infected']);
            Assert::assertSame(ScanVerdict::Infected, $infected->verdict, 'The infected sample must scan infected.');
            Assert::assertNull($infected->reason);
        }

        foreach (self::FAILURES as $outcome => [$reason, $retryable]) {
            if (!isset($samples[$outcome])) {
                continue;
            }
            $result = $this->scan($scanner, $samples[$outcome]);
            Assert::assertSame(ScanVerdict::Failed, $result->verdict, sprintf('The %s sample must be a failure, never a verdict.', $outcome));
            Assert::assertSame($reason, $result->reason, sprintf('The %s sample must fail with reason "%s".', $outcome, $reason));
            Assert::assertSame($retryable, $result->retryable, sprintf('The %s failure %s be retryable.', $outcome, $retryable ? 'must' : 'must not'));
        }

        $this->assertUnreadableBytesAreNeverClean($scanner);
    }

    /**
     * An IO failure is either thrown (the lifecycle then records a failure) or returned
     * as a failure; it is never a verdict, because a verdict says the bytes were seen.
     */
    public function assertUnreadableBytesAreNeverClean(ContentScanner $scanner): void
    {
        $stream = FailingStream::open();
        try {
            $result = $scanner->scan($this->input($stream, 'unreadable'));
        } catch (Throwable) {
            return;
        } finally {
            fclose($stream);
            FailingStream::release();
        }

        Assert::assertSame(ScanVerdict::Failed, $result->verdict, 'Bytes the scanner could not read must never be reported clean or infected: they are a failure.');
    }

    private function scan(ContentScanner $scanner, string $bytes): ScanResult
    {
        $stream = fopen('php://temp', 'r+b') ?: throw new \RuntimeException('A scratch stream could not be opened.');
        fwrite($stream, $bytes);
        rewind($stream);
        try {
            return $scanner->scan($this->input($stream, $bytes));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** @param resource $stream */
    private function input($stream, string $bytes): ScanInput
    {
        return new ScanInput(
            stream: $stream,
            revisionId: 1,
            blobId: 1,
            driveId: 1,
            tenantId: null,
            scopeKey: 'contract',
            sha256: hash('sha256', $bytes),
            size: strlen($bytes),
            detectedMime: null,
            scannerVersion: 'contract',
            policyVersion: 'contract',
            deadline: new DateTimeImmutable('+30 seconds'),
        );
    }
}
