<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Packman\Config\Exceptions\InvalidConfiguration;
use Vaulter\Webhooks\Configuration\WebhooksOptions;
use Vaulter\Webhooks\Security\BlockedRangesInvariant;
use Vaulter\Webhooks\Security\SchemesInvariant;

function webhooksDelivery(array $delivery): WebhooksOptions
{
    return WebhooksOptions::fromArray(new Repository(['vaulter-webhooks' => ['delivery' => $delivery]])->get('vaulter-webhooks', []));
}

it('accepts only https as the delivery scheme in production', function (array $schemes, bool $secure): void {
    $invariant = new SchemesInvariant(webhooksDelivery(['allowed_schemes' => $schemes]));

    expect($invariant->id())->toBe('webhooks.schemes')
        ->and($invariant->violation() === null)->toBe($secure);
})->with([[['https'], true], [['HTTPS'], true], [['http', 'https'], false], [['http'], false]]);

it('treats the default scheme list as insecure', function (): void {
    expect(new SchemesInvariant(webhooksDelivery([]))->violation())->toContain('allowed_schemes');
});

it('requires the default blocked ranges to stay and allows extending them', function (): void {
    $default = WebhooksOptions::DEFAULT_BLOCKED_IP_RANGES;

    expect(new BlockedRangesInvariant(webhooksDelivery([]))->violation())->toBeNull()
        ->and(new BlockedRangesInvariant(webhooksDelivery(['blocked_ip_ranges' => [...$default, '203.0.113.0/24']]))->violation())->toBeNull()
        ->and(new BlockedRangesInvariant(webhooksDelivery(['blocked_ip_ranges' => ['203.0.113.0/24']]))->violation())->toContain('10.0.0.0/8');
});

it('judges blocked ranges by binary coverage and rejects malformed CIDRs', function (): void {
    $default = WebhooksOptions::DEFAULT_BLOCKED_IP_RANGES;
    $invariant = fn (array $ranges): ?string => new BlockedRangesInvariant(webhooksDelivery(['blocked_ip_ranges' => $ranges]))->violation();

    expect($invariant(['0.0.0.0/0', '::/0']))->toBeNull()
        ->and($invariant(array_diff($default, ['198.18.0.0/15'])))->toContain('198.18.0.0/15')
        ->and($invariant([...$default, '203.0.113.0/24', '2001:db8::/32', '8.8.8.8']))->toBeNull();
});

it('rejects every malformed blocked or allowed CIDR structurally, never as an accepted risk and never coerced', function (string $range): void {
    foreach (['blocked_ip_ranges', 'allowed_ip_ranges'] as $key) {
        $ranges = $key === 'blocked_ip_ranges' ? [...WebhooksOptions::DEFAULT_BLOCKED_IP_RANGES, $range] : [$range];

        expect(fn () => webhooksDelivery([$key => $ranges])->validate())->toThrow(InvalidConfiguration::class, $key);
    }

    expect(fn (): ?string => new BlockedRangesInvariant(webhooksDelivery(['blocked_ip_ranges' => [...WebhooksOptions::DEFAULT_BLOCKED_IP_RANGES, $range]]))->violation())
        ->toThrow(InvalidConfiguration::class, 'valid IP or CIDR');
})->with(['10.0.0.0/nope', '10.0.0.0/-1', '10.0.0.0/', '/8', '', '10.0.0.0/33', '10.0.0.0/99', '::1/129', '::1/999', 'nope/8', '10.0.0.0/8/8', ' 10.0.0.0/8']);

it('still accepts legitimate public IPv4 and IPv6 allow and block ranges', function (): void {
    $config = webhooksDelivery(['allowed_ip_ranges' => ['198.51.100.0/24', '2001:db8::/32', '203.0.113.9'], 'blocked_ip_ranges' => ['0.0.0.0/0', '::/0']]);
    $config->validate();

    expect($config->allowedIpRanges())->toBe(['198.51.100.0/24', '2001:db8::/32', '203.0.113.9']);
});
