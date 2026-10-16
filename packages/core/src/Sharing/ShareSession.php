<?php

declare(strict_types=1);

namespace Vaulter\Sharing;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Vaulter\Attributes\Internal;
use Vaulter\Models\Sharing\NodeShare;
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

    public function __construct(private Repository $config) {}

    /** A session for $share, or null when the application has no key to sign with. */
    public function issue(NodeShare $share, Carbon $now): ?string
    {
        $key = $this->key();
        if ($key === null) {
            return null;
        }
        $expires = $now->getTimestamp() + self::LIFETIME_SECONDS;
        if ($share->expires_at !== null) {
            $expires = min($expires, $share->expires_at->getTimestamp());
        }
        $payload = rtrim(strtr(base64_encode($this->payload($share, $expires)), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', $payload, $key);
    }

    public function valid(?string $session, NodeShare $share, Carbon $now): bool
    {
        $key = $this->key();
        if ($key === null || $session === null || substr_count($session, '.') !== 1) {
            return false;
        }
        [$payload, $signature] = explode('.', $session);
        if (!hash_equals(hash_hmac('sha256', $payload, $key), $signature)) {
            return false;
        }
        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($decoded === false) {
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
        $fingerprint = substr(hash('sha256', implode('|', [
            $share->token_hash, $share->role_key, $share->role_version, $share->audience->value, (string) $share->password_hash,
        ])), 0, 16);

        return implode('|', ['v1', Identity::stringify($share->getKey()), $share->token_generation, $fingerprint, $expires]);
    }

    private function key(): ?string
    {
        $key = $this->config->get('app.key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
