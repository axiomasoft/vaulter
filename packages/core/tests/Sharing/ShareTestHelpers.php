<?php

declare(strict_types=1);

namespace Vaulter\Tests\Sharing;

use Illuminate\Support\Carbon;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Sharing\ShareAudience;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Sharing\NodeShare;
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
        Node|NodeSnapshot $node,
        string $token = 'raw-share-token-value',
        string $role = RoleRegistry::READER,
        ShareAudience $audience = ShareAudience::Anyone,
        GrantScope $scope = GrantScope::Item,
        ?string $passwordHash = null,
        int $generation = 1,
        ?Carbon $expiresAt = null,
        ?int $maxUses = null,
        int $useCount = 0,
        ?Carbon $revokedAt = null,
    ): NodeShare {
        $model = app(VaulterConfig::class)->model('node_share');
        $nodeModel = app(VaulterConfig::class)->model('node');
        $row = $node instanceof Node ? $node : $nodeModel::query()->findOrFail($node->id);

        /** @var NodeShare $share */
        $share = new $model;
        $share->forceFill([
            'tenant_id' => $row->tenant_id,
            'node_id' => $row->getKey(),
            'token_hash' => ShareToken::hash($token),
            'token_prefix' => ShareToken::prefix($token, 8),
            'role_key' => $role,
            'role_version' => 1,
            'audience' => $audience,
            'token_generation' => $generation,
            'scope' => $scope->value,
            'password_hash' => $passwordHash,
            'expires_at' => $expiresAt,
            'max_uses' => $maxUses,
            'use_count' => $useCount,
            'revoked_at' => $revokedAt,
        ]);
        app(VaulterDatabase::class)->write(static fn (): bool => $share->save());

        return $share->refresh();
    }
}
