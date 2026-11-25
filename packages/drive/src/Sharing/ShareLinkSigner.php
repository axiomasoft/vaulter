<?php

declare(strict_types=1);

namespace Vaulter\Drive\Sharing;

use Illuminate\Contracts\Config\Repository;
use Packman\Attributes\Internal;
use Vaulter\Drive\Models\Sharing\NodeShare;

/**
 * Signs the stateless tokens a share link hands out (the visit session and the download
 * grant) with the application key, and names the link settings they are bound to. A token
 * is `base64url(payload).hmac`: it carries no secret, and changing what decides who may
 * open the link (token, role, audience, password) changes the fingerprint and voids it.
 */
#[Internal]
final readonly class ShareLinkSigner
{
    public function __construct(private Repository $config) {}

    /** The signed token for $payload, or null when the application has no key to sign with. */
    public function sign(string $payload): ?string
    {
        $key = $this->key();
        if ($key === null) {
            return null;
        }
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        return $encoded.'.'.hash_hmac('sha256', $encoded, $key);
    }

    /** The payload of a token this application signed, or null for anything else. */
    public function open(?string $token): ?string
    {
        $key = $this->key();
        if ($key === null || $token === null || substr_count($token, '.') !== 1) {
            return null;
        }
        [$encoded, $signature] = explode('.', $token);
        if (!hash_equals(hash_hmac('sha256', $encoded, $key), $signature)) {
            return null;
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /** The settings of $share a signed token is bound to. */
    public function fingerprint(NodeShare $share): string
    {
        return substr(hash('sha256', implode('|', [
            $share->token_hash, $share->role_key, $share->role_version, $share->audience->value, (string) $share->password_hash,
        ])), 0, 16);
    }

    private function key(): ?string
    {
        $key = $this->config->get('app.key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
