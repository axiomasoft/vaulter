<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources\Sharing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Configuration\SharingConfig;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Data\Snapshots\ShareLinkSnapshot;

/**
 * The read shape of a bearer share-link (§2.7 / M2c). It NEVER serializes the
 * token_hash or the raw token: it exposes only the non-secret token_prefix, the
 * grant shape (role/audience/scope), the password flag and the lifecycle counters.
 *
 * The raw token is surfaced EXACTLY ONCE — at mint time the create endpoint calls
 * {@see self::withToken()} to attach it (and the full share URL built from the
 * configurable base). Every listing/show response omits it, because it is
 * unrecoverable from the stored row.
 *
 * @mixin ShareLinkSnapshot
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
        /** @var ShareLinkSnapshot $share */
        $share = $this->resource;

        $payload = [
            'id' => $share->id,
            'node_id' => $share->nodeId,
            'token_prefix' => $share->tokenPrefix,
            'role' => $share->role,
            'role_version' => $share->roleVersion,
            'audience' => $share->audience->value,
            'scope' => $share->scope->value,
            'has_password' => $share->passwordProtected,
            'token_generation' => $share->tokenGeneration,
            'expires_at' => SnapshotValue::iso($share->expiresAt),
            'max_uses' => $share->maxUses,
            'use_count' => $share->useCount,
            'revoked_at' => SnapshotValue::iso($share->revokedAt),
            'created_at' => SnapshotValue::iso($share->createdAt),
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
