<?php

declare(strict_types=1);

namespace Vaulter\Sharing;

use Illuminate\Support\Carbon;
use Vaulter\Access\Roles\Role;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Attributes\Internal;
use Vaulter\Enums\Sharing\ShareAudience;
use Vaulter\Exceptions\Access\UnknownRoleException;
use Vaulter\Exceptions\Sharing\ShareLinkTtlExceededException;
use Vaulter\Exceptions\Sharing\ShareRoleNotAllowedException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Profiles\ProfileRegistry;

/**
 * What a share link may be: which roles it can carry for which audience,
 * and how long the drive's profile lets it live. One place, used when a link is
 * created or changed and again on every visit, so tightening a rule takes effect on
 * links that already exist.
 */
#[Internal]
final readonly class ShareLinkRules
{
    /** Roles a link can carry whatever the audience. */
    private const array ANY_AUDIENCE = [RoleRegistry::PREVIEWER, RoleRegistry::READER, RoleRegistry::COMMENTER];

    public function __construct(private RoleRegistry $roles, private ProfileRegistry $profiles) {}

    /**
     * The newest version of $roleKey, provided a link may carry it for $audience.
     *
     * @throws UnknownRoleException
     * @throws ShareRoleNotAllowedException
     */
    public function roleFor(string $roleKey, ShareAudience $audience): Role
    {
        $role = $this->roles->latest($roleKey);
        if (!$this->allowed($role->key, $audience)) {
            throw ShareRoleNotAllowedException::for($role->key, $audience);
        }

        return $role;
    }

    public function allowed(string $roleKey, ShareAudience $audience): bool
    {
        return in_array($roleKey, self::ANY_AUDIENCE, true)
            || ($roleKey === RoleRegistry::WRITER && $audience->requiresIdentity());
    }

    /** The latest moment a link on $drive may expire, or null when the profile sets no limit. */
    public function latestExpiry(Drive $drive, Carbon $now): ?Carbon
    {
        $days = $this->limitDays($drive);

        return $days === null ? null : $now->copy()->addDays($days);
    }

    /** @throws ShareLinkTtlExceededException */
    public function assertExpiry(Drive $drive, ?Carbon $expiresAt, Carbon $now): void
    {
        $limit = $this->latestExpiry($drive, $now);
        if ($limit !== null && ($expiresAt === null || $expiresAt->gt($limit))) {
            throw ShareLinkTtlExceededException::forLimit((int) $this->limitDays($drive));
        }
    }

    /** Whether the stored link stays within the role and TTL rules as they are now. */
    public function holds(Drive $drive, NodeShare $share, Carbon $now): bool
    {
        if (!$this->roles->has($share->role_key, $share->role_version) || !$this->allowed($share->role_key, $share->audience)) {
            return false;
        }
        $limit = $this->latestExpiry($drive, $now);

        return $limit === null || ($share->expires_at !== null && !$share->expires_at->gt($limit));
    }

    private function limitDays(Drive $drive): ?int
    {
        return $this->profiles->get($drive->profile)->getDrivePolicy()->maxShareLinkTtlDays;
    }
}
