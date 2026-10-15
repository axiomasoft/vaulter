<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Webhooks;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Exceptions\Webhooks\BlockedOutboundUrlException;
use Vaulter\ValueObjects\Webhooks\PinnedOutboundEndpoint;

/**
 * The SSRF barrier for outbound webhooks. A registered
 * endpoint URL is a user-controlled primitive; without this guard the relay would POST
 * to whatever host it names — internal services and cloud metadata (169.254.169.254)
 * included.
 *
 * Host-swappable: point configuration key vaulter.webhooks.delivery.url_guard at another
 * implementation (e.g. a host with its own egress policy). The default enforces the
 * five N2 controls (scheme allow-list, A+AAAA resolution + range rejection, DNS
 * pinning, redirects off, protocol/size limits at the call site).
 */
#[Spi(SpiKind::Implement)]
interface OutboundUrlGuard
{
    /**
     * Early-reject barrier: throw when $url is not a deliverable outbound target — a
     * disallowed scheme, an unresolvable host, or a host that resolves (on ANY A/AAAA
     * record) into a blocked range. Used at registration for a fast UX-level refusal.
     *
     * @throws BlockedOutboundUrlException
     */
    public function isAllowed(string $url): void;

    /**
     * Resolve + validate $url and return the pinned endpoint the delivery must dial —
     * the very IP that passed the range check — so DNS cannot rebind between the check
     * and the connect. Called on the DELIVERY path, where the real barrier lives.
     *
     * @throws BlockedOutboundUrlException
     */
    public function pin(string $url): PinnedOutboundEndpoint;
}
