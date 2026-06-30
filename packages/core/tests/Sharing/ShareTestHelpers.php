<?php

declare(strict_types=1);

namespace Vaulter\Tests\Sharing;

use Illuminate\Support\Carbon;
use Vaulter\Enums\GrantLevel;
use Vaulter\Enums\GrantScope;
use Vaulter\Enums\Permission;
use Vaulter\Models\Node;
use Vaulter\Models\NodeShare;
use Vaulter\Support\Config;
use Vaulter\Support\ShareToken;

/**
 * Builders for the M2c bearer-share tests (§2.7): write a share row directly with a
 * known raw token (so a test can present it) while the DB stores only the hash +
 * prefix, exactly as the Action does. Keeps the resolver/HTTP tests focused on
 * behaviour, not row plumbing.
 */
final class ShareTestHelpers
{
    /**
     * Persist a bearer share on a node with the given RAW token (only its hash +
     * prefix reach the DB — mirroring the Action). Returns the persisted row; the
     * caller already knows the raw token to present.
     */
    public static function share(
        Node $node,
        string $token = 'raw-share-token-value',
        int $permissions = Permission::Read->value,
        GrantLevel $level = GrantLevel::Reader,
        GrantScope $scope = GrantScope::Item,
        ?string $passwordHash = null,
        bool $requireLogin = false,
        ?Carbon $expiresAt = null,
        ?int $maxUses = null,
        int $useCount = 0,
        ?Carbon $revokedAt = null,
    ): NodeShare {
        $model = Config::nodeShareModel();

        /** @var NodeShare $share */
        $share = new $model;
        $share->forceFill([
            'tenant_id' => $node->tenant_id,
            'node_id' => $node->getKey(),
            'token_hash' => ShareToken::hash($token),
            'token_prefix' => ShareToken::prefix($token, 8),
            'permissions' => $permissions,
            'level' => $level->value,
            'scope' => $scope->value,
            'password_hash' => $passwordHash,
            'require_login' => $requireLogin,
            'allow_discovery' => false,
            'expires_at' => $expiresAt,
            'max_uses' => $maxUses,
            'use_count' => $useCount,
            'revoked_at' => $revokedAt,
        ])->save();

        return $share->refresh();
    }
}
