<?php

declare(strict_types=1);

namespace Vaulter\Filament\Support;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Exceptions\VaulterException;

/**
 * The grant rows of the panel table: for every live node the acting admin may view and
 * manage, the grants the handle's access operations list. A node the handle does not list,
 * or whose grants it refuses, contributes no row.
 *
 * @phpstan-type GrantRow array{id: string, node_id: string, drive_id: string, subject_type: string, subject_id: string, role_key: string, role_version: int, scope: GrantScope, expires_at: ?CarbonImmutable, created_at: CarbonImmutable}
 */
#[Internal]
final class PanelGrants
{
    private function __construct() {}

    /** @return list<GrantRow> */
    public static function rows(): array
    {
        $rows = [];
        $handles = [];

        foreach (PanelNodes::rows() as $node) {
            if ($node['trashed_at'] !== null || ($node['abilities'][Ability::Manage->value] ?? false) !== true) {
                continue;
            }

            $handle = $handles[$node['drive_id']] ??= PanelDrives::handle($node['drive_id']);
            if (!$handle instanceof DriveHandle) {
                continue;
            }

            try {
                foreach ($handle->access()->grants($node['id']) as $grant) {
                    $rows[] = [
                        'id' => $grant->id,
                        'node_id' => $grant->nodeId,
                        'drive_id' => $node['drive_id'],
                        'subject_type' => $grant->subject->type,
                        'subject_id' => (string) $grant->subject->id,
                        'role_key' => $grant->role,
                        'role_version' => $grant->roleVersion,
                        'scope' => $grant->scope,
                        'expires_at' => $grant->expiresAt === null ? null : CarbonImmutable::instance($grant->expiresAt),
                        'created_at' => CarbonImmutable::instance($grant->createdAt),
                    ];
                }
            } catch (VaulterException) {
                // A node whose grants the handle refuses (feature off, no Manage) contributes no rows.
            }
        }

        return $rows;
    }
}
