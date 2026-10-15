<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Webhooks;

use Vaulter\Attributes\Internal;

/**
 * The result of validating an outbound webhook URL: the scheme,
 * host and port to dial together with the EXACT IP that passed the range check.
 *
 * Delivery pins {@see curlResolveEntry()} onto the request (CURLOPT_RESOLVE) so the
 * connection lands on the very address the guard vetted — closing the DNS-rebind /
 * TOCTOU window between "resolve + check" and "connect".
 */
#[Internal]
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
     */
    public function curlResolveEntry(): string
    {
        return "{$this->host}:{$this->port}:{$this->ip}";
    }
}
