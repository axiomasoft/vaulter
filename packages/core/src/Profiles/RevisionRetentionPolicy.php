<?php

declare(strict_types=1);

namespace Vaulter\Profiles;

use Vaulter\Exceptions\Profiles\InvalidProfileException;

/**
 * How long the non-current revisions of a file are kept (PLAN2.D48). The age of a
 * non-current revision counts from the moment it stopped being the head
 * (v_file_revisions.superseded_at).
 *
 * Protection order of the pruning: the head is never deleted, then pinned
 * (keep_forever) revisions, then revisions younger than minNoncurrentAgeDays; of the
 * rest, everything past the newest maxNoncurrentRevisions unpinned non-current
 * revisions and everything older than maxNoncurrentAgeDays is deleted. A null limit
 * does not limit. Pinned revisions do not count against maxNoncurrentRevisions.
 *
 * protectPinned cannot be switched off in 1.0; it is a field so that a later release
 * can add the option without changing the shape.
 */
final readonly class RevisionRetentionPolicy
{
    public function __construct(
        public ?int $maxNoncurrentRevisions = 100,
        public ?int $maxNoncurrentAgeDays = null,
        public int $minNoncurrentAgeDays = 7,
        public bool $protectPinned = true,
    ) {
        if (($maxNoncurrentRevisions !== null && $maxNoncurrentRevisions < 0)
            || ($maxNoncurrentAgeDays !== null && $maxNoncurrentAgeDays < 1)
            || $minNoncurrentAgeDays < 0) {
            throw new InvalidProfileException('Revision retention limits must be null or non-negative (maxNoncurrentAgeDays at least 1).');
        }
        if ($maxNoncurrentAgeDays !== null && $maxNoncurrentAgeDays < $minNoncurrentAgeDays) {
            throw new InvalidProfileException('maxNoncurrentAgeDays cannot be shorter than minNoncurrentAgeDays.');
        }
        if (!$protectPinned) {
            throw new InvalidProfileException('Pinned (keep_forever) revisions are always protected.');
        }
    }

    /** Whether the policy can ever delete anything. */
    public function prunes(): bool
    {
        return $this->maxNoncurrentRevisions !== null || $this->maxNoncurrentAgeDays !== null;
    }
}
