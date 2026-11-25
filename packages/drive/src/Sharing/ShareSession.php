<?php

declare(strict_types=1);

namespace Vaulter\Drive\Sharing;

use Illuminate\Support\Carbon;
use Packman\Attributes\Internal;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Support\Identity;

/**
 * The session a visitor opens by redeeming a share link. It is a signed,
 * stateless token the client sends back in the X-Share-Session header: while it is
 * valid, further requests on the link are the same visit and cost no use. It is
 * bound to the link, its token generation and the settings that decide who may
 * open it (token, role, audience, password), so rotating the token, revoking the
 * link, or changing one of those voids it. It carries no secret and grants nothing
 * by itself — every request still passes the full resolve.
 */
#[Internal]
final readonly class ShareSession
{
    public const string HEADER = 'X-Share-Session';

    private const int LIFETIME_SECONDS = 3600;

    public function __construct(private ShareLinkSigner $signer) {}

    /** A session for $share, or null when the application has no key to sign with. */
    public function issue(NodeShare $share, Carbon $now): ?string
    {
        $expires = $now->getTimestamp() + self::LIFETIME_SECONDS;
        if ($share->expires_at !== null) {
            $expires = min($expires, $share->expires_at->getTimestamp());
        }

        return $this->signer->sign($this->payload($share, $expires));
    }

    public function valid(?string $session, NodeShare $share, Carbon $now): bool
    {
        $decoded = $this->signer->open($session);
        if ($decoded === null) {
            return false;
        }
        $parts = explode('|', $decoded);
        if (count($parts) !== 5 || $parts[0] !== 'v1') {
            return false;
        }
        $expires = (int) $parts[4];

        return $expires > $now->getTimestamp()
            && $this->payload($share, $expires) === $decoded;
    }

    private function payload(NodeShare $share, int $expires): string
    {
        return implode('|', ['v1', Identity::stringify($share->getKey()), $share->token_generation, $this->signer->fingerprint($share), $expires]);
    }
}
