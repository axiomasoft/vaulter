<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Security;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Security\ProductionInvariant;
use Vaulter\Webhooks\Configuration\WebhooksConfig;

/** The blocked ranges may be extended but never narrowed below the built-in private/loopback/link-local defaults. */
#[Internal]
final readonly class BlockedRangesInvariant implements ProductionInvariant
{
    public function __construct(private WebhooksConfig $config) {}

    public function id(): string
    {
        return 'webhooks.blocked-ranges';
    }

    public function violation(): ?string
    {
        $missing = array_diff(WebhooksConfig::DEFAULT_BLOCKED_IP_RANGES, $this->config->blockedIpRanges());

        return $missing === []
            ? null
            : 'vaulter-webhooks.delivery.blocked_ip_ranges must contain the default blocked ranges ('.implode(', ', $missing).' missing).';
    }
}
