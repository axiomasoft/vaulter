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
        $configured = $this->config->blockedIpRanges();
        $missing = [];

        foreach (WebhooksConfig::DEFAULT_BLOCKED_IP_RANGES as $default) {
            if (!$this->covered($default, $configured)) {
                $missing[] = $default;
            }
        }

        $malformed = array_values(array_filter($configured, fn (string $range): bool => $this->parse($range) === null));

        if ($malformed !== []) {
            return 'vaulter-webhooks.delivery.blocked_ip_ranges contains malformed CIDR entries ('.implode(', ', $malformed).').';
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
        $inner = $this->parse($default);

        if ($inner === null) {
            return false;
        }

        foreach ($configured as $range) {
            $outer = $this->parse($range);

            if ($outer === null || strlen($outer[0]) !== strlen($inner[0]) || $outer[1] > $inner[1]) {
                continue;
            }

            if ($this->prefixMatches($outer[0], $inner[0], $outer[1])) {
                return true;
            }
        }

        return false;
    }

    /** @return array{string, int}|null packed network address and prefix length; null when malformed. */
    private function parse(string $cidr): ?array
    {
        $parts = explode('/', $cidr, 2);
        $bin = @inet_pton($parts[0]);

        if ($bin === false) {
            return null;
        }

        $max = strlen($bin) * 8;

        if (!isset($parts[1])) {
            return [$bin, $max];
        }

        if (preg_match('/^\d{1,3}$/', $parts[1]) !== 1 || (int) $parts[1] > $max) {
            return null;
        }

        return [$bin, (int) $parts[1]];
    }

    private function prefixMatches(string $a, string $b, int $bits): bool
    {
        $full = intdiv($bits, 8);

        if ($full > 0 && strncmp($a, $b, $full) !== 0) {
            return false;
        }

        $rest = $bits % 8;

        return $rest === 0 || ((ord($a[$full]) ^ ord($b[$full])) & ((0xFF << (8 - $rest)) & 0xFF)) === 0;
    }
}
