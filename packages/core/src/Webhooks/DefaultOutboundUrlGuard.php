<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Vaulter\Configuration\WebhooksConfig;
use Vaulter\Contracts\Webhooks\HostResolver;
use Vaulter\Contracts\Webhooks\OutboundUrlGuard;
use Vaulter\Exceptions\Webhooks\BlockedOutboundUrlException;
use Vaulter\ValueObjects\Webhooks\PinnedOutboundEndpoint;

/**
 * The default SSRF barrier for outbound webhooks (N2 /).
 *
 * pin() is the real barrier on the delivery path: it parses the URL, enforces the
 * scheme allow-list, resolves EVERY A/AAAA record and rejects the host if ANY address
 * lands in a blocked range, then returns the vetted IP so the caller pins it onto the
 * connection (no second DNS lookup — no rebind window). isAllowed() is the same check
 * used at registration as an early UX-level refusal.
 *
 * String-prefix shortcuts ("does the host start with 10.") are deliberately NOT used:
 * they are trivially bypassed by a DNS name, a decimal/octal IP literal or an
 * IPv4-mapped IPv6 address. Every resolved address is range-checked on its packed
 * binary form, and IPv4-mapped IPv6 is folded back to IPv4 before the check.
 */
final readonly class DefaultOutboundUrlGuard implements OutboundUrlGuard
{
    public function __construct(
        private readonly WebhooksConfig $webhooksConfig,
        private HostResolver $resolver,
    ) {}

    public function isAllowed(string $url): void
    {
        $this->pin($url);
    }

    public function pin(string $url): PinnedOutboundEndpoint
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            throw BlockedOutboundUrlException::malformed($url);
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';

        if (!in_array($scheme, $this->webhooksConfig->allowedSchemes(), true)) {
            throw BlockedOutboundUrlException::scheme($scheme);
        }

        $host = trim($parts['host'], '[]');

        $ips = $this->resolver->resolve($host);

        if ($ips === []) {
            throw BlockedOutboundUrlException::unresolvable($host);
        }

        foreach ($ips as $ip) {
            if ($this->isBlocked($ip)) {
                throw BlockedOutboundUrlException::blockedAddress($host, $ip);
            }
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        return new PinnedOutboundEndpoint(scheme: $scheme, host: $host, ip: $ips[0], port: $port);
    }

    /**
     * True when the address (after folding an IPv4-mapped IPv6 back to IPv4) falls in
     * a blocked range and is NOT rescued by a host-configured allow range. A syntactically
     * invalid address is treated as blocked (fail-closed).
     */
    private function isBlocked(string $ip): bool
    {
        $ip = $this->normalize($ip);

        if (@inet_pton($ip) === false) {
            return true;
        }

        foreach ($this->webhooksConfig->allowedIpRanges() as $range) {
            if ($this->inRange($ip, $range)) {
                return false;
            }
        }

        foreach ($this->webhooksConfig->blockedIpRanges() as $range) {
            if ($this->inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fold an IPv4-mapped IPv6 address (::ffff:a.b.c.d) back to its IPv4 form so the
     * IPv4 ranges catch a mapped loopback/private address. Everything else is returned
     * unchanged.
     */
    private function normalize(string $ip): string
    {
        $bin = @inet_pton($ip);

        if ($bin === false || strlen($bin) !== 16) {
            return $ip;
        }

        if (substr($bin, 0, 12) !== "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
            return $ip;
        }

        $mapped = @inet_ntop(substr($bin, 12, 4));

        return $mapped === false ? $ip : $mapped;
    }

    /**
     * Binary CIDR containment, family-aware: a bare IP is an exact match, and a
     * cross-family (IPv4 vs IPv6) comparison is never a match.
     */
    private function inRange(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            $ipBin = @inet_pton($ip);
            $cidrBin = @inet_pton($cidr);

            return $ipBin !== false && $cidrBin !== false && $ipBin === $cidrBin;
        }

        [$subnet, $bitsRaw] = explode('/', $cidr, 2);

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bitsRaw;
        $bits = max(0, min($bits, strlen($ipBin) * 8));

        $fullBytes = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        if ($fullBytes > 0 && strncmp($ipBin, $subnetBin, $fullBytes) !== 0) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainderBits)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }
}
