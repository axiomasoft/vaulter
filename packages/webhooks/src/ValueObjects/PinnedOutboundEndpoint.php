<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\ValueObjects;

use Packman\Attributes\Api;

/**
 * The result of validating an outbound webhook URL: the scheme,
 * host and port to dial together with the EXACT IP that passed the range check.
 *
 * Delivery pins {@see curlConnectToEntry()} onto the request so the
 * connection lands on the very address the guard vetted — closing the DNS-rebind /
 * TOCTOU window between "resolve + check" and "connect".
 */
#[Api]
final readonly class PinnedOutboundEndpoint
{
    public function __construct(
        public string $scheme,
        public string $host,
        public string $ip,
        public int $port,
    ) {}

    /**
     * The curl "HOST:PORT:ADDRESS" override that pins the vetted IP onto the dial,
     * so DNS is never consulted a second time at connect.
     *
     * @deprecated Use curlConnectToEntry().
     */
    public function curlResolveEntry(): string
    {
        return "{$this->host}:{$this->port}:{$this->ip}";
    }

    public function curlConnectToEntry(): string
    {
        $host = str_contains($this->host, ':') ? '['.$this->host.']' : $this->host;
        $ip = str_contains($this->ip, ':') ? '['.$this->ip.']' : $this->ip;

        return "{$host}:{$this->port}:{$ip}:{$this->port}";
    }
}
