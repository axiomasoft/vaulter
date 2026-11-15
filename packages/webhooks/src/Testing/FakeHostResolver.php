<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Testing;

use Vaulter\Attributes\Internal;
use Vaulter\Webhooks\Contracts\HostResolver;

/**
 * A deterministic {@see HostResolver} for the SSRF-guard tests:
 * no real DNS, no sockets. An IP-literal host resolves to itself (mirroring the real
 * resolver), a mapped host resolves to the configured list, and every other host
 * resolves to a public address by default — so an ordinary delivery to a test host
 * (hooks.test) sails through the range check while an SSRF test opts a host into a
 * private/link-local address.
 */
#[Internal]
final class FakeHostResolver implements HostResolver
{
    public const string PUBLIC_IP = '93.184.216.34';

    /**
     * @var array<string, list<string>>
     */
    private array $map = [];

    /**
     * @param list<string> $ips
     */
    public function set(string $host, array $ips): self
    {
        $this->map[$host] = $ips;

        return $this;
    }

    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return $this->map[$host] ?? [self::PUBLIC_IP];
    }
}
