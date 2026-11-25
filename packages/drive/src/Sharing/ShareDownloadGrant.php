<?php

declare(strict_types=1);

namespace Vaulter\Drive\Sharing;

use Illuminate\Support\Carbon;
use Packman\Attributes\Internal;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Support\Identity;

/**
 * Proof that a visitor gave a share link's password, for the one file it was issued for.
 * A password travels only in a header or a POST body, never in a URL; a plain `<a href>`
 * download, which can send neither, carries this grant in its `grant` query parameter
 * instead. It is signed, lives a few minutes (never past the link's expiry) and is bound
 * to the link, its token generation, the settings that decide who may open it and the
 * opaque id of the file, so it opens nothing else and dies with a password change, a
 * rotation or a revoke. Like the link session it stands for a visit already paid for.
 */
#[Internal]
final readonly class ShareDownloadGrant
{
    public const string PARAMETER = 'grant';

    public const int LIFETIME_SECONDS = 300;

    public function __construct(private ShareLinkSigner $signer) {}

    /** A grant to download $resource (the opaque node id) of $share, or null when nothing can sign it. */
    public function issue(NodeShare $share, string $resource, Carbon $now): ?string
    {
        return $this->signer->sign($this->payload($share, $resource, $this->expiry($share, $now)));
    }

    /** The moment a grant issued at $now stops working. */
    public function expiry(NodeShare $share, Carbon $now): int
    {
        $expires = $now->getTimestamp() + self::LIFETIME_SECONDS;

        return $share->expires_at === null ? $expires : min($expires, $share->expires_at->getTimestamp());
    }

    public function valid(?string $grant, NodeShare $share, ?string $resource, Carbon $now): bool
    {
        $decoded = $this->signer->open($grant);
        if ($decoded === null || $resource === null || $resource === '') {
            return false;
        }
        $parts = explode('|', $decoded);
        if (count($parts) !== 6 || $parts[0] !== 'download-v1') {
            return false;
        }
        $expires = (int) $parts[5];

        return $expires > $now->getTimestamp()
            && $this->payload($share, $resource, $expires) === $decoded;
    }

    private function payload(NodeShare $share, string $resource, int $expires): string
    {
        return implode('|', ['download-v1', Identity::stringify($share->getKey()), $share->token_generation, $this->signer->fingerprint($share), $resource, $expires]);
    }
}
