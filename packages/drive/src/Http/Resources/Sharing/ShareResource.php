<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Resources\Sharing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Packman\Attributes\Internal;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Drive\Data\Snapshots\ShareLinkSnapshot;
use Vaulter\Drive\Http\Resources\AggregatePreconditions;

/**
 * A share link as its owner sees it. The token is never part of it: only the store
 * and rotate responses carry it, once ({@see MintedShareResource}). `etag` is the
 * link's tag (id and version), the If-Match of its update, rotation and revocation.
 *
 * @mixin ShareLinkSnapshot
 */
#[Internal]
final class ShareResource extends JsonResource
{
    /** The ETag header value: the same tag the body carries (the link's id and version). */
    public function etag(): string
    {
        /** @var ShareLinkSnapshot $share */
        $share = $this->resource;

        return AggregatePreconditions::shareTag($share);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var ShareLinkSnapshot $share */
        $share = $this->resource;

        return [
            'id' => $share->id,
            'node_id' => $share->nodeId,
            'role' => $share->role,
            'role_version' => $share->roleVersion,
            'audience' => $share->audience->value,
            'scope' => $share->scope->value,
            'expires_at' => SnapshotValue::iso($share->expiresAt),
            'max_uses' => $share->maxUses,
            'use_count' => $share->useCount,
            'has_password' => $share->passwordProtected,
            'token_generation' => $share->tokenGeneration,
            'version' => $share->version,
            'etag' => $this->etag(),
            'created_by' => $share->createdBy,
            'created_at' => SnapshotValue::iso($share->createdAt),
        ];
    }
}
