<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Packman\Attributes\Internal;
use Uri\WhatWg\Url;
use Vaulter\Webhooks\Configuration\WebhooksOptions;
use Vaulter\Webhooks\Contracts\HostResolver;
use Vaulter\Webhooks\Contracts\OutboundUrlGuard;
use Vaulter\Webhooks\Exceptions\BlockedOutboundUrlException;
use Vaulter\Webhooks\Security\CidrRange;
use Vaulter\Webhooks\ValueObjects\PinnedOutboundEndpoint;

/**
 * The default SSRF barrier for outbound webhooks.
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
#[Internal]
final readonly class DefaultOutboundUrlGuard implements OutboundUrlGuard
{
    public function __construct(
        private WebhooksOptions $webhooksConfig,
        private HostResolver $resolver,
    ) {}

    public function isAllowed(string $url): void
    {
        $this->pin($url);
    }

    public function pin(string $url): PinnedOutboundEndpoint
    {
        // Reject ambiguous authority syntax on both parsers, including empty userinfo.
        if (preg_match('~[\x00-\x20\x7f]~', $url) === 1 || str_contains($url, '\\')
            || preg_match('~\A([a-z][a-z0-9+.-]*)://(\[[a-f0-9:.]+\]|[^:/?#@%\[\]]+)(?::([0-9]{1,5}))?(?=[/?#]|\z)~i', $url, $parts) !== 1) {
            throw BlockedOutboundUrlException::malformed();
        }

        $scheme = strtolower($parts[1]);

        if (!in_array($scheme, ['http', 'https'], true) || !in_array($scheme, $this->webhooksConfig->allowedSchemes(), true)) {
            throw BlockedOutboundUrlException::scheme($scheme);
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port = isset($parts[3]) ? (int) $parts[3] : $defaultPort;
        // Nonstandard ports require a host-supplied outbound policy.
        if ($port !== $defaultPort) {
            throw BlockedOutboundUrlException::malformed();
        }
        $host = $this->asciiHost($url, $parts[2]);

        $ips = filter_var($host, FILTER_VALIDATE_IP) === false ? $this->resolver->resolve($host) : [$host];

        if ($ips === []) {
            throw BlockedOutboundUrlException::unresolvable($host);
        }

        foreach ($ips as $ip) {
            if ($this->isBlocked($ip)) {
                throw BlockedOutboundUrlException::blockedAddress($host, $ip);
            }
        }

        return new PinnedOutboundEndpoint(scheme: $scheme, host: $host, ip: $ips[0], port: $port);
    }

    private function asciiHost(string $url, string $rawHost): string
    {
        // WHATWG can canonicalize a dotted IPv4 literal before returning its host.
        if (preg_match('/[.。．｡]\z/u', $rawHost) === 1) {
            throw BlockedOutboundUrlException::malformed();
        }
        if (class_exists(Url::class)) {
            $host = Url::parse($url)?->getAsciiHost();
            $host = $host === null ? false : trim($host, '[]');
        } elseif (str_starts_with($rawHost, '[')) {
            $host = substr($rawHost, 1, -1);
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw BlockedOutboundUrlException::malformed();
            }
        } else {
            $host = idn_to_ascii($rawHost, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            // Reject unbracketed numeric forms that clients may reinterpret as IPs.
            if (is_string($host) && preg_match('/(?:^|\.)(?:[0-9]+|0x[a-f0-9]+)$/iD', $host) === 1
                && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw BlockedOutboundUrlException::malformed();
            }
        }

        if ($host === false || $host === '' || str_ends_with($host, '.')
            || (filter_var($host, FILTER_VALIDATE_IP) === false && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)) {
            throw BlockedOutboundUrlException::malformed();
        }

        return strtolower($host);
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

        return array_any($this->webhooksConfig->blockedIpRanges(), fn (string $range): bool => $this->inRange($ip, $range));
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

        if (!str_starts_with($bin, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
            return $ip;
        }

        $mapped = @inet_ntop(substr($bin, 12, 4));

        return $mapped === false ? $ip : $mapped;
    }

    /**
     * Binary CIDR containment, family-aware: a bare IP is an exact match, a
     * cross-family (IPv4 vs IPv6) comparison and a malformed range are never a match.
     */
    private function inRange(string $ip, string $cidr): bool
    {
        $range = CidrRange::parse($cidr);
        $ipBin = @inet_pton($ip);

        // A malformed range never matches: it is rejected at config validation, never coerced to a prefix.
        return $range !== null && $ipBin !== false && CidrRange::contains($ipBin, $range[0], $range[1]);
    }
}
