<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Drive\Testing\Contracts\ScanLifecycleContractTests;
use Vaulter\Testing\Fixtures\FixtureActor;

/**
 * The scan lifecycle suite is proved twice: green on a host configured the way the
 * documentation says, red on hosts that break it, so a suite that stops catching a
 * misconfiguration fails here.
 */
function scanHost(string $mode, string $queue = 'database'): Closure
{
    return static function () use ($mode, $queue): void {
        config()->set('vaulter-drive.scan.mode', $mode);
        config()->set('vaulter-drive.scan.scanner_key', 'contract');
        config()->set('vaulter-drive.scan.scanner_version', '1');
        config()->set('vaulter-drive.scan.rollout_key', 'contract-rollout');
        config()->set('queue.default', $queue);
        config()->set('queue.connections.database.retry_after', 180);
    };
}

it('accepts a host that scans all content', function (): void {
    (new ScanLifecycleContractTests)->assertScanLifecycleContract(scanHost('all_content'), FixtureActor::next());
});

it('accepts a host that scans only new content', function (): void {
    (new ScanLifecycleContractTests)->assertScanLifecycleContract(scanHost('new_content_only'), FixtureActor::next());
});

it('rejects a host with scanning off', function (): void {
    (new ScanLifecycleContractTests)->assertScanLifecycleContract(scanHost('off'), FixtureActor::next());
})->throws(AssertionFailedError::class, 'Scanning is off');

it('rejects a host whose queue cannot carry scan work', function (): void {
    (new ScanLifecycleContractTests)->assertScanLifecycleContract(scanHost('all_content', 'sync'), FixtureActor::next());
})->throws(AssertionFailedError::class, 'vaulter:scan:reconcile failed');

it('accepts a host with strict tenancy, proving scope isolation across two tenants', function (): void {
    $resolver = new class implements TenantResolver
    {
        public function current(): string
        {
            return '7';
        }
    };
    $host = scanHost('all_content');

    (new ScanLifecycleContractTests)->assertScanLifecycleContract(static function () use ($host, $resolver): void {
        $host();
        config()->set('vaulter.tenancy.enabled', true);
        config()->set('vaulter.tenancy.resolver', $resolver::class);
        app()->instance(TenantResolver::class, $resolver);
    }, FixtureActor::next());

    expect(app(TenantResolver::class))->toBe($resolver);
});
