<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Testing;

use Vaulter\Content\ScanInput;
use Vaulter\Content\ScanResult;
use Vaulter\Contracts\Cas\ContentScanner;
use Vaulter\Enums\Cas\ScanVerdict;

/** Breaks the scanner contract: a read error is taken for an empty file, which is clean. */
final class ReadErrorSwallowingScanner implements ContentScanner
{
    public function key(): string
    {
        return 'acme-broken';
    }

    public function version(): string
    {
        return '1';
    }

    public function scan(ScanInput $input): ScanResult
    {
        $bytes = @stream_get_contents($input->stream);

        return str_contains((string) $bytes, 'EICAR') ? new ScanResult(ScanVerdict::Infected) : new ScanResult(ScanVerdict::Clean);
    }
}
