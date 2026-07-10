<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Vaulter\Contracts\Webhooks\HostResolver;

/**
 * The production {@see HostResolver}: resolves a hostname to every A and AAAA record
 * via the system resolver (§2.11 / F-P1.7-01). An IP-literal host resolves to itself,
 * so a URL like http://169.254.169.254/ is range-checked without a DNS lookup.
 *
 * Both families are queried so a public A record paired with a private AAAA cannot
 * slip a delivery past the guard.
 */
final class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $a = @dns_get_record($host, DNS_A);

        if (is_array($a)) {
            foreach ($a as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);

        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        // Fallback where dns_get_record yields nothing for A records on some resolvers.
        if ($ips === []) {
            $resolved = gethostbynamel($host);

            if (is_array($resolved)) {
                foreach ($resolved as $ip) {
                    $ips[] = $ip;
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
