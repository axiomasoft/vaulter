<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources\Sharing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Data\Snapshots\ShareLinkSnapshot;

/**
 * A share link as its owner sees it. The token is never part of it: only the store
 * and rotate responses carry it, once ({@see MintedShareResource}).
 *
 * @mixin ShareLinkSnapshot
 */
#[Internal]
final class ShareResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
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
            'created_by' => $share->createdBy,
            'created_at' => SnapshotValue::iso($share->createdAt),
        ];
    }
}
