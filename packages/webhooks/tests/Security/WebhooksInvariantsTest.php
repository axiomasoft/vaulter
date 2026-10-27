<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Vaulter\Webhooks\Configuration\WebhooksConfig;
use Vaulter\Webhooks\Security\BlockedRangesInvariant;
use Vaulter\Webhooks\Security\SchemesInvariant;

function webhooksDelivery(array $delivery): WebhooksConfig
{
    return new WebhooksConfig(new Repository(['vaulter-webhooks' => ['delivery' => $delivery]]));
}

it('accepts only https as the delivery scheme in production', function (array $schemes, bool $secure): void {
    $invariant = new SchemesInvariant(webhooksDelivery(['allowed_schemes' => $schemes]));

    expect($invariant->id())->toBe('webhooks.schemes')
        ->and($invariant->violation() === null)->toBe($secure);
})->with([[['https'], true], [['HTTPS'], true], [['http', 'https'], false], [['http'], false]]);

it('treats the default scheme list as insecure', function (): void {
    expect((new SchemesInvariant(webhooksDelivery([])))->violation())->toContain('allowed_schemes');
});

it('requires the default blocked ranges to stay and allows extending them', function (): void {
    $default = WebhooksConfig::DEFAULT_BLOCKED_IP_RANGES;

    expect((new BlockedRangesInvariant(webhooksDelivery([])))->violation())->toBeNull()
        ->and((new BlockedRangesInvariant(webhooksDelivery(['blocked_ip_ranges' => [...$default, '203.0.113.0/24']])))->violation())->toBeNull()
        ->and((new BlockedRangesInvariant(webhooksDelivery(['blocked_ip_ranges' => ['203.0.113.0/24']])))->violation())->toContain('10.0.0.0/8');
});
