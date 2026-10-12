<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Webhooks;

/**
 * Resolves a hostname to EVERY IP it maps to — both A (IPv4) and AAAA (IPv6) records
 * . The outbound guard rejects the host if ANY returned address
 * lands in a blocked range: a public A paired with a private AAAA must not slip
 * through.
 *
 * Injected (not called statically) so a test drives it with a fake map and no real DNS
 * or socket is touched.
 */
interface HostResolver
{
    /**
     * Every IP literal (IPv4 and IPv6) the host resolves to. An IP-literal host
     * resolves to itself. An empty list means the host does not resolve.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
