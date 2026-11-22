<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Packman\Attributes\Internal;
use Vaulter\Webhooks\Contracts\HostResolver;

/**
 * The production {@see HostResolver}: resolves a hostname to every A and AAAA record
 * via the system resolver. An IP-literal host resolves to itself,
 * so a URL like http://169.254.169.254/ is range-checked without a DNS lookup.
 *
 * Both families are queried so a public A record paired with a private AAAA cannot
 * slip a delivery past the guard.
 */
#[Internal]
final class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }
}
