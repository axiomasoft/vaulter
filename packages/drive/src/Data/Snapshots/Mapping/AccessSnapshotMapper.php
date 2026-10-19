<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Snapshots\Mapping;

use Vaulter\Attributes\Internal;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Drive\Data\Snapshots\GrantSnapshot;
use Vaulter\Drive\Data\Snapshots\ShareLinkSnapshot;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Sharing\NodeShare;

/** @internal Grants and share links to their snapshots. */
#[Internal]
final readonly class AccessSnapshotMapper
{
    public function grant(NodeGrant $grant): GrantSnapshot
    {
        return new GrantSnapshot(
            id: SnapshotValue::id($grant->getKey()),
            nodeId: SnapshotValue::id($grant->node_id),
            subject: $grant->subject(),
            role: $grant->role_key,
            roleVersion: $grant->role_version,
            scope: $grant->scope,
            expiresAt: SnapshotValue::time($grant->expires_at),
            createdAt: SnapshotValue::requiredTime($grant->created_at),
            grantedBy: SnapshotValue::nullableId($grant->granted_by),
        );
    }

    public function share(NodeShare $share): ShareLinkSnapshot
    {
        return new ShareLinkSnapshot(
            id: SnapshotValue::id($share->getKey()),
            nodeId: SnapshotValue::id($share->node_id),
            role: $share->role_key,
            roleVersion: $share->role_version,
            audience: $share->audience,
            scope: $share->scope,
            expiresAt: SnapshotValue::time($share->expires_at),
            passwordProtected: $share->hasPassword(),
            maxUses: $share->max_uses,
            useCount: $share->use_count,
            tokenPrefix: $share->token_prefix,
            tokenGeneration: $share->token_generation,
            revokedAt: SnapshotValue::time($share->revoked_at),
            createdAt: SnapshotValue::requiredTime($share->created_at),
            createdBy: SnapshotValue::nullableId($share->granted_by),
            version: is_numeric($version = $share->getAttribute('version')) ? (int) $version : 0,
        );
    }
}
