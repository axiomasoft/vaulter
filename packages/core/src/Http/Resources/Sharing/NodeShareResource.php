<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources\Sharing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Configuration\SharingConfig;
use Vaulter\Models\Sharing\NodeShare;

/**
 * The read shape of a bearer share-link (§2.7 / M2c). It NEVER serializes the
 * token_hash or the raw token: it exposes only the non-secret token_prefix, the
 * grant shape (permissions/level/scope), the gates and the lifecycle counters.
 *
 * The raw token is surfaced EXACTLY ONCE — at mint time the create endpoint calls
 * {@see self::withToken()} to attach it (and the full share URL built from the
 * configurable base). Every listing/show response omits it, because it is
 * unrecoverable from the stored row.
 *
 * @mixin NodeShare
 */
final class NodeShareResource extends JsonResource
{
    /**
     * The raw token, attached ONLY by the create endpoint so it is returned once.
     * Null on every list/show response.
     */
    private ?string $rawToken = null;

    /**
     * Attach the raw bearer token to this (create-time only) response. The
     * resulting payload carries the token and a full share URL; it is the single
     * moment the token is ever visible.
     */
    public function withToken(string $token): self
    {
        $this->rawToken = $token;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var NodeShare $share */
        $share = $this->resource;

        $payload = [
            'id' => $share->getKey(),
            'node_id' => $share->node_id,
            'token_prefix' => $share->token_prefix,
            'permissions' => $share->permissions,
            'level' => $share->level->value,
            'scope' => $share->scope->value,
            'has_password' => $share->hasPassword(),
            'require_login' => $share->require_login,
            'allow_discovery' => $share->allow_discovery,
            'expires_at' => $share->expires_at,
            'max_uses' => $share->max_uses,
            'use_count' => $share->use_count,
            'revoked_at' => $share->revoked_at,
            'created_at' => $share->created_at,
        ];

        // The raw token + URL are present ONLY in the create response (returned
        // once); list/show never include them.
        if ($this->rawToken !== null) {
            $payload['token'] = $this->rawToken;
            $payload['url'] = $this->shareUrl($this->rawToken);
        }

        return $payload;
    }

    /**
     * The public share URL built from the configurable base plus the raw token.
     */
    private function shareUrl(string $token): string
    {
        return app(SharingConfig::class)->urlBase().'/'.$token;
    }
}
