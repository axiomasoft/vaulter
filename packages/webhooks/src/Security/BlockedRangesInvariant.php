<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Security;

use Packman\Attributes\Internal;
use Vaulter\Contracts\Security\ProductionInvariant;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Webhooks\Configuration\WebhooksOptions;

/** The blocked ranges may be extended but never narrowed below the built-in private/loopback/link-local defaults. */
#[Internal]
final readonly class BlockedRangesInvariant implements ProductionInvariant
{
    public function __construct(private WebhooksOptions $config) {}

    public function id(): string
    {
        return 'webhooks.blocked-ranges';
    }

    public function violation(): ?string
    {
        $configured = $this->config->blockedIpRanges();
        $missing = [];

        foreach (WebhooksOptions::DEFAULT_BLOCKED_IP_RANGES as $default) {
            if (!$this->covered($default, $configured)) {
                $missing[] = $default;
            }
        }

        $malformed = array_values(array_filter($configured, fn (string $range): bool => CidrRange::parse($range) === null));

        if ($malformed !== []) {
            // Structural, never an accepted risk: a malformed entry has no defined range.
            throw new InvalidConfigurationException('[Vaulter] vaulter-webhooks.delivery.blocked_ip_ranges contains malformed CIDR entries ('.implode(', ', $malformed).').');
        }

        return $missing === []
            ? null
            : 'vaulter-webhooks.delivery.blocked_ip_ranges must contain the default blocked ranges ('.implode(', ', $missing).' missing).';
    }

    /**
     * Binary coverage, not string equality: a range is covered when some configured range of the
     * same family is at least as wide and shares its network prefix (a custom supernet is fine).
     *
     * @param list<string> $configured
     */
    private function covered(string $default, array $configured): bool
    {
        $inner = CidrRange::parse($default);

        if ($inner === null) {
            return false;
        }

        foreach ($configured as $range) {
            $outer = CidrRange::parse($range);

            if ($outer === null || strlen($outer[0]) !== strlen($inner[0]) || $outer[1] > $inner[1]) {
                continue;
            }

            if (CidrRange::prefixMatches($outer[0], $inner[0], $outer[1])) {
                return true;
            }
        }

        return false;
    }
}
