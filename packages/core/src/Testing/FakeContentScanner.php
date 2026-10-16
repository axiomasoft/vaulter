<?php

declare(strict_types=1);

namespace Vaulter\Testing;

use Throwable;
use Vaulter\Attributes\Api;
use Vaulter\Content\ScanInput;
use Vaulter\Content\ScanResult;
use Vaulter\Contracts\Cas\ContentScanner;
use Vaulter\Enums\Cas\ScanVerdict;

/**
 * A scanner whose verdicts a test scripts, keyed by the bytes it is asked to scan, and
 * that records what it was asked. It reads the stream to the end like a real engine, so
 * a test can tell that the bytes were really offered.
 *
 * Its key and version must equal the scan.scanner_key / scan.scanner_version the
 * application is configured with: the scan work is bound to that identity.
 */
#[Api]
final class FakeContentScanner implements ContentScanner
{
    /** @var list<array{revisionId: int|string, driveId: int|string, tenantId: int|string|null, scopeKey: string, sha256: string, bytes: string}> */
    public array $scans = [];

    /** @var array<string, ScanResult|Throwable> */
    private array $outcomes = [];

    private ScanResult|Throwable $default;

    public function __construct(private readonly string $key = 'fake', private readonly string $version = '1')
    {
        $this->default = new ScanResult(ScanVerdict::Clean);
    }

    /** What every scan returns (or throws) unless a more specific outcome is set. */
    public function alwaysReturns(ScanResult|Throwable $outcome): self
    {
        $this->default = $outcome;

        return $this;
    }

    /** What a scan of exactly these bytes returns (or throws). */
    public function returnsFor(string $bytes, ScanResult|Throwable $outcome): self
    {
        $this->outcomes[hash('sha256', $bytes)] = $outcome;

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function version(): string
    {
        return $this->version;
    }

    #[\Override]
    public function scan(ScanInput $input): ScanResult
    {
        $bytes = '';
        while (!feof($input->stream)) {
            $chunk = @fread($input->stream, 8192);
            if ($chunk === false || ($chunk === '' && !feof($input->stream))) {
                return new ScanResult(ScanVerdict::Failed, 'transient_io', true);
            }
            $bytes .= $chunk;
        }
        $this->scans[] = [
            'revisionId' => $input->revisionId,
            'driveId' => $input->driveId,
            'tenantId' => $input->tenantId,
            'scopeKey' => $input->scopeKey,
            'sha256' => $input->sha256,
            'bytes' => $bytes,
        ];

        $outcome = $this->outcomes[hash('sha256', $bytes)] ?? $this->default;
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $outcome;
    }
}
