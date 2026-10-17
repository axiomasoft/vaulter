<?php

declare(strict_types=1);

namespace Vaulter\Filament\Support;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\Sharing\ShareAudience;
use Vaulter\Exceptions\VaulterException;

/**
 * The share-link rows of the panel table: for every live node the acting admin may view and
 * share, the links the handle's share operations list. The raw token is never part of a row,
 * only its non-secret prefix.
 *
 * @phpstan-type ShareRow array{id: string, node_id: string, drive_id: string, token_prefix: string, role_key: string, audience: ShareAudience, scope: GrantScope, status: string, use_count: int, max_uses: ?int, expires_at: ?CarbonImmutable, revoked_at: ?CarbonImmutable, created_at: CarbonImmutable}
 */
#[Internal]
final class PanelShares
{
    public const string LIVE = 'Live';

    public const string REVOKED = 'Revoked';

    public const string EXPIRED = 'Expired';

    public const string EXHAUSTED = 'Exhausted';

    private function __construct() {}

    /** @return list<ShareRow> */
    public static function rows(): array
    {
        $rows = [];
        $handles = [];

        foreach (PanelNodes::rows() as $node) {
            if ($node['trashed_at'] !== null || ($node['abilities'][Ability::Share->value] ?? false) !== true) {
                continue;
            }

            $handle = $handles[$node['drive_id']] ??= PanelDrives::handle($node['drive_id']);
            if (!$handle instanceof DriveHandle) {
                continue;
            }

            try {
                foreach ($handle->shares()->list($node['id']) as $share) {
                    $revoked = $share->revokedAt === null ? null : CarbonImmutable::instance($share->revokedAt);
                    $expires = $share->expiresAt === null ? null : CarbonImmutable::instance($share->expiresAt);
                    $rows[] = [
                        'id' => $share->id,
                        'node_id' => $share->nodeId,
                        'drive_id' => $node['drive_id'],
                        'token_prefix' => $share->tokenPrefix,
                        'role_key' => $share->role,
                        'audience' => $share->audience,
                        'scope' => $share->scope,
                        'status' => match (true) {
                            $revoked !== null => self::REVOKED,
                            $expires?->isPast() === true => self::EXPIRED,
                            $share->maxUses !== null && $share->useCount >= $share->maxUses => self::EXHAUSTED,
                            default => self::LIVE,
                        },
                        'use_count' => $share->useCount,
                        'max_uses' => $share->maxUses,
                        'expires_at' => $expires,
                        'revoked_at' => $revoked,
                        'created_at' => CarbonImmutable::instance($share->createdAt),
                    ];
                }
            } catch (VaulterException) {
                // A node whose links the handle refuses (feature off, no Share) contributes no rows.
            }
        }

        return $rows;
    }
}
